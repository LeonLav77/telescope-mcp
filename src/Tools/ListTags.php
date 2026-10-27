<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListTags extends TelescopeTool
{
    protected string $name = 'list_tags';

    protected string $title = 'List Telescope tags';

    protected string $description = <<<'TEXT'
        Tags in use in a window (default: last 24h), most used first, to discover what the `tag` filter of
        list_entries/stats can match. Telescope tags authenticated users ("Auth:<id>"), Eloquent models touched
        ("App\Models\Order:<key>"), failed jobs ("failed") and slow queries ("slow"); apps add their own.
        `prefixes` summarizes tags by the part before ":" (e.g. how many distinct users), `tags` lists exact tags.
        Use prefix="App\Models\Order:" to find the tags of one model, e.g. to follow one record's history.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Window start, ISO 8601. UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Default 1440 (24h).'),
            'type' => $schema->string()->enum(Entries::TYPES)->description('Only tags of entries of this type.'),
            'prefix' => $schema->string()->max(255)->description('Only tags starting with this, e.g. "Auth:" or "App\\Models\\Order:".'),
            'limit' => $schema->integer()->min(1)->max(200)->default(50),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $window = Window::fromInput($input, 1440);
        $type = $input->type('type', false);
        $prefix = $input->string('prefix', 255);
        $limit = $input->int('limit', 50, 1, 200);

        $result = $entries->tagCounts($window, $type, $prefix, (int) config('telescope-mcp.scan_limit', 5000));
        $prefixes = [];

        foreach ($result['tags'] as $tag => $count) {
            $name = str_contains($tag, ':') ? substr($tag, 0, strrpos($tag, ':') + 1).'*' : $tag;
            $prefixes[$name] ??= ['prefix' => $name, 'distinct' => 0, 'entries' => 0];
            $prefixes[$name]['distinct']++;
            $prefixes[$name]['entries'] += $count;
        }

        usort($prefixes, fn ($a, $b) => $b['entries'] <=> $a['entries']);
        $tags = array_slice($result['tags'], 0, $limit, true);

        return array_filter([
            'window' => $window->toArray(),
            'type' => $type,
            'prefix' => $prefix,
            'coverage' => $result['capped']
                ? "Tags of the newest {$result['scanned']} entries (back to {$result['oldest']}); narrow the window or pass type for more."
                : "All {$result['scanned']} entries of the window.",
            'prefixes' => array_slice($prefixes, 0, 30),
            'tags' => array_map(fn ($tag, $count) => ['tag' => (string) $tag, 'count' => $count], array_keys($tags), $tags),
            'next' => 'list_entries(type, tag="...") or stats(type, tag="...") with an exact tag.',
        ], fn ($value) => $value !== null);
    }
}
