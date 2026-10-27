<?php

namespace LloydsDigital\TelescopeMcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class InvestigateError extends Prompt
{
    protected string $name = 'investigate_error';

    protected string $title = 'Investigate an error';

    protected string $description = 'Find the root cause of an error, failed job or failing endpoint using Telescope data.';

    public function arguments(): array
    {
        return [
            new Argument('what', 'The error message, entry id, URL, job class or time it happened. Empty: the most recent problems.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $what = trim((string) $request->get('what', ''));
        $target = $what === '' ? 'the most recent problems' : "this: {$what}";

        return Response::text(<<<TEXT
            Investigate {$target}

            Use the Telescope MCP tools:
            1. If you have an entry id, get_entry(id). Otherwise call overview (widen the window with minutes if needed),
               or search(text) for a message, URL or class, and open the most relevant entry with get_entry.
            2. For exceptions and failed jobs read `app_frames` first: they point at the app code involved. Ask for
               include=["trace"] only if the app frames don't explain it.
            3. Use the `batch` digest to see what led up to it: the request or job it happened in, its queries,
               logs, outgoing HTTP calls and other problems. For a scheduler run, check failed scheduled_tasks.
            4. Check how often it happens: exception_family on the exception, or stats(type="exception") /
               stats(type="job") over a longer window, with compare_previous=true to see if it is new.
            5. Read the app code at the reported files and lines before concluding.

            Report: what happened, the root cause with file:line, since when and how often, and a concrete fix.
            TEXT);
    }
}
