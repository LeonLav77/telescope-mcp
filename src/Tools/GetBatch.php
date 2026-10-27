<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\Present;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetBatch extends TelescopeTool
{
    protected string $name = 'get_batch';

    protected string $title = 'Get Telescope batch';

    protected string $description = <<<'TEXT'
        Everything recorded during one request, job run, command or scheduler run (a batch), in the order it
        happened, one line each with ids. Use it when the digest in get_entry isn't enough, e.g. to read every
        query of a request in order (type="query"). Page with cursor=next_cursor.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'batch_id' => $schema->string()->description('Batch id (UUID), from get_entry or list_entries.')->required(),
            'type' => $schema->string()->enum(Entries::TYPES)->description('Only entries of this type.'),
            'limit' => $schema->integer()->min(1)->max(200)->default(100),
            'cursor' => $schema->integer()->description('next_cursor from a previous call.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $batchId = $input->uuid('batch_id');
        $type = $input->type('type', false);
        $limit = $input->int('limit', 100, 1, 200);
        $cursor = $input->int('cursor', null, 1, PHP_INT_MAX);

        $rows = $entries->batch($batchId, $type)
            ->when($cursor, fn ($q) => $q->where('sequence', '>', $cursor))
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();

        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return array_filter([
            'batch_id' => $batchId,
            'type' => $type,
            'counts' => $cursor ? null : $entries->countsByType($entries->batch($batchId)),
            'entries' => $rows->map(function ($row) {
                $item = Present::row($row);
                unset($item['batch_id']);

                return $item;
            })->values()->all(),
            'next_cursor' => $more ? (int) $rows->last()->sequence : null,
        ], fn ($value) => $value !== null);
    }
}
