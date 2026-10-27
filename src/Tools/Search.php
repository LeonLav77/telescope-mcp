<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\InvalidInput;
use LloydsDigital\TelescopeMcp\Support\Present;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Search extends TelescopeTool
{
    protected string $name = 'search';

    protected string $title = 'Search Telescope entries';

    /** High-volume types searched only with all_types or an explicit type: ~75% of stored bytes. */
    public const BULK_TYPES = ['query', 'view', 'redis', 'cache'];

    protected string $description = <<<'TEXT'
        Find entries whose content mentions some text: an order number, email, URL, job class, error message...
        Case-insensitive, newest first, one line each with ids for get_entry. Searches every type except the bulky
        query, view, redis and cache entries (pass all_types=true, or type="query" for SQL); any type it skipped is
        named in the result. Walks back from `to` (default now) towards `from` (default: all retained data) for
        about 15 seconds per call; when more remains, `continue_with` holds the exact arguments for the next call.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->max(200)->description('Text to find in entry content.')->required(),
            'type' => $schema->string()->enum(Entries::TYPES)->description('Only entries of this type.'),
            'all_types' => $schema->boolean()->description('Also search query, view, redis and cache entries (slower).'),
            'from' => $schema->string()->description('Oldest point to search back to, ISO 8601. Default: all retained data.'),
            'to' => $schema->string()->description('Search back from here, ISO 8601. Defaults to now.'),
            'exclude' => $schema->string()->max(200)->description('Leave out entries whose content contains this text.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'cursor' => $schema->integer()->description('From continue_with.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $text = $input->string('text', 200) ?? throw new InvalidInput('[text] is required.');
        $type = $input->type('type', false);
        $allTypes = $input->bool('all_types', false);
        $limit = $input->int('limit', 25, 1, 100);
        $cursor = $input->int('cursor', null, 1, PHP_INT_MAX);
        $exclude = $input->string('exclude', 200);
        $window = new Window($input->time('from'), $input->time('to'));

        $query = match (true) {
            $type !== null => $entries->typed($type),
            $allTypes => $entries->query(),
            default => $entries->query()->whereNotIn('type', self::BULK_TYPES),
        };

        $result = ['rows' => [], 'next_cursor' => null, 'scan_capped' => false];

        if ($entries->within($query, $window)) {
            $entries->contains($query, $text);

            if ($exclude) {
                $entries->notContains($query, $exclude);
            }

            $result = $entries->scan($query, $limit, $cursor);
        }

        $more = $result['next_cursor'] !== null;

        return array_filter([
            'text' => $text,
            'skipped_types' => $type === null && ! $allTypes ? self::BULK_TYPES : null,
            'searched' => [
                'from' => $more ? $entries->timeAt($result['next_cursor']) : ($window->from?->toIso8601ZuluString() ?? $entries->retention()['oldest'] ?? null),
                'to' => ($window->to ?? CarbonImmutable::now('UTC'))->toIso8601ZuluString(),
            ],
            'count' => count($result['rows']),
            'entries' => array_map(fn ($row) => Present::row($row), $result['rows']),
            'continue_with' => $more ? ListEntries::arguments($input, ['cursor' => $result['next_cursor']]) : null,
            'note' => $more
                ? ($result['scan_capped'] ? 'Stopped at the time limit; continue_with searches further back.' : 'More matches; continue_with gets the next page.')
                : 'Searched to the end of the window.',
        ], fn ($value) => $value !== null);
    }
}
