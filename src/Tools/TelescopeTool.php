<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LloydsDigital\TelescopeMcp\Support\Budget;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\InvalidInput;
use LloydsDigital\TelescopeMcp\Support\Untrusted;
use Throwable;

abstract class TelescopeTool extends Tool
{
    /** Set by a tool that was asked to show withheld (suspicious) values. */
    protected bool $revealSuspicious = false;

    public function handle(Request $request): Response
    {
        try {
            // Tools only read. The transaction is always rolled back, so nothing
            // a tool call does can ever persist.
            // Tool instances may be reused between calls (e.g. Octane): no state carries over.
            $this->revealSuspicious = false;

            $connection = Entries::connection();
            $connection->beginTransaction();

            try {
                $data = $this->run(new Input($request), new Entries);
            } finally {
                $connection->rollBack();
            }

            return Response::text($this->encode($data));
        } catch (InvalidInput $e) {
            return Response::error(Untrusted::string('Invalid input: '.$e->getMessage()));
        } catch (Throwable $e) {
            // Messages can carry recorded data too (e.g. SQL with its bindings).
            return Response::error(Untrusted::string($this->describe($e)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function run(Input $input, Entries $entries): array;

    /**
     * Compact JSON, cleaned of prompt-injection attempts (see Untrusted),
     * fitted into the response limit by shortening long values (never by
     * cutting the JSON itself).
     *
     * @param  array<string, mixed>  $data
     */
    protected function encode(array $data): string
    {
        return Budget::encode(
            ['_notice' => Untrusted::NOTICE] + Untrusted::clean($data, $this->revealSuspicious),
            (int) config('telescope-mcp.max_response_chars', 60000),
            (int) config('telescope-mcp.max_string_length', 4000),
            (int) config('telescope-mcp.max_array_items', 50),
        );
    }

    /**
     * Full error details: this endpoint is only reachable with the key.
     */
    protected function describe(Throwable $e): string
    {
        $text = '';

        for ($current = $e; $current; $current = $current->getPrevious()) {
            $text .= ($text === '' ? '' : "\nCaused by: ")
                .get_class($current).': '.$current->getMessage()
                .' at '.$current->getFile().':'.$current->getLine();
        }

        $trace = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);

        return $text."\n\nTrace:\n".implode("\n", $trace);
    }
}
