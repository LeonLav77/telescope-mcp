<?php

namespace LloydsDigital\TelescopeMcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class InvestigateUser extends Prompt
{
    protected string $name = 'investigate_user';

    protected string $title = 'Investigate what a user did';

    protected string $description = 'Reconstruct what a user did and what they ran into, e.g. for a support ticket.';

    public function arguments(): array
    {
        return [
            new Argument('user', 'The user\'s id, email or name.', required: true),
            new Argument('when', 'Roughly when, e.g. "yesterday afternoon" or an ISO time. Empty: the last 24 hours.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = trim((string) $request->get('user', ''));
        $when = trim((string) $request->get('when', '')) ?: 'the last 24 hours';

        return Response::text(<<<TEXT
            Find out what the user "{$user}" did during {$when}, and whether anything went wrong for them.

            Use the Telescope MCP tools (all times are UTC; call overview first if you need the current time):
            1. user_activity(user="{$user}") with from/to or minutes for that period. If it returns candidates, pick
               the right one and call again with their id.
            2. Read `actions` (their writes, failed requests and requests that changed data) and `exceptions`.
               `activity` shows when they were active; `routes` what they used and how fast it was.
            3. get_entry(id) on the actions or exceptions that matter: app_frames for errors, the batch digest for
               what else happened in that request.
            4. For a record they changed, record_history(model, key) shows its whole history and who else touched it.

            Report a short timeline of what they did, anything that failed or was slow for them (with causes and
            file:line where known), and what they changed.
            TEXT);
    }
}
