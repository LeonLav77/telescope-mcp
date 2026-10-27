<?php

use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\Key;
use LloydsDigital\TelescopeMcp\Tests\TestCase;
use LloydsDigital\TelescopeMcp\Tools\TelescopeTool;

$initialize = [
    'protocolVersion' => '2025-06-18',
    'capabilities' => (object) [],
    'clientInfo' => ['name' => 'test', 'version' => '1.0'],
];

it('answers initialize with the right key', function () use ($initialize) {
    $this->rpc('initialize', $initialize)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Laravel Telescope');
});

it('returns 404 without a key or with a wrong key', function () use ($initialize) {
    $this->rpc('initialize', $initialize, key: false)->assertNotFound();
    $this->rpc('initialize', $initialize, key: 'wrong')->assertNotFound();
    $this->rpc('initialize', $initialize, key: Key::expected().'x')->assertNotFound();
    $this->rpc('initialize', $initialize, key: TestCase::APP_KEY)->assertNotFound();
});

it('does not reveal the route through GET or DELETE', function () {
    $this->get('/'.Key::path().'')->assertNotFound();
    $this->delete('/'.Key::path().'')->assertNotFound();
});

it('rejects browser requests carrying an Origin header', function () use ($initialize) {
    $this->rpc('initialize', $initialize, headers: ['Origin' => 'https://evil.example'])->assertNotFound();
});

it('requires https when configured', function () use ($initialize) {
    config(['telescope-mcp.require_https' => true]);

    $this->rpc('initialize', $initialize)->assertNotFound();
    $this->rpc('initialize', $initialize, headers: ['X-Forwarded-Proto' => 'https'])->assertOk();
});

it('requires https in production by default', function () use ($initialize) {
    $this->app['env'] = 'production';

    $plain = $this->rpc('initialize', $initialize);
    $forwarded = $this->rpc('initialize', $initialize, headers: ['X-Forwarded-Proto' => 'https']);

    $this->app['env'] = 'testing'; // teardown migrations refuse to run in production

    $plain->assertNotFound();
    $forwarded->assertOk();
});

it('rejects oversized bodies', function () {
    config(['telescope-mcp.max_body_bytes' => 100]);

    $this->rpc('tools/call', ['name' => 'overview', 'arguments' => ['padding' => str_repeat('a', 200)]])->assertStatus(413);
});

it('goes dark when disabled, even with cached routes', function () use ($initialize) {
    config(['telescope-mcp.enabled' => false]);

    $this->rpc('initialize', $initialize)->assertNotFound();
});

it('goes dark without an APP_KEY', function () use ($initialize) {
    $key = Key::expected();
    config(['app.key' => '']);

    $this->rpc('initialize', $initialize, key: $key)->assertNotFound();
});

it('rotates the key with APP_KEY', function () use ($initialize) {
    $old = Key::expected();
    $path = Key::path();
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    // The derived path moves with APP_KEY too, from the next boot; this app booted with the old one.
    expect(Key::path())->not->toBe($path);
    config(['telescope-mcp.path' => $path]);

    expect(Key::expected())->not->toBe($old)->toStartWith('tmcp_')->toHaveLength(69);
    $this->rpc('initialize', $initialize, key: $old)->assertNotFound();
    $this->rpc('initialize', $initialize)->assertOk();
});

it('lists exactly the read-only tools', function () {
    $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name')->sort()->values()->all())->toBe(['get_batch', 'get_entry', 'list_entries', 'list_tags', 'n_plus_one', 'overview', 'record_history', 'search', 'stats', 'user_activity']);
    $tools->each(fn ($tool) => expect($tool['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tool['annotations']['openWorldHint'])->toBeFalse());
});

it('rolls back anything a tool call writes', function () {
    $tool = new class extends TelescopeTool
    {
        protected function run(Input $input, Entries $entries): array
        {
            DB::table('telescope_monitoring')->insert(['tag' => 'x']);

            return ['written' => DB::table('telescope_monitoring')->count()];
        }
    };

    $response = $tool->handle(new Request);

    expect(json_decode((string) $response->content(), true)['written'])->toBe(1)
        ->and(DB::table('telescope_monitoring')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('keeps a failing overview section from breaking the others', function () {
    $entries = new Entries;
    DB::beginTransaction();

    try {
        expect(fn () => $entries->isolated(fn () => DB::select('select * from missing_table')))->toThrow(Exception::class);
        expect($entries->query()->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});

it('shows the key and the claude command', function () {
    config(['app.url' => 'https://shop.example', 'app.name' => 'My Shop']);

    $this->artisan('telescope-mcp:key')
        // Most specific first: each output line is matched by the first expectation that fits.
        ->expectsOutputToContain('claude mcp add --transport http telescope-my-shop https://shop.example/'.Key::path().' --header "Authorization: Bearer '.Key::expected().'"')
        ->expectsOutputToContain(Key::expected())
        ->assertSuccessful();

    config(['app.key' => '']);
    $this->artisan('telescope-mcp:key')->assertFailed();
});
