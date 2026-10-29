<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LloydsDigital\TelescopeMcp\Tests\Fixtures\LookupOrder;

/**
 * A browser request as Telescope stores it: boilerplate headers, a query
 * string repeated in the payload and a session cookie in the response.
 */
function seedBrowserRequest($test): string
{
    return $test->entry('request', [
        'method' => 'GET', 'uri' => '/api/orders?draw=3&start=0&length=50', 'response_status' => 200, 'duration' => 40,
        'payload' => ['draw' => '3', 'start' => '0', 'length' => '50'],
        'headers' => [
            'host' => 'shop.test', 'referer' => 'https://shop.test/orders', 'user-agent' => 'Mozilla', 'x-request-id' => 'r-1',
            'sec-ch-ua' => '"Chromium";v="156"', 'sec-fetch-mode' => 'cors', 'accept-encoding' => 'gzip', 'cookie' => 'app_session=eyJzZXNz',
        ],
        'response_headers' => [
            'content-type' => 'application/json', 'content-security-policy' => "connect-src 'self'", 'date' => 'Thu, 29 Oct 2026',
            'set-cookie' => 'XSRF-TOKEN=eyJpdiI6; path=/; samesite=lax, app_session=eyJzZXNz; path=/; httponly',
        ],
    ], (string) Str::uuid());
}

it('masks session cookies even with redaction off', function () {
    $id = seedBrowserRequest($this);
    $text = $this->rpc('tools/call', ['name' => 'get_entry', 'arguments' => ['id' => $id, 'include' => ['headers']]])->json('result.content.0.text');
    [$data] = $this->tool('get_entry', ['id' => $id, 'include' => ['headers']]);

    expect($text)->not->toContain('eyJzZXNz')->not->toContain('eyJpdiI6')
        ->and($data['entry']['content']['response_headers']['set-cookie'])->toBe('XSRF-TOKEN=********; path=/; samesite=lax, app_session=********; path=/; httponly')
        ->and($data['entry']['content']['headers']['cookie'])->toBe('********');

    config(['telescope-mcp.mask_cookies' => false]);
    [$raw] = $this->tool('get_entry', ['id' => $id, 'include' => ['headers']]);

    expect($raw['entry']['content']['headers']['cookie'])->toBe('app_session=eyJzZXNz');
});

it('links entries to the Telescope dashboard', function () {
    $id = seedBrowserRequest($this);

    [$default] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none']);
    config(['telescope-mcp.telescope_url' => 'https://admin.shop.test/watch/']);
    [$configured] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none']);
    config(['telescope-mcp.telescope_url' => false]);
    [$off] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none']);

    expect($default['entry']['url'])->toBe("http://localhost/telescope/requests/{$id}")
        ->and($configured['entry']['url'])->toBe("https://admin.shop.test/watch/requests/{$id}")
        ->and($off['entry'])->not->toHaveKey('url');
});

it('leaves boilerplate out of requests unless asked for headers', function () {
    $id = seedBrowserRequest($this);

    [$compact] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none']);
    [$full] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none', 'include' => ['headers']]);
    $content = $compact['entry']['content'];

    expect(array_keys($content['headers']))->toBe(['host', 'referer', 'user-agent', 'x-request-id', 'cookie'])
        ->and(array_keys($content['response_headers']))->toBe(['content-type', 'set-cookie'])
        ->and($content['uri'])->toBe('/api/orders?… (3 params, see payload)')
        ->and($compact['entry']['omitted'])->toContain('3 boilerplate headers')->toContain('query string')
        ->and($full['entry']['content']['uri'])->toBe('/api/orders?draw=3&start=0&length=50')
        ->and($full['entry']['content']['headers'])->toHaveKey('sec-ch-ua')
        ->and($full['entry'])->not->toHaveKey('omitted');
});

it('keeps a query string that is not repeated in the payload', function () {
    $id = $this->entry('request', [
        'method' => 'POST', 'uri' => '/orders?ref=mail', 'payload' => ['sku' => 'A-1'], 'response_status' => 302,
    ], (string) Str::uuid());

    [$data] = $this->tool('get_entry', ['id' => $id, 'batch' => 'none']);

    expect($data['entry']['content']['uri'])->toBe('/orders?ref=mail');
});

it('finds a record by an enabled business attribute and merges the history of several matches', function () {
    Schema::create('lookup_orders', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->integer('order_number');
    });
    LookupOrder::query()->insert([['id' => 'line-a', 'order_number' => 59308], ['id' => 'line-b', 'order_number' => 59308], ['id' => 'other', 'order_number' => 1]]);
    config(['telescope-mcp.record_lookup' => [LookupOrder::class => ['order_number']]]);

    $class = LookupOrder::class;
    $at = CarbonImmutable::now()->subHour();
    $take = (string) Str::uuid();
    $request = $this->entry('request', ['method' => 'POST', 'uri' => '/orders/work', 'response_status' => 302, 'user' => ['id' => 7, 'name' => 'Zainab']], $take, $at);
    $this->entry('model', ['action' => 'updated', 'model' => "{$class}:line-a", 'changes' => ['assignee_id' => 7]], $take, $at, ["{$class}:line-a"]);
    $this->entry('model', ['action' => 'updated', 'model' => "{$class}:line-b", 'changes' => ['status' => 9]], (string) Str::uuid(), $at->addMinute(), ["{$class}:line-b"]);

    [$data, $error] = $this->tool('record_history', ['model' => $class, 'attribute' => 'order_number', 'value' => '59308']);

    expect($error)->toBeFalse()
        ->and($data['record'])->toBe(["{$class}:line-a", "{$class}:line-b"])
        ->and(array_column($data['history'], 'record'))->toBe(['line-a', 'line-b'])
        ->and($data['history'][0]['via_id'])->toBe($request)
        ->and($data['history'][0]['url'])->toStartWith('http://localhost/telescope/models/');

    [$single] = $this->tool('record_history', ['model' => $class, 'key' => 'line-a']);
    expect($single['record'])->toBe("{$class}:line-a")
        ->and($single['history'][0])->not->toHaveKey('record');
});

it('refuses lookups the app did not enable, and says when nothing matches', function () {
    Schema::create('lookup_orders', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->integer('order_number');
        $table->string('customer_email')->nullable();
    });
    config(['telescope-mcp.record_lookup' => [LookupOrder::class => ['order_number']]]);

    [$notEnabled, $error1] = $this->tool('record_history', ['model' => LookupOrder::class, 'attribute' => 'customer_email', 'value' => 'a@b.c']);
    [$noMatch, $error2] = $this->tool('record_history', ['model' => LookupOrder::class, 'attribute' => 'order_number', 'value' => '404']);
    [$missing, $error3] = $this->tool('record_history', ['model' => LookupOrder::class]);

    expect($error1)->toBeTrue()->and($notEnabled)->toContain("isn't enabled")->toContain('enabled attributes: order_number')
        ->and($error2)->toBeTrue()->and($noMatch)->toContain('has order_number = "404"')
        ->and($error3)->toBeTrue()->and($missing)->toContain('Pass [key], or [attribute] and [value]');
});

it('shows how steadily a user sends requests', function () {
    $start = CarbonImmutable::now()->subMinutes(30);

    foreach (range(0, 5) as $i) {
        $this->entry('request', ['method' => 'GET', 'uri' => '/api/orders', 'response_status' => 200, 'duration' => 30, 'user' => ['id' => 1, 'name' => 'Tanvir']], (string) Str::uuid(), $start->addSeconds($i * 2));
    }

    $this->entry('request', ['method' => 'GET', 'uri' => '/orders', 'response_status' => 200, 'duration' => 90, 'user' => ['id' => 2, 'name' => 'Noor']], (string) Str::uuid(), $start);

    [$data] = $this->tool('stats', ['type' => 'request', 'group_by' => 'user', 'minutes' => 60]);
    $groups = collect($data['groups'])->keyBy(fn ($group) => str_starts_with($group['group'], 'Tanvir') ? 'tanvir' : 'noor');

    expect($groups['tanvir']['median_gap_s'])->toEqual(2)
        ->and($groups['noor'])->not->toHaveKey('median_gap_s');
});
