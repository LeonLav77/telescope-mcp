<?php

namespace LloydsDigital\TelescopeMcp\Support;

/**
 * Fits a tool result into the response budget without ever cutting the JSON
 * itself: long strings and arrays inside the data are shortened, step by
 * step, until the encoded result fits.
 *
 * Top-level lists (a page of entries, a list of groups) are never shortened,
 * so cursors stay correct; only what is nested inside them is.
 */
final class Budget
{
    /** Each step: [max string length, max array items]. */
    private const STEPS = [[4000, 25], [1000, 10], [300, 5], [120, 3]];

    /** Arrays shallower than this are the result's own structure and keep every item. */
    private const PROTECTED_DEPTH = 2;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data, int $maxChars, int $maxString, int $maxItems): string
    {
        $json = self::json(self::limit($data, $maxString, $maxItems));

        if (mb_strlen($json) <= $maxChars) {
            return $json;
        }

        foreach (self::STEPS as [$strings, $items]) {
            $shortened = self::limit($data, min($strings, $maxString), min($items, $maxItems));
            $shortened = ['_shortened' => "Long values were shortened to fit the {$maxChars}-character response limit (strings to {$strings} chars, nested lists to {$items} items). Open single entries or narrow the request for full detail."] + $shortened;
            $json = self::json($shortened);

            if (mb_strlen($json) <= $maxChars) {
                return $json;
            }
        }

        return self::json([
            'error' => "The result is too large to return even after shortening (limit {$maxChars} characters). Narrow the request: a smaller limit, a shorter window, or a type filter.",
            'keys' => array_keys($data),
        ]);
    }

    /**
     * Shortens strings longer than $maxString and arrays longer than $maxItems (below the protected depth).
     */
    public static function limit(mixed $value, int $maxString, int $maxItems, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $maxString
                ? mb_substr($value, 0, $maxString).'… [truncated, '.mb_strlen($value).' chars total]'
                : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $more = 0;

        if ($depth >= self::PROTECTED_DEPTH && count($value) > $maxItems) {
            $more = count($value) - $maxItems;
            $value = array_slice($value, 0, $maxItems, true);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::limit($item, $maxString, $maxItems, $depth + 1);
        }

        if ($more > 0 && array_is_list($value)) {
            $value[] = "… +{$more} more";
        } elseif ($more > 0) {
            $value['…'] = "+{$more} more keys";
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
