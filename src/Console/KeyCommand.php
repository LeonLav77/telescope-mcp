<?php

namespace LloydsDigital\TelescopeMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LloydsDigital\TelescopeMcp\Support\Key;

class KeyCommand extends Command
{
    protected $signature = 'telescope-mcp:key';

    protected $description = 'Show the Telescope MCP access key (derived from APP_KEY, writes nothing)';

    public function handle(): int
    {
        $key = Key::expected();

        if ($key === null) {
            $this->error('APP_KEY is not set, so there is no key and the endpoint is off.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/').'/'.Key::path();
        $name = 'telescope-'.(Str::slug((string) config('app.name')) ?: 'app');

        $this->newLine();
        $this->line('  Key:');
        $this->line("  <fg=green>{$key}</>");
        $this->newLine();
        $this->line('  Connect Claude Code:');
        $this->line("  <fg=yellow>claude mcp add --transport http {$name} {$url} --header \"Authorization: Bearer {$key}\"</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
