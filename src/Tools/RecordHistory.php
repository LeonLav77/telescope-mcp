<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use LloydsDigital\TelescopeMcp\Support\Budget;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\InvalidInput;
use LloydsDigital\TelescopeMcp\Support\Present;
use LloydsDigital\TelescopeMcp\Support\Redact;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class RecordHistory extends TelescopeTool
{
    protected string $name = 'record_history';

    protected string $title = 'Record history';

    protected string $description = <<<'TEXT'
        The recorded history of one Eloquent record, oldest first: when it was created, updated and deleted, which
        fields each change set (new values; Telescope does not store old ones), who did it (the logged-in user) and
        through what (the request, command or job), plus jobs, mail and notifications that carried the record.
        Answers "who changed order 123 and when?". `model` is the class ("App\Models\Order", or "Order" for App\Models),
        `key` its primary key. Covers all retained data unless a window is given.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->max(255)->description('Model class, e.g. "App\\Models\\Order" or "Order".')->required(),
            'key' => $schema->string()->max(100)->description('Primary key of the record.')->required(),
            'from' => $schema->string()->description('Window start, ISO 8601. Default: all retained data.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(50),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $model = ltrim($input->string('model', 255) ?? throw new InvalidInput('[model] is required.'), '\\');
        $key = $input->string('key', 100) ?? throw new InvalidInput('[key] is required.');
        $limit = $input->int('limit', 50, 1, 100);
        $window = new Window($input->time('from'), $input->time('to'));

        $class = str_contains($model, '\\') ? $model : 'App\\Models\\'.$model;
        $tag = "{$class}:{$key}";

        if (! $entries->tagExists($tag)) {
            $similar = $entries->tagsStartingWith($class.':', 3);

            throw new InvalidInput("Nothing is tagged \"{$tag}\" in retained data."
                .($similar ? ' Tags of that class look like: '.implode(', ', $similar).'.' : " No tags start with \"{$class}:\"; check the class name with list_tags(prefix=\"App\\\\Models\\\\\")."));
        }

        $query = $entries->query()->where('type', '!=', 'query');

        if (! $entries->within($query, $window)) {
            return ['record' => $tag, 'window' => $window->toArray(), 'history' => []];
        }

        $entries->tagged($query, $tag);
        $rows = $query->orderBy('sequence')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $origins = $entries->originsOf($rows->pluck('batch_id')->all());

        // Without a request or command, the batch is usually a queued job's run.
        foreach ($rows->pluck('batch_id')->unique()->diff(array_keys($origins))->take(10) as $batchId) {
            if ($job = $entries->jobExecutedIn($batchId)) {
                $origins[$batchId] = $job;
            }
        }

        $history = $rows->map(function ($row) use ($origins) {
            $c = Present::content($row);
            $origin = $origins[$row->batch_id] ?? null;
            $by = Present::user($c) ?? ($origin ? Present::user(Present::content($origin)) : null);

            return array_filter([
                'at' => Present::time($row->created_at),
                'what' => $row->type === 'model'
                    ? trim(($c['action'] ?? '?').' '.class_basename(explode(':', (string) ($c['model'] ?? ''))[0]))
                    : Present::summary($row->type, $c),
                'set' => $row->type === 'model' && is_array($c['changes'] ?? null)
                    ? Budget::limit(Redact::content('model', array_diff_key($c['changes'], ['updated_at' => true])), 200, 20)
                    : null,
                'by' => $by,
                'via' => $origin
                    ? ($origin->type === 'job' ? 'job run: ' : '').Present::summary($origin->type, Present::content($origin), withUser: false)
                    : 'unknown (no request, command or job recorded for its batch)',
                'id' => $row->uuid,
                'batch_id' => $row->batch_id,
            ], fn ($value) => $value !== null && $value !== []);
        })->values()->all();

        return array_filter([
            'record' => $tag,
            'window' => $window->from || $window->to ? $window->toArray() : null,
            'history' => $history,
            'note' => $more ? "Showing the first {$limit} entries; pass from= the last one's time for later history." : null,
            'next' => 'get_entry(id) for everything that happened in the same request or job.',
        ], fn ($value) => $value !== null);
    }
}
