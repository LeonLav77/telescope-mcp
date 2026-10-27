<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Seeds a realistic graph: a request that runs an N+1, logs, throws and
 * dispatches a job; the job's worker execution has its own batch.
 */
function seedGraph($test, ?CarbonImmutable $at = null): array
{
    $at ??= CarbonImmutable::now()->subMinutes(5);
    $requestBatch = (string) Str::uuid();
    $workerBatch = (string) Str::uuid();

    $request = $test->entry('request', [
        'method' => 'POST', 'uri' => '/orders', 'response_status' => 500, 'duration' => 812,
        'controller_action' => 'App\Http\Controllers\OrderController@store', 'payload' => ['sku' => 'A-1'],
    ], $requestBatch, $at);

    for ($i = 1; $i <= 4; $i++) {
        $test->entry('query', [
            'sql' => "select * from products where id = {$i}", 'time' => 1.5, 'hash' => 'products-by-id',
            'file' => '/app/Http/Controllers/OrderController.php', 'line' => 41, 'slow' => false,
        ], $requestBatch, $at);
    }

    $slow = $test->entry('query', [
        'sql' => 'select * from orders where total > 100', 'time' => 950.2, 'hash' => 'orders-total',
        'file' => '/app/Models/Order.php', 'line' => 10, 'slow' => true,
    ], $requestBatch, $at, ['slow']);

    $test->entry('log', ['level' => 'error', 'message' => 'Payment gateway timeout'], $requestBatch, $at);

    $exception = $test->entry('exception', [
        'class' => 'Illuminate\Database\QueryException', 'message' => 'SQLSTATE[23000]: Integrity constraint violation',
        'file' => '/app/Services/OrderService.php', 'line' => 88, 'trace' => [['file' => '/app/x.php', 'line' => 1]],
    ], $requestBatch, $at, [], 'family-1');

    $job = $test->entry('job', [
        'name' => 'App\Jobs\SendInvoice', 'status' => 'failed', 'queue' => 'default', 'connection' => 'redis',
        'updated_batch_id' => $workerBatch, 'exception' => ['message' => 'SMTP down'],
    ], $requestBatch, $at, ['failed']);

    $workerQuery = $test->entry('query', [
        'sql' => 'select * from invoices where order_id = 7', 'time' => 3.1, 'hash' => 'invoices',
        'file' => '/app/Jobs/SendInvoice.php', 'line' => 20,
    ], $workerBatch, $at->addSeconds(5));

    $test->entry('exception', [
        'class' => 'Symfony\Component\Mailer\Exception\TransportException', 'message' => 'SMTP down',
        'file' => '/app/Jobs/SendInvoice.php', 'line' => 30,
    ], $workerBatch, $at->addSeconds(5), [], 'family-2');

    return compact('requestBatch', 'workerBatch', 'request', 'slow', 'exception', 'job', 'workerQuery');
}

it('gives an overview with retention, counts and problems', function () {
    $ids = seedGraph($this);

    [$data, $error] = $this->tool('overview');

    expect($error)->toBeFalse()
        ->and($data['data_available']['oldest'])->not->toBeNull()
        ->and($data['counts']['query'])->toBe(6)
        ->and($data['exception_groups'])->toHaveCount(2)
        ->and($data['server_errors'][0]['id'])->toBe($ids['request'])
        ->and($data['server_errors'][0]['summary'])->toContain('POST /orders → 500')
        ->and($data['failed_jobs'][0]['id'])->toBe($ids['job'])
        ->and($data['slow_queries'][0]['id'])->toBe($ids['slow']);
});

it('reports when Telescope has no data', function () {
    [$data] = $this->tool('overview');

    expect($data['data_available'])->toBeNull()->and($data['note'])->toContain('no stored entries');
});

it('drills from a request into its queries, exception and job', function () {
    $ids = seedGraph($this);

    [$data, $error] = $this->tool('get_entry', ['id' => $ids['request']]);

    expect($error)->toBeFalse()
        ->and($data['entry']['content']['payload'])->toBe(['sku' => 'A-1'])
        ->and($data['batch']['counts'])->toMatchArray(['query' => 5, 'exception' => 1, 'job' => 1, 'log' => 1])
        ->and($data['batch']['queries']['count'])->toBe(5)
        ->and($data['batch']['queries']['slowest'][0]['id'])->toBe($ids['slow'])
        ->and($data['batch']['queries']['repeated'][0]['times'])->toBe(4)
        ->and($data['batch']['entries']['exception'][0]['id'])->toBe($ids['exception'])
        ->and($data['batch']['entries']['job'][0]['execution_batch'])->toBe($ids['workerBatch'])
        ->and($data['batch']['entries'])->not->toHaveKey('request'); // the entry itself is excluded
});

it('follows a job into its own execution batch', function () {
    $ids = seedGraph($this);

    [$data] = $this->tool('get_entry', ['id' => $ids['job']]);

    expect($data['batch']['origin']['id'])->toBe($ids['request'])
        ->and($data['execution_batch']['batch_id'])->toBe($ids['workerBatch'])
        ->and($data['execution_batch']['queries']['slowest'][0]['id'])->toBe($ids['workerQuery'])
        ->and($data['execution_batch']['entries']['exception'][0]['summary'])->toContain('TransportException: SMTP down');
});

it('finds the job that a worker batch executed', function () {
    $ids = seedGraph($this);

    [$data] = $this->tool('get_entry', ['id' => $ids['workerQuery']]);

    expect($data['batch']['origin']['executed_job']['id'])->toBe($ids['job']);
});

it('shows exception family occurrences', function () {
    $ids = seedGraph($this);
    $this->entry('exception', ['class' => 'Illuminate\Database\QueryException', 'message' => 'again'], (string) Str::uuid(), null, [], 'family-1');

    [$data] = $this->tool('get_entry', ['id' => $ids['exception']]);

    expect($data['exception_family']['occurrences'])->toBe(2);
});

it('lists a batch in order, filtered and paged', function () {
    $ids = seedGraph($this);

    [$page1] = $this->tool('get_batch', ['batch_id' => $ids['requestBatch'], 'type' => 'query', 'limit' => 3]);
    [$page2] = $this->tool('get_batch', ['batch_id' => $ids['requestBatch'], 'type' => 'query', 'limit' => 3, 'cursor' => $page1['next_cursor']]);

    expect($page1['entries'])->toHaveCount(3)
        ->and($page1['entries'][0]['summary'])->toContain('products where id = 1')
        ->and($page2['entries'])->toHaveCount(2)
        ->and($page2)->not->toHaveKey('next_cursor');
});

it('groups exceptions by family by default', function () {
    seedGraph($this);
    seedGraph($this);

    [$data] = $this->tool('list_entries', ['type' => 'exception']);

    expect($data['grouped'])->toBeTrue()
        ->and($data['groups'])->toHaveCount(2)
        ->and($data['groups'][0]['occurrences_in_window'])->toBe(2);

    [$all] = $this->tool('list_entries', ['type' => 'exception', 'group' => false]);
    expect($all['entries'])->toHaveCount(4);
});

it('filters by status, http status, duration and tag', function () {
    seedGraph($this);
    $this->entry('request', ['method' => 'GET', 'uri' => '/ok', 'response_status' => 200, 'duration' => 20], (string) Str::uuid());
    $this->entry('job', ['name' => 'App\Jobs\Ok', 'status' => 'processed'], (string) Str::uuid());

    [$errors] = $this->tool('list_entries', ['type' => 'request', 'min_status' => 500]);
    [$failed] = $this->tool('list_entries', ['type' => 'job', 'status' => 'failed']);
    [$processed] = $this->tool('list_entries', ['type' => 'job', 'status' => 'processed']);
    [$slow] = $this->tool('list_entries', ['type' => 'query', 'min_ms' => 100]);
    [$tagged] = $this->tool('list_entries', ['type' => 'query', 'tag' => 'slow']);

    expect($errors['entries'])->toHaveCount(1)
        ->and($failed['entries'][0]['summary'])->toContain('SendInvoice failed')
        ->and($processed['entries'][0]['summary'])->toContain('App\Jobs\Ok processed')
        ->and($slow['entries'])->toHaveCount(1)
        ->and($tagged['entries'])->toHaveCount(1);
});

it('finds errors from three days ago on long retention', function () {
    $old = seedGraph($this, CarbonImmutable::now()->subDays(3)->setTime(9, 30));
    seedGraph($this);

    $from = CarbonImmutable::now()->subDays(3)->setTime(9, 0)->toIso8601ZuluString();
    $to = CarbonImmutable::now()->subDays(3)->setTime(10, 0)->toIso8601ZuluString();

    [$data] = $this->tool('list_entries', ['type' => 'exception', 'from' => $from, 'to' => $to]);
    [$overview] = $this->tool('overview', ['from' => $from, 'to' => $to]);

    expect($data['groups'])->toHaveCount(2)
        ->and(collect($data['groups'])->pluck('latest.id'))->toContain($old['exception'])
        ->and($overview['counts']['query'])->toBe(6)
        ->and($overview['server_errors'][0]['id'])->toBe($old['request']);
});

it('says when a window is older than retained data', function () {
    seedGraph($this);

    [$data] = $this->tool('overview', ['from' => CarbonImmutable::now()->subDays(10)->toIso8601ZuluString(), 'minutes' => null]);

    expect($data['note'])->toContain('older data has been pruned');
});

it('searches content literally over the whole window', function () {
    seedGraph($this);

    [$hit] = $this->tool('list_entries', ['type' => 'request', 'search' => '/orders']);
    [$wildcard] = $this->tool('list_entries', ['type' => 'request', 'search' => '%']);
    [$upper] = $this->tool('list_entries', ['type' => 'request', 'search' => '/ORDERS']);
    [$class] = $this->tool('list_entries', ['type' => 'job', 'search' => 'App\Jobs\SendInvoice']);
    [$json] = $this->tool('list_entries', ['type' => 'job', 'search' => '"status":"failed"']);
    [$wide, $error] = $this->tool('list_entries', ['type' => 'request', 'search' => 'orders', 'minutes' => 60 * 48]);

    expect($hit['entries'])->toHaveCount(1)
        ->and($upper['entries'])->toHaveCount(1)
        ->and($class['entries'])->toHaveCount(1)
        ->and($json['entries'])->toHaveCount(1)
        ->and($wildcard['entries'] ?? [])->toHaveCount(0)
        ->and($error)->toBeFalse()
        ->and($wide['entries'])->toHaveCount(1)
        ->and(CarbonImmutable::parse($wide['window']['from'])->diffInHours(CarbonImmutable::parse($wide['window']['to'])))->toEqual(48)
        ->and($wide)->not->toHaveKey('continue_with');
});

it('pages through results with a cursor', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->entry('log', ['level' => 'info', 'message' => "m{$i}"], (string) Str::uuid());
    }

    [$page1] = $this->tool('list_entries', ['type' => 'log', 'limit' => 3]);
    [$page2] = $this->tool('list_entries', ['type' => 'log', 'limit' => 3, 'cursor' => $page1['next_cursor']]);

    expect($page1['entries'])->toHaveCount(3)
        ->and($page1['entries'][0]['summary'])->toBe('INFO: m4')
        ->and($page2['entries'])->toHaveCount(2);
});

it('returns times in UTC whatever the app timezone', function () {
    // What Laravel does at boot with app.timezone.
    config(['app.timezone' => 'Europe/Zagreb']);
    date_default_timezone_set('Europe/Zagreb');

    try {
        $at = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
        $id = $this->entry('log', ['level' => 'info', 'message' => 'tz'], (string) Str::uuid(), $at);

        [$data] = $this->tool('get_entry', ['id' => $id]);
        [$list] = $this->tool('list_entries', ['type' => 'log', 'from' => '2026-10-15T11:59:00Z', 'to' => '2026-10-15T12:01:00Z']);
    } finally {
        date_default_timezone_set('UTC');
    }

    expect($data['entry']['at'])->toBe('2026-10-15T12:00:00Z')
        ->and($list['entries'][0]['id'])->toBe($id);
});

it('rejects invalid input', function (array $arguments, string $message) {
    [$text, $error] = $this->tool('list_entries', $arguments);

    expect($error)->toBeTrue()->and($text)->toContain($message);
})->with([
    'bad type' => [['type' => 'users'], 'must be one of'],
    'sql in type' => [['type' => "request' or 1=1 --"], 'must be one of'],
    'bad date' => [['type' => 'log', 'from' => 'yesterday-ish'], 'ISO 8601'],
    'filter on wrong type' => [['type' => 'log', 'min_status' => 500], 'only applies to'],
    'limit too high' => [['type' => 'log', 'limit' => 1000], 'between 1 and 100'],
]);

it('rejects bad ids', function () {
    [$text, $error] = $this->tool('get_entry', ['id' => '1 or 1=1']);
    expect($error)->toBeTrue()->and($text)->toContain('must be a UUID');

    [$text, $error] = $this->tool('get_entry', ['id' => (string) Str::uuid()]);
    expect($error)->toBeTrue()->and($text)->toContain('No entry');
});

it('returns full error details', function () {
    config(['telescope.storage.database.connection' => 'missing']);

    [$text, $error] = $this->tool('overview');

    config(['telescope.storage.database.connection' => null]); // Telescope's migrations read it on teardown

    expect($error)->toBeTrue()->and($text)->toContain('InvalidArgumentException')->toContain('Trace:');
});

it('returns nothing, not an error, for a tag no entry has', function () {
    seedGraph($this);

    [$list, $error] = $this->tool('list_entries', ['type' => 'request', 'tag' => 'Auth:nobody']);
    [$stats] = $this->tool('stats', ['type' => 'request', 'tag' => 'Auth:nobody']);

    expect($error)->toBeFalse()
        ->and($list['entries'] ?? [])->toBeEmpty()
        ->and($stats['totals']['count'])->toBe(0);
});
