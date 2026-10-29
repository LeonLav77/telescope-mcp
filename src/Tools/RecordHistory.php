<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
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
        `key` its primary key. Without the key, `attribute` + `value` find the record by a business field (an order
        number, an email) where the app allows it (config record_lookup); several matching records (e.g. the line
        items of one order) give one merged history, each item naming its record. Covers all retained data unless a
        window is given.
        TEXT;

    /** Records one attribute lookup may cover. */
    private const MAX_RECORDS = 10;

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->max(255)->description('Model class, e.g. "App\\Models\\Order" or "Order".')->required(),
            'key' => $schema->string()->max(100)->description('Primary key of the record.'),
            'attribute' => $schema->string()->max(100)->description('Instead of key: a column to find the record(s) by, e.g. "order_number" (only columns enabled in config record_lookup).'),
            'value' => $schema->string()->max(255)->description('With attribute: the value to match exactly.'),
            'from' => $schema->string()->description('Window start, ISO 8601. Default: all retained data.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(50),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $model = ltrim($input->string('model', 255) ?? throw new InvalidInput('[model] is required.'), '\\');
        $key = $input->string('key', 100);
        $attribute = $input->string('attribute', 100);
        $limit = $input->int('limit', 50, 1, 100);
        $window = new Window($input->time('from'), $input->time('to'));

        $class = str_contains($model, '\\') ? $model : 'App\\Models\\'.$model;

        if ($key !== null) {
            $keys = [$key];
        } elseif ($attribute !== null) {
            $keys = self::lookup($class, $attribute, $input->string('value', 255) ?? throw new InvalidInput('[value] is required with [attribute].'));
        } else {
            throw new InvalidInput('Pass [key], or [attribute] and [value].');
        }

        $tags = array_map(fn ($key) => "{$class}:{$key}", $keys);
        $known = array_values(array_filter($tags, fn ($tag) => $entries->tagExists($tag)));

        if ($known === []) {
            $similar = $entries->tagsStartingWith($class.':', 3);

            throw new InvalidInput('Nothing is tagged "'.implode('", "', $tags).'" in retained data.'
                .($similar ? ' Tags of that class look like: '.implode(', ', $similar).'.' : " No tags start with \"{$class}:\"; check the class name with list_tags(prefix=\"App\\\\Models\\\\\")."));
        }

        $record = count($tags) === 1 ? $tags[0] : $tags;
        $query = $entries->query()->where('type', '!=', 'query');

        if (! $entries->within($query, $window)) {
            return ['record' => $record, 'window' => $window->toArray(), 'history' => []];
        }

        $entries->tagged($query, $known);
        $rows = $query->orderBy('sequence')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $origins = $entries->originsOf($rows->pluck('batch_id')->all());
        $recordsOf = count($known) > 1 ? $entries->tagsAmong($rows->pluck('uuid')->all(), $known) : [];

        // Without a request or command, the batch is usually a queued job's run.
        foreach ($rows->pluck('batch_id')->unique()->diff(array_keys($origins))->take(10) as $batchId) {
            if ($job = $entries->jobExecutedIn($batchId)) {
                $origins[$batchId] = $job;
            }
        }

        $history = $rows->map(function ($row) use ($origins, $recordsOf, $class) {
            $c = Present::content($row);
            $origin = $origins[$row->batch_id] ?? null;
            $by = Present::user($c) ?? ($origin ? Present::user(Present::content($origin)) : null);
            $records = array_map(fn ($tag) => substr($tag, strlen($class) + 1), $recordsOf[$row->uuid] ?? []);

            return array_filter([
                'at' => Present::time($row->created_at),
                'record' => $records === [] ? null : implode(', ', $records),
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
                'via_id' => $origin?->uuid,
                'id' => $row->uuid,
                'url' => Present::link($row->type, $row->uuid),
                'batch_id' => $row->batch_id,
            ], fn ($value) => $value !== null && $value !== []);
        })->values()->all();

        return array_filter([
            'record' => $record,
            'not_in_telescope' => count($known) < count($tags) ? array_values(array_diff($tags, $known)) : null,
            'window' => $window->from || $window->to ? $window->toArray() : null,
            'history' => $history,
            'note' => $more ? "Showing the first {$limit} entries; pass from= the last one's time for later history." : null,
            'next' => 'get_entry(id) for everything that happened in the same request or job.',
        ], fn ($value) => $value !== null);
    }

    /**
     * Primary keys of the records whose $attribute equals $value, read from
     * the app's database. Only attributes enabled in config record_lookup.
     *
     * @return list<string>
     */
    private static function lookup(string $class, string $attribute, string $value): array
    {
        $allowed = [];

        foreach ((array) config('telescope-mcp.record_lookup', []) as $model => $attributes) {
            if (ltrim((string) $model, '\\') === $class) {
                $allowed = array_values((array) $attributes);
            }
        }

        if (! in_array($attribute, $allowed, true)) {
            throw new InvalidInput("Looking up {$class} by [{$attribute}] isn't enabled"
                .($allowed ? '; enabled attributes: '.implode(', ', $allowed).'.' : '. Add it to config telescope-mcp.record_lookup, or find the key with search(text=…, type="job") or type="request".'));
        }

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidInput("{$class} is not an Eloquent model.");
        }

        /** @var Model $model */
        $model = new $class;
        // The base query: keys as stored, without the model's casts or accessors.
        $keys = $model->newQueryWithoutScopes()->toBase()
            ->where($attribute, $value)
            ->orderBy($model->getKeyName())
            ->limit(self::MAX_RECORDS + 1)
            ->pluck($model->getKeyName())
            ->map(fn ($key) => (string) $key)
            ->all();

        if ($keys === []) {
            throw new InvalidInput("No {$class} has {$attribute} = \"{$value}\".");
        }

        if (count($keys) > self::MAX_RECORDS) {
            throw new InvalidInput("More than ".self::MAX_RECORDS." {$class} records have {$attribute} = \"{$value}\"; pass a key instead.");
        }

        return $keys;
    }
}
