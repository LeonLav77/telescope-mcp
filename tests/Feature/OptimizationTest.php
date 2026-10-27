<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Window;

/**
 * Inserts an entry at an explicit sequence, to create the large gaps between
 * sequences that a busy production table has between rare entries.
 */
function entryAt($test, int $sequence, string $type, array $content, ?CarbonImmutable $at = null): string
{
    $uuid = (string) Str::uuid();

    DB::table('telescope_entries')->insert([
        'sequence' => $sequence,
        'uuid' => $uuid,
        'batch_id' => (string) Str::uuid(),
        'should_display_on_index' => true,
        'type' => $type,
        'content' => json_encode($content),
        'created_at' => ($at ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
    ]);

    return $uuid;
}

it('reads type-filtered queries through the type index on MySQL', function () {
    $sql = (new Entries)->typed('request')->toSql();

    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($sql)->toContain('force index (telescope_entries_type_should_display_on_index_index)')
            ->and($sql)->toContain('`should_display_on_index` = ?');
    } else {
        expect($sql)->not->toContain('force index');
    }

    // Older occurrences of an exception have should_display_on_index = false: never filter exceptions on it.
    expect((new Entries)->typed('exception')->toSql())->not->toMatch('/[`"]should_display_on_index[`"] =/')
        ->and((new Entries)->typed(['request', 'job'])->toSql())->not->toContain('force index');
});

it('pages completely and in order across sparse sequence ranges', function () {
    $sequences = [5, 7, 120_000, 120_001, 900_000, 3_500_000, 3_500_002, 9_000_000];

    foreach ($sequences as $i => $sequence) {
        entryAt($this, $sequence, 'log', ['level' => 'error', 'message' => "m{$i}"]);
        entryAt($this, $sequence + 10, 'query', ['sql' => 'select 1', 'time' => 1]);
    }

    $seen = [];
    $arguments = ['type' => 'log', 'limit' => 3];

    do {
        [$page] = $this->tool('list_entries', $arguments);
        $seen = array_merge($seen, array_column($page['entries'], 'summary'));
        $arguments = $page['continue_with'] ?? null;
    } while ($arguments);

    expect($seen)->toBe(array_map(fn ($i) => "ERROR: m{$i}", array_reverse(array_keys($sequences))));

    [$stats] = $this->tool('stats', ['type' => 'log', 'minutes' => 60]);
    expect($stats['totals']['count'])->toBe(8)->and($stats['coverage']['complete'])->toBeTrue();
});

it('counts entries per type in a window like a plain group by', function () {
    $at = CarbonImmutable::now()->subMinutes(30);
    $this->entry('log', [], (string) Str::uuid(), CarbonImmutable::now()->subHours(3)); // before the window

    foreach (['request' => 3, 'query' => 7, 'cache' => 2] as $type => $count) {
        for ($i = 0; $i < $count; $i++) {
            $this->entry($type, [], (string) Str::uuid(), $at);
        }
    }

    $this->entry('exception', ['class' => 'E'], (string) Str::uuid(), $at, [], 'f');
    DB::table('telescope_entries')->where('type', 'exception')->update(['should_display_on_index' => false]);

    $counts = (new Entries)->countsInWindow(new Window(CarbonImmutable::now()->subHour(), null));

    expect($counts)->toBe(['query' => 7, 'request' => 3, 'cache' => 2, 'exception' => 1]);
});

it('matches request paths only at the start of the URI', function () {
    $this->entry('request', ['ip_address' => '1.2.3.4', 'uri' => '/login', 'method' => 'POST', 'response_status' => 302], (string) Str::uuid());
    $this->entry('request', ['ip_address' => '1.2.3.4', 'uri' => '/orders', 'method' => 'GET', 'headers' => ['referer' => 'https://x.test/login'], 'response_status' => 200], (string) Str::uuid());

    [$data] = $this->tool('list_entries', ['type' => 'request', 'path' => '/login']);
    [$search] = $this->tool('list_entries', ['type' => 'request', 'search' => '/login']);

    expect($data['entries'])->toHaveCount(1)
        ->and($data['entries'][0]['summary'])->toStartWith('POST /login')
        ->and($search['entries'])->toHaveCount(2);
});

it('says which overview and user sections stopped at the time limit instead of looking empty', function () {
    $this->entry('job', ['name' => 'App\Jobs\X', 'status' => 'failed'], (string) Str::uuid(), null, ['failed']);
    config(['telescope-mcp.time_budget_seconds' => 0]);

    [$overview] = $this->tool('overview');

    expect($overview['failed_jobs'])->toBe([])
        ->and($overview['incomplete'])->toHaveKeys(['failed_jobs', 'slow_queries', 'server_errors'])
        ->and($overview['incomplete']['failed_jobs'])->toContain('list_entries(type="job", status="failed")');

    config(['telescope-mcp.time_budget_seconds' => 15]);
    [$complete] = $this->tool('overview');

    expect($complete)->not->toHaveKey('incomplete')
        ->and($complete['failed_jobs'])->toHaveCount(1);
});
