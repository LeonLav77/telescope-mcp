<?php

namespace LloydsDigital\TelescopeMcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class WhySlow extends Prompt
{
    protected string $name = 'why_slow';

    protected string $title = 'Find out why something is slow';

    protected string $description = 'Find where the time goes in a slow endpoint, job or the app overall, using Telescope data.';

    public function arguments(): array
    {
        return [
            new Argument('target', 'A route, URL path, controller or job class. Empty: the slowest parts of the app.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $target = trim((string) $request->get('target', ''));
        $scope = $target === '' ? 'the slowest parts of the app' : $target;

        return Response::text(<<<TEXT
            Find out why {$scope} is slow.

            Use the Telescope MCP tools:
            1. stats(type="request") ranks endpoints by total time (sort="p95_ms" for the worst experience);
               stats(type="query") finds the most expensive SQL. Narrow with path or name.
            2. list_entries(type="request", path=..., sort="slowest") to pick a few slow occurrences.
            3. get_entry(id) on each: `batch.time` splits the duration into queries, redis and outgoing HTTP.
               A large unaccounted_ms means PHP work or untracked I/O (files, image processing, external SDKs).
               `batch.queries` shows the slowest queries and repeated ones (kind "n+1" or "identical").
            4. n_plus_one(path=...) or n_plus_one(name=...) checks many requests or job runs for N+1 queries at once.
            5. Compare a fast and a slow occurrence of the same endpoint to see what differs.
            6. Read the app code at the reported locations before concluding.

            Report: where the time goes with numbers, the cause with file:line, and concrete fixes ranked by impact.
            TEXT);
    }
}
