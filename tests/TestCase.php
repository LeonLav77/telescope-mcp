<?php

namespace LloydsDigital\TelescopeMcp\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\McpServiceProvider;
use LloydsDigital\TelescopeMcp\Support\Key;
use LloydsDigital\TelescopeMcp\TelescopeMcpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const APP_KEY = 'base64:dGVzdC1hcHAta2V5LTAxMjM0NTY3ODlhYmNkZWY=';

    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, TelescopeMcpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match (getenv('TEST_DB') ?: 'sqlite') {
            // Throwaway servers, e.g.: docker run --rm -p 3307:3306 -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=tmcp mysql:8.0
            'mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => getenv('TEST_DB_PORT') ?: 3307,
                'database' => 'tmcp', 'username' => 'root', 'password' => 'secret', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            ],
            // docker run --rm -p 5433:5432 -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=tmcp postgres:16-alpine
            'pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => getenv('TEST_DB_PORT') ?: 5433,
                'database' => 'tmcp', 'username' => 'postgres', 'password' => 'secret', 'charset' => 'utf8',
                'prefix' => '', 'search_path' => 'public',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        });
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('app.key', self::APP_KEY);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/telescope/database/migrations');
    }

    /**
     * POSTs a JSON-RPC message to the endpoint the way an MCP client does.
     * $key: null = the app's valid key, false = no Authorization header.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     */
    protected function rpc(string $method, array $params = [], string|false|null $key = null, array $headers = []): TestResponse
    {
        $key ??= Key::expected();

        return $this->postJson('/'.Key::path().'', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], array_filter([
            'Authorization' => $key ? "Bearer {$key}" : null,
            'Accept' => 'application/json, text/event-stream',
        ]) + $headers);
    }

    /**
     * Calls a tool and returns [decoded result or raw text, isError].
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: mixed, 1: bool}
     */
    protected function tool(string $name, array $arguments = []): array
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk();

        $text = $response->json('result.content.0.text');
        $decoded = json_decode((string) $text, true);

        return [$decoded ?? $text, (bool) $response->json('result.isError')];
    }

    /**
     * Inserts a Telescope entry directly, like Telescope's database storage does.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $tags
     */
    public function entry(string $type, array $content, string $batchId, ?CarbonImmutable $at = null, array $tags = [], ?string $familyHash = null): string
    {
        $uuid = (string) Str::uuid();

        DB::table('telescope_entries')->insert([
            'uuid' => $uuid,
            'batch_id' => $batchId,
            'family_hash' => $familyHash,
            'should_display_on_index' => true,
            'type' => $type,
            'content' => json_encode($content),
            'created_at' => ($at ?? CarbonImmutable::now())->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]);

        foreach ($tags as $tag) {
            DB::table('telescope_entries_tags')->insert(['entry_uuid' => $uuid, 'tag' => $tag]);
        }

        return $uuid;
    }
}
