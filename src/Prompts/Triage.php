<?php

namespace LloydsDigital\TelescopeMcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class Triage extends Prompt
{
    protected string $name = 'triage';

    protected string $title = 'Triage recent problems';

    protected string $description = 'Summarize the health of the app over a period: errors, failures, slow spots and noise, ranked by impact.';

    public function arguments(): array
    {
        return [
            new Argument('hours', 'How far back to look, in hours. Default 24.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $hours = max(1, min(2160, (int) ($request->get('hours') ?: 24)));
        $minutes = $hours * 60;

        return Response::text(<<<TEXT
            Triage the last {$hours} hours of this app.

            Use the Telescope MCP tools, all with minutes={$minutes}:
            1. overview: retention, exception groups, 5xx requests, failed jobs, slow queries.
            2. stats(type="exception", sort="count") and stats(type="job", sort="errors"), with compare_previous=true
               to spot what is new.
            3. stats(type="request", sort="errors") for failing endpoints and stats(type="request") for where time goes.
            4. stats(type="schedule") and stats(type="command", sort="errors") for background work.
            5. n_plus_one for the N+1 queries costing the most time.
            6. stats(type="request", group_by="user", sort="errors") for users running into errors.
            7. For the top 3 problems, get_entry on a sample and read its app_frames and batch digest.

            Report a short ranked list: problem, impact (count, users or endpoints affected), whether it is new,
            likely cause with file:line, and suggested fix. Mention noise worth silencing separately.
            TEXT);
    }
}
