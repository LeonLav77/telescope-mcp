<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LloydsDigital\TelescopeMcp\Support\Present;

const KASHIF = ['id' => 'u-1', 'name' => 'Kashif', 'email' => 'kashif@example.com'];
const NOOR = ['id' => 'u-2', 'name' => 'Noor', 'email' => 'noor@example.com'];

/**
 * One request by a logged-in user, the way Telescope records it: the user on
 * every entry's content and an "Auth:<id>" tag on each of them.
 *
 * @param  array<string, mixed>  $request
 * @param  list<array<string, mixed>>  $models
 * @return array{request: string, batch: string}
 */
function userRequest($test, array $user, array $request, array $models = [], ?CarbonImmutable $at = null): array
{
    $batch = (string) Str::uuid();
    $auth = ['Auth:'.$user['id']];

    foreach ($models as $model) {
        $test->entry('model', $model + ['user' => $user], $batch, $at, array_merge($auth, [$model['model']]));
    }

    $id = $test->entry('request', $request + ['user' => $user, 'duration' => 50, 'response_status' => 200, 'ip_address' => '10.0.0.1'], $batch, $at, $auth);

    return ['request' => $id, 'batch' => $batch];
}

it('tells what a user did: actions with changes, activity and routes', function () {
    $start = CarbonImmutable::now()->subHours(3);

    for ($i = 0; $i < 6; $i++) {
        userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/api/orders?page=1', 'controller_action' => 'App\Http\Controllers\OrderController@index'], [], $start->addMinutes($i));
    }

    $write = userRequest($this, KASHIF, ['method' => 'POST', 'uri' => '/orders/7/take', 'controller_action' => 'App\Http\Controllers\OrderController@take'], [
        ['action' => 'updated', 'model' => 'App\Models\Order:7', 'changes' => ['status' => 5, 'assignee_id' => 'u-1', 'updated_at' => 'now']],
        ['action' => 'created', 'model' => 'App\Models\OrderTakenEntry:99', 'changes' => ['order_id' => 7]],
    ], $start->addMinutes(10));

    userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/orders/7', 'controller_action' => 'App\Http\Controllers\OrderController@show', 'response_status' => 500], [], $start->addMinutes(11));
    userRequest($this, NOOR, ['method' => 'GET', 'uri' => '/orders', 'controller_action' => 'App\Http\Controllers\OrderController@index'], [], $start);

    [$byEmail] = $this->tool('user_activity', ['user' => 'kashif@example.com']);
    [$byId] = $this->tool('user_activity', ['user' => 'u-1']);

    expect($byEmail['user'])->toBe(['id' => 'u-1', 'name' => 'Kashif', 'email' => 'kashif@example.com'])
        ->and($byId['user']['id'])->toBe('u-1')
        ->and($byEmail['requests'])->toMatchArray(['count' => 8, 'writes' => 1, 'server_errors' => 1])
        ->and($byEmail['actions'][0]['request'])->toContain('GET /orders/7')->toContain('OrderController@show')
        ->and($byEmail['actions'][1]['id'])->toBe($write['request'])
        ->and($byEmail['actions'][1]['changed'])->toBe(['updated Order:7 [status, assignee_id]', 'created OrderTakenEntry:99 [order_id]'])
        ->and(collect($byEmail['changes'])->keyBy('change')['updated App\Models\Order'])->toMatchArray(['change' => 'updated App\Models\Order', 'times' => 1, 'records' => ['7' => 1], 'fields' => ['status', 'assignee_id']])
        ->and($byEmail['routes'][0])->toMatchArray(['route' => 'GET OrderController@index', 'count' => 6])
        ->and(array_sum(array_column($byEmail['activity'], 'requests')))->toBe(8);
});

it('asks which user is meant when a name matches several', function () {
    userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/']);
    userRequest($this, ['id' => 'u-3', 'name' => 'Kashif Ali', 'email' => 'ali@example.com'], ['method' => 'GET', 'uri' => '/']);

    [$data] = $this->tool('user_activity', ['user' => 'kashif']);

    expect(collect($data['candidates'])->pluck('id')->sort()->values()->all())->toBe(['u-1', 'u-3']);

    [$text, $error] = $this->tool('user_activity', ['user' => 'nobody']);
    expect($error)->toBeTrue()->and($text)->toContain('No logged-in user matching');
});

it('follows a user through a common Auth tag as well as a rare one', function () {
    // More tagged entries than the rare-tag threshold switches to the per-entry check.
    $batch = (string) Str::uuid();
    $now = CarbonImmutable::now()->subMinute()->format('Y-m-d H:i:s');

    foreach (array_chunk(range(1, 2100), 500) as $chunk) {
        $rows = array_map(fn () => ['uuid' => (string) Str::uuid(), 'batch_id' => $batch, 'type' => 'query', 'should_display_on_index' => true, 'content' => json_encode(['sql' => 'select 1', 'time' => 1, 'user' => KASHIF]), 'created_at' => $now], $chunk);
        DB::table('telescope_entries')->insert($rows);
        DB::table('telescope_entries_tags')->insert(array_map(fn ($row) => ['entry_uuid' => $row['uuid'], 'tag' => 'Auth:u-1'], $rows));
    }

    userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/orders']);

    [$requests] = $this->tool('list_entries', ['type' => 'request', 'tag' => 'Auth:u-1']);
    [$queries] = $this->tool('stats', ['type' => 'query', 'tag' => 'Auth:u-1']);

    expect($requests['entries'])->toHaveCount(1)
        ->and($requests['entries'][0]['summary'])->toEndWith('· Kashif')
        ->and($queries['totals']['count'])->toBe(2100);
});

it('groups stats by user, ip and status, with every list filter', function () {
    userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/a']);
    userRequest($this, KASHIF, ['method' => 'GET', 'uri' => '/b', 'response_status' => 404]);
    userRequest($this, NOOR, ['method' => 'GET', 'uri' => '/c']);
    $this->entry('request', ['method' => 'GET', 'uri' => '/wp-login.php', 'response_status' => 404, 'ip_address' => '6.6.6.6'], (string) Str::uuid());

    [$users] = $this->tool('stats', ['type' => 'request', 'group_by' => 'user', 'sort' => 'count']);
    [$ips] = $this->tool('stats', ['type' => 'request', 'group_by' => 'ip', 'min_status' => 400, 'max_status' => 499, 'sort' => 'count']);
    [$statuses] = $this->tool('stats', ['type' => 'request', 'group_by' => 'status', 'sort' => 'count']);

    expect($users['groups'][0])->toMatchArray(['group' => 'Kashif <kashif@example.com> [id u-1]', 'count' => 2, 'client_errors' => 1])
        ->and(collect($users['groups'])->pluck('group'))->toContain('not logged in')
        ->and($ips['filters'])->toBe(['min_status' => 400, 'max_status' => 499])
        ->and(collect($ips['groups'])->pluck('count', 'group')->all())->toEqualCanonicalizing(['10.0.0.1' => 1, '6.6.6.6' => 1])
        ->and(collect($statuses['groups'])->pluck('count', 'group')->all())->toEqualCanonicalizing(['200' => 2, '404' => 2]);

    [$text, $error] = $this->tool('stats', ['type' => 'job', 'group_by' => 'ip']);
    expect($error)->toBeTrue()->and($text)->toContain('only applies to');
});

it('tells the history of a record: changes, who and through what', function () {
    $at = CarbonImmutable::now()->subHours(2);
    $jobBatch = (string) Str::uuid();
    $workerBatch = (string) Str::uuid();

    $this->entry('job', ['name' => 'App\Jobs\ImportOrder', 'status' => 'processed', 'updated_batch_id' => $workerBatch], $jobBatch, $at);
    $this->entry('model', ['action' => 'created', 'model' => 'App\Models\Order:7', 'changes' => ['status' => 4]], $workerBatch, $at->addSecond(), ['App\Models\Order:7']);
    userRequest($this, KASHIF, ['method' => 'POST', 'uri' => '/orders/7/take', 'controller_action' => 'App\Http\Controllers\OrderController@take'], [
        ['action' => 'updated', 'model' => 'App\Models\Order:7', 'changes' => ['status' => 5, 'updated_at' => 'x']],
    ], $at->addHour());

    [$data] = $this->tool('record_history', ['model' => 'Order', 'key' => '7']);

    expect($data['record'])->toBe('App\Models\Order:7')
        ->and($data['history'][0])->toMatchArray(['what' => 'created Order', 'set' => ['status' => 4], 'via' => 'job run: App\Jobs\ImportOrder processed (queue: , connection: )'])
        ->and($data['history'][1])->toMatchArray(['what' => 'updated Order', 'set' => ['status' => 5], 'by' => 'Kashif <kashif@example.com>'])
        ->and($data['history'][1]['via'])->toStartWith('POST /orders/7/take → 200')->not->toContain('· Kashif');

    [$text, $error] = $this->tool('record_history', ['model' => 'Order', 'key' => '8']);
    expect($error)->toBeTrue()->and($text)->toContain('Order:7');
});

/**
 * A request that runs one query per item (N+1) and repeats an identical one.
 */
function nPlusOneRequest($test, string $uri, string $action, int $items = 6): string
{
    $batch = (string) Str::uuid();

    for ($i = 1; $i <= $items; $i++) {
        $test->entry('query', ['sql' => "select * from roles where id = {$i}", 'time' => 2, 'hash' => 'roles-by-id', 'file' => '/app/app/Models/Role.php', 'line' => 34], $batch);
        $test->entry('query', ['sql' => "select * from settings where name = 'site'", 'time' => 1, 'hash' => 'settings', 'file' => '/app/app/Settings.php', 'line' => 9], $batch);
    }

    $test->entry('query', ['sql' => 'select * from users where id = 1', 'time' => 1, 'hash' => 'users', 'file' => '/app/app/Auth.php', 'line' => 3], $batch);

    return $test->entry('request', ['method' => 'GET', 'uri' => $uri, 'controller_action' => $action, 'response_status' => 200, 'duration' => 80], $batch);
}

it('finds N+1 queries across requests and says where they happen', function () {
    nPlusOneRequest($this, '/orders', 'App\Http\Controllers\OrderController@index');
    nPlusOneRequest($this, '/orders', 'App\Http\Controllers\OrderController@index');
    nPlusOneRequest($this, '/reports', 'App\Http\Controllers\ReportController@show', 3);

    [$data] = $this->tool('n_plus_one', ['sort' => 'batches']);
    $groups = collect($data['groups'])->keyBy('location');

    expect($data['coverage']['complete'])->toBeTrue()
        ->and($groups['Role.php:34'])->toMatchArray(['kind' => 'n+1', 'batches' => 2, 'max_repeats' => 6, 'total_ms' => 24.0, 'where' => ['GET OrderController@index' => 2]])
        ->and($groups['Role.php:34']['sql'])->toBe('select * from roles where id = ?')
        ->and($groups['Role.php:34']['sample']['type'])->toBe('request')
        ->and($groups['Settings.php:9']['kind'])->toBe('identical')
        ->and($groups)->not->toHaveKey('Auth.php:3');

    [$reports] = $this->tool('n_plus_one', ['path' => '/reports', 'min_repeats' => 3]);

    expect($reports['coverage']['matched_batches'])->toBe(1)
        ->and(collect($reports['groups'])->pluck('where')->first())->toBe(['GET ReportController@show' => 1]);
});

it('labels repeated queries in the batch digest', function () {
    $request = nPlusOneRequest($this, '/orders', 'App\Http\Controllers\OrderController@index');

    [$data] = $this->tool('get_entry', ['id' => $request]);
    $repeated = collect($data['batch']['queries']['repeated'])->keyBy('location');

    expect($data['batch']['queries']['n_plus_one'])->toBe(1)
        ->and($repeated['Role.php:34'])->toMatchArray(['kind' => 'n+1', 'times' => 6, 'distinct_values' => 6, 'sql' => 'select * from roles where id = ?'])
        ->and($repeated['Settings.php:9'])->toMatchArray(['kind' => 'identical', 'times' => 6]);
});

it('stops long scans at the time budget and says so', function () {
    config(['telescope-mcp.time_budget_seconds' => 0]);

    for ($i = 0; $i < 3; $i++) {
        $this->entry('request', ['method' => 'GET', 'uri' => '/', 'response_status' => 200, 'duration' => 5], (string) Str::uuid());
    }

    [$data] = $this->tool('stats', ['type' => 'request']);

    expect($data['coverage']['complete'])->toBeFalse()
        ->and($data['coverage']['note'])->toContain('time limit');
});

it('normalizes generated path segments', function () {
    expect(Present::path('/uploads/189081/file-1792182870-411232070.png'))->toBe('/uploads/{id}/{id}.png')
        ->and(Present::path('/api/users/01971767-4ace-72bc-a84b-5879c3371b4d/orders?page=2'))->toBe('/api/users/{id}/orders')
        ->and(Present::path('/css/app.css'))->toBe('/css/app.css')
        ->and(Present::pattern('select * from "users" where "id" = 5 and name = \'x\'', true))->toBe('select * from "users" where "id" = ? and name = ?');
});
