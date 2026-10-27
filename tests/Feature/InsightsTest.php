<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LloydsDigital\TelescopeMcp\Support\Budget;

/**
 * A stack trace the way Telescope stores one: mostly vendor frames, a few in the app.
 *
 * @return list<array<string, mixed>>
 */
function vendorHeavyTrace(string $root = '/var/www/app/'): array
{
    $frames = [];

    for ($i = 0; $i < 30; $i++) {
        $frames[] = ['file' => "{$root}vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php", 'line' => 100 + $i, 'function' => 'handle', 'class' => 'Illuminate\Pipeline\Pipeline', 'type' => '->'];
    }

    array_splice($frames, 2, 0, [
        ['file' => "{$root}app/Services/MediaStorage.php", 'line' => 356, 'function' => 'toMediaCollection', 'class' => 'Spatie\MediaLibrary\FileAdder', 'type' => '->'],
        ['file' => "{$root}app/Jobs/ProcessProduct.php", 'line' => 31, 'function' => 'saveFromUrl', 'class' => 'App\Services\MediaStorage', 'type' => '->'],
    ]);

    $frames[] = ['file' => "{$root}artisan", 'line' => 13];

    return $frames;
}

it('keeps a huge failed job under the response limit as valid JSON, app frames first', function () {
    config(['telescope-mcp.max_response_chars' => 20000]);
    $batch = (string) Str::uuid();

    $id = $this->entry('job', [
        'name' => 'App\Jobs\ProcessProduct', 'status' => 'failed', 'queue' => 'default', 'connection' => 'redis',
        'data' => ['order' => array_fill_keys(array_map(fn ($i) => "field_{$i}", range(1, 120)), str_repeat('x', 400))],
        'exception' => ['message' => 'ProcessProduct has timed out.', 'line' => 15, 'line_preview' => ['15' => 'vendor code'], 'trace' => vendorHeavyTrace()],
    ], $batch, null, ['failed']);

    $response = $this->rpc('tools/call', ['name' => 'get_entry', 'arguments' => ['id' => $id]])->assertOk();
    $text = (string) $response->json('result.content.0.text');
    $data = json_decode($text, true);
    $exception = $data['entry']['content']['exception'];

    expect(mb_strlen($text))->toBeLessThanOrEqual(20000)
        ->and($data)->toBeArray()
        ->and($exception['app_frames'])->toBe([
            'app/Services/MediaStorage.php:356 → FileAdder->toMediaCollection()',
            'app/Jobs/ProcessProduct.php:31 → MediaStorage->saveFromUrl()',
        ])
        ->and($exception['trace'])->toContain('33 frames (30 in vendor/)')
        ->and($exception)->not->toHaveKey('line_preview')
        ->and($data['entry']['content']['data']['order'])->toHaveKey('…');

    config(['telescope-mcp.max_response_chars' => 60000]);
    [$full] = $this->tool('get_entry', ['id' => $id, 'include' => ['trace'], 'batch' => 'none']);

    expect($full['entry']['content']['exception']['trace'])->toHaveCount(33)
        ->and($full['entry']['content']['exception']['trace'][0])->toBe('vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php:100 → Pipeline->handle()')
        ->and($full)->not->toHaveKey('batch');
});

it('shortens values step by step but never the top-level list', function () {
    $data = ['entries' => array_map(fn ($i) => ['id' => $i, 'summary' => str_repeat('s', 900), 'nested' => range(1, 40)], range(1, 30))];

    $json = Budget::encode($data, 15000, 4000, 50);
    $decoded = json_decode($json, true);

    expect(mb_strlen($json))->toBeLessThanOrEqual(15000)
        ->and($decoded['entries'])->toHaveCount(30)
        ->and($decoded['_shortened'])->toContain('15000')
        ->and($decoded['entries'][0]['nested'])->toContain('… +35 more');
});

it('locates exceptions thrown in vendor at the first app frame', function () {
    $id = $this->entry('exception', [
        'class' => 'Illuminate\Database\QueryException', 'message' => "Table 'users' doesn't exist",
        'file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 838,
        'trace' => array_map(fn ($frame) => ['file' => $frame['file'], 'line' => $frame['line']], vendorHeavyTrace()),
    ], (string) Str::uuid(), null, [], 'family-db');

    [$data] = $this->tool('get_entry', ['id' => $id]);

    expect($data['entry']['summary'])->toContain('(MediaStorage.php:356)')
        ->and($data['entry']['content']['file'])->toBe('vendor/laravel/framework/src/Illuminate/Database/Connection.php')
        ->and($data['entry']['content']['app_frames'][0])->toBe('app/Services/MediaStorage.php:356');
});

/**
 * A scheduler run whose second task fails; optionally the task's own process
 * recorded its command entry (written before the scheduler records the task).
 *
 * @return array{exception: string, failed: string, run: ?string}
 */
function seedSchedulerRun($test, CarbonImmutable $start, bool $recorded): array
{
    $batch = (string) Str::uuid();
    $php = "'/usr/bin/php8.4' 'artisan'";

    $test->entry('query', ['sql' => 'select 1', 'time' => 1], $batch, $start);
    $test->entry('schedule', ['command' => "{$php} health:check", 'expression' => '* * * * *'], $batch, $start->addSeconds(2));
    $run = $recorded ? $test->entry('command', ['command' => 'media-library:clean', 'exit_code' => 255], (string) Str::uuid(), $start->addMinutes(49)) : null;
    $failed = $test->entry('schedule', ['command' => "{$php} media-library:clean --force", 'expression' => '0 0 * * *'], $batch, $start->addMinutes(50));
    $exception = $test->entry('exception', [
        'class' => 'Exception', 'message' => "Scheduled command [{$php} media-library:clean --force] failed with exit code [255].",
        'file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php', 'line' => 207,
    ], $batch, $start->addMinutes(50), [], 'family-schedule');

    return compact('exception', 'failed', 'run');
}

it('describes a scheduler run and the task that failed', function () {
    $start = CarbonImmutable::now()->subHours(5)->startOfMinute();
    $ids = seedSchedulerRun($this, $start, recorded: false);

    [$data] = $this->tool('get_entry', ['id' => $ids['exception']]);
    $task = $data['batch']['scheduled_tasks'][0];

    expect($data['batch']['origin']['scheduler_run'])->toMatchArray(['tasks' => 2, 'failed' => 1, 'from' => $start->toIso8601ZuluString()])
        ->and($task['id'])->toBe($ids['failed'])
        ->and($task['task'])->toBe('media-library:clean --force')
        ->and($task['ran_for'])->toBe('~49m 58s')
        ->and($task['failed'])->toBe('exit code 255')
        ->and($task['note'])->toContain('left no Telescope entry');
});

it('links a failed scheduled task to its own command run when recorded', function () {
    $ids = seedSchedulerRun($this, CarbonImmutable::now()->subHours(2)->startOfMinute(), recorded: true);

    [$data] = $this->tool('get_entry', ['id' => $ids['exception']]);

    expect($data['batch']['scheduled_tasks'][0]['command_entry']['id'])->toBe($ids['run'])
        ->and($data['batch']['scheduled_tasks'][0])->not->toHaveKey('note');
});

it('collapses repeated entries and lists problems first in the digest', function () {
    $batch = (string) Str::uuid();
    $request = $this->entry('request', ['method' => 'GET', 'uri' => '/report', 'response_status' => 200, 'duration' => 1000], $batch);

    for ($i = 0; $i < 12; $i++) {
        $this->entry('cache', ['type' => 'missed', 'key' => 'illuminate:foundation:down'], $batch);
        $this->entry('redis', ['command' => 'get down', 'time' => 0.1 + $i / 100], $batch);
        $this->entry('log', ['level' => 'info', 'message' => "step {$i}"], $batch);
    }

    $this->entry('log', ['level' => 'error', 'message' => 'Export failed'], $batch);
    $this->entry('query', ['sql' => 'select * from reports', 'time' => 100], $batch);
    $this->entry('client_request', ['method' => 'GET', 'uri' => 'https://api.example.com/x', 'response_status' => 200, 'duration' => 300], $batch);

    [$data] = $this->tool('get_entry', ['id' => $request]);
    $digest = $data['batch'];

    expect($digest['entries']['cache'])->toBe([['id' => $digest['entries']['cache'][0]['id'], 'at' => $digest['entries']['cache'][0]['at'], 'summary' => 'missed illuminate:foundation:down', 'times' => 12]])
        ->and($digest['entries']['redis'][0]['times'])->toBe(12)
        ->and($digest['entries']['log'][0]['summary'])->toBe('ERROR: Export failed')
        ->and($digest['entries']['log'])->toHaveCount(11)
        ->and(end($digest['entries']['log']))->toContain('+3 more distinct')
        ->and($digest['time'])->toMatchArray(['total_ms' => 1000, 'queries_ms' => 100, 'http_out_ms' => 300, 'unaccounted_ms' => 598.14]);

    [$brief] = $this->tool('get_entry', ['id' => $request, 'batch' => 'brief']);

    expect($brief['batch'])->toHaveKeys(['counts', 'origin', 'time'])
        ->and($brief['batch'])->not->toHaveKeys(['queries', 'entries']);
});

it('shortens long query strings in summaries', function () {
    $this->entry('request', ['method' => 'GET', 'uri' => '/api/orders?'.http_build_query(array_fill_keys(range('a', 'z'), 'value')), 'response_status' => 200, 'duration' => 5], (string) Str::uuid());

    [$data] = $this->tool('list_entries', ['type' => 'request']);

    expect($data['entries'][0]['summary'])->toStartWith('GET /api/orders?… (26 params) → 200');
});

it('returns empty filters as a JSON object', function () {
    $text = $this->rpc('tools/call', ['name' => 'list_entries', 'arguments' => ['type' => 'log']])->json('result.content.0.text');

    expect($text)->toContain('"filters":{}');
});

it('filters by level, name, exclude, status range, method, path and hostname', function () {
    $batch = fn () => (string) Str::uuid();
    $this->entry('log', ['level' => 'error', 'message' => 'boom'], $batch());
    $this->entry('log', ['level' => 'info', 'message' => 'fine'], $batch());
    $this->entry('job', ['name' => 'App\Jobs\SendInvoice', 'status' => 'processed'], $batch());
    $this->entry('job', ['name' => 'Spatie\Health\Jobs\HealthQueueJob', 'status' => 'processed'], $batch());
    $this->entry('request', ['method' => 'GET', 'uri' => '/wp-login.php', 'response_status' => 404, 'duration' => 3, 'hostname' => 'web-1'], $batch());
    $this->entry('request', ['method' => 'POST', 'uri' => '/api/orders/5', 'response_status' => 422, 'duration' => 30, 'hostname' => 'web-2'], $batch());
    $this->entry('request', ['method' => 'GET', 'uri' => '/api/orders', 'response_status' => 500, 'duration' => 900, 'hostname' => 'web-2'], $batch());

    $ids = fn (array $arguments) => collect($this->tool('list_entries', $arguments)[0]['entries'])->pluck('summary')->all();

    expect($ids(['type' => 'log', 'level' => 'error,critical']))->toBe(['ERROR: boom'])
        ->and($ids(['type' => 'job', 'name' => 'sendinvoice']))->toHaveCount(1)
        ->and($ids(['type' => 'job', 'exclude' => 'HealthQueueJob']))->toHaveCount(1)
        ->and($ids(['type' => 'request', 'min_status' => 400, 'max_status' => 499]))->toHaveCount(2)
        ->and($ids(['type' => 'request', 'min_status' => 400, 'max_status' => 499, 'exclude' => 'wp-']))->toHaveCount(1)
        ->and($ids(['type' => 'request', 'method' => 'post']))->toHaveCount(1)
        ->and($ids(['type' => 'request', 'path' => '/api/orders']))->toHaveCount(2)
        ->and($ids(['type' => 'request', 'hostname' => 'web-2']))->toHaveCount(2)
        ->and($ids(['type' => 'request', 'sort' => 'slowest', 'limit' => 2]))->toBe([
            'GET /api/orders → 500 (900ms)', 'POST /api/orders/5 → 422 (30ms)',
        ]);

    [$text, $error] = $this->tool('list_entries', ['type' => 'log', 'sort' => 'slowest']);
    expect($error)->toBeTrue()->and($text)->toContain('only applies to');
});

it('aggregates requests and jobs with stats', function () {
    $at = CarbonImmutable::now()->subMinutes(10);

    foreach ([100, 200, 300, 400, 1000] as $ms) {
        $this->entry('request', ['method' => 'GET', 'uri' => '/orders/'.random_int(1, 999), 'response_status' => $ms === 1000 ? 500 : 200, 'duration' => $ms, 'controller_action' => 'App\Http\Controllers\OrderController@show'], (string) Str::uuid(), $at);
    }

    $this->entry('request', ['method' => 'GET', 'uri' => '/health', 'response_status' => 200, 'duration' => 5], (string) Str::uuid(), $at);

    foreach (['processed', 'processed', 'failed'] as $status) {
        $this->entry('job', ['name' => 'App\Jobs\SendInvoice', 'status' => $status], (string) Str::uuid(), $at);
    }

    // Only in the previous window.
    $this->entry('job', ['name' => 'App\Jobs\OldJob', 'status' => 'processed'], (string) Str::uuid(), CarbonImmutable::now()->subMinutes(90));

    [$requests] = $this->tool('stats', ['type' => 'request', 'minutes' => 60]);
    $orders = $requests['groups'][0];

    expect($requests['sort'])->toBe('total_ms')
        ->and($requests['coverage']['complete'])->toBeTrue()
        ->and($orders)->toMatchArray([
            'group' => 'GET App\Http\Controllers\OrderController@show', 'count' => 5, 'errors' => 1,
            'total_ms' => 2000, 'p50_ms' => 300, 'p95_ms' => 1000, 'max_ms' => 1000,
        ])
        ->and($requests['groups'][1]['group'])->toBe('GET /health');

    [$jobs] = $this->tool('stats', ['type' => 'job', 'minutes' => 60, 'sort' => 'errors', 'compare_previous' => true]);

    expect($jobs['groups'][0])->toMatchArray(['group' => 'App\Jobs\SendInvoice', 'count' => 3, 'errors' => 1, 'statuses' => ['processed' => 2, 'failed' => 1]])
        ->and($jobs['groups'][0]['previous'])->toContain('new')
        ->and($jobs['previous']['totals']['count'])->toBe(1);

    [$text, $error] = $this->tool('stats', ['type' => 'job', 'sort' => 'p95_ms']);
    expect($error)->toBeTrue()->and($text)->toContain('needs durations');
});

it('searches every type but the bulky ones, all retained data', function () {
    $old = CarbonImmutable::now()->subHours(30);
    $this->entry('log', ['level' => 'info', 'message' => 'order #4711 shipped'], (string) Str::uuid());
    $this->entry('request', ['method' => 'GET', 'uri' => '/orders/4711', 'response_status' => 200, 'duration' => 5], (string) Str::uuid());
    $this->entry('query', ['sql' => 'select * from orders where id = 4711', 'time' => 1], (string) Str::uuid());
    $older = $this->entry('job', ['name' => 'App\Jobs\Ship', 'status' => 'processed', 'data' => ['order' => '#4711']], (string) Str::uuid(), $old);

    [$first] = $this->tool('search', ['text' => '4711']);
    [$all] = $this->tool('search', ['text' => '4711', 'all_types' => true]);
    [$queries] = $this->tool('search', ['text' => '4711', 'type' => 'query']);

    expect(collect($first['entries'])->pluck('type')->sort()->values()->all())->toBe(['job', 'log', 'request'])
        ->and($first['skipped_types'])->toBe(['query', 'view', 'redis', 'cache'])
        ->and($first)->not->toHaveKey('continue_with')
        ->and(collect($first['entries'])->pluck('id'))->toContain($older)
        ->and($all['entries'])->toHaveCount(4)
        ->and($all)->not->toHaveKey('skipped_types')
        ->and($queries['entries'])->toHaveCount(1);

    // Out of time: say how far it got and continue from there.
    config(['telescope-mcp.time_budget_seconds' => 0]);
    [$stopped] = $this->tool('search', ['text' => '4711']);
    config(['telescope-mcp.time_budget_seconds' => 15]);
    [$continued] = $this->tool('search', $stopped['continue_with']);

    expect($stopped['note'])->toContain('time limit')
        ->and($continued['entries'])->toHaveCount(3);

    [$paged] = $this->tool('search', ['text' => '4711', 'limit' => 1]);
    [$next] = $this->tool('search', $paged['continue_with']);

    expect($paged['continue_with'])->toHaveKey('cursor')
        ->and($next['entries'][0]['id'])->not->toBe($paged['entries'][0]['id']);
});

it('lists tags with prefix summaries', function () {
    foreach (['Auth:1', 'Auth:1', 'Auth:2', 'App\Models\Order:7'] as $tag) {
        $this->entry('request', ['method' => 'GET', 'uri' => '/', 'response_status' => 200], (string) Str::uuid(), null, [$tag]);
    }

    [$data] = $this->tool('list_tags');

    expect($data['tags'][0])->toBe(['tag' => 'Auth:1', 'count' => 2])
        ->and($data['prefixes'][0])->toBe(['prefix' => 'Auth:*', 'distinct' => 2, 'entries' => 3]);

    [$orders] = $this->tool('list_tags', ['prefix' => 'App\Models\Order:']);
    expect($orders['tags'])->toBe([['tag' => 'App\Models\Order:7', 'count' => 1]]);
});

it('names the app and counts the last day in the overview', function () {
    config(['app.name' => 'Shop']);
    $this->entry('job', ['name' => 'App\Jobs\X', 'status' => 'failed', 'hostname' => 'worker-1'], (string) Str::uuid(), CarbonImmutable::now()->subHours(5), ['failed']);
    $this->entry('request', ['method' => 'GET', 'uri' => '/', 'response_status' => 200, 'hostname' => 'web-1'], (string) Str::uuid());

    [$data] = $this->tool('overview');

    expect($data['app'])->toBe(['name' => 'Shop', 'environment' => 'testing'])
        ->and($data['hosts'])->toBe(['web-1' => 1])
        ->and($data['failed_jobs'])->toBe([])
        ->and($data['last_24h'])->toBe(['exceptions' => 0, 'failed_jobs' => 1]);
});

it('offers prompts and names the app in its instructions', function () {
    config(['app.name' => 'Shop']);

    $initialize = $this->rpc('initialize', [
        'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1.0'],
    ])->assertOk();

    expect($initialize->json('result.instructions'))->toContain('Shop (testing)');

    $prompts = collect($this->rpc('prompts/list')->assertOk()->json('result.prompts'))->pluck('name')->sort()->values()->all();
    expect($prompts)->toBe(['investigate_error', 'investigate_user', 'triage', 'why_slow']);

    $prompt = $this->rpc('prompts/get', ['name' => 'why_slow', 'arguments' => ['target' => '/api/orders']])->assertOk();
    expect($prompt->json('result.messages.0.content.text'))->toContain('/api/orders')->toContain('stats(type="request")');
});
