<?php

namespace LloydsDigital\TelescopeMcp\Support;

use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Throwable;

/**
 * Strict parsing of tool arguments. Anything unexpected is rejected, never coerced into SQL.
 */
final class Input
{
    public function __construct(private Request $request)
    {
    }

    /**
     * The raw arguments, e.g. to build the arguments of a follow-up call.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->request->all();
    }

    public function type(string $key = 'type', bool $required = true): ?string
    {
        $value = $this->string($key, 20);

        if ($value === null) {
            if ($required) {
                throw new InvalidInput("[{$key}] is required. One of: ".implode(', ', Entries::TYPES));
            }

            return null;
        }

        if (! in_array($value, Entries::TYPES, true)) {
            throw new InvalidInput("[{$key}] must be one of: ".implode(', ', Entries::TYPES));
        }

        return $value;
    }

    public function uuid(string $key, bool $required = true): ?string
    {
        $value = $this->string($key, 36);

        if ($value === null) {
            if ($required) {
                throw new InvalidInput("[{$key}] is required.");
            }

            return null;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidInput("[{$key}] must be a UUID.");
        }

        return strtolower($value);
    }

    public function int(string $key, ?int $default, int $min, int $max): ?int
    {
        $value = $this->request->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) && ! (is_float($value) && floor($value) === $value)) {
            throw new InvalidInput("[{$key}] must be an integer.");
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            throw new InvalidInput("[{$key}] must be between {$min} and {$max}.");
        }

        return $value;
    }

    public function float(string $key, float $min, float $max): ?float
    {
        $value = $this->request->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidInput("[{$key}] must be a number.");
        }

        $value = (float) $value;

        if ($value < $min || $value > $max) {
            throw new InvalidInput("[{$key}] must be between {$min} and {$max}.");
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->request->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($bool === null) {
            throw new InvalidInput("[{$key}] must be a boolean.");
        }

        return $bool;
    }

    public function string(string $key, int $maxLength): ?string
    {
        $value = $this->request->get($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidInput("[{$key}] must be a string.");
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidInput("[{$key}] must be at most {$maxLength} characters.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function enum(string $key, array $allowed): ?string
    {
        $value = $this->string($key, 50);

        if ($value !== null && ! in_array($value, $allowed, true)) {
            throw new InvalidInput("[{$key}] must be one of: ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * A list of allowed values, given as an array or a comma-separated string.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function list(string $key, array $allowed): array
    {
        $value = $this->request->get($key);

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            throw new InvalidInput("[{$key}] must be a list.");
        }

        $values = array_values(array_unique(array_map(fn ($item) => is_string($item) ? strtolower(trim($item)) : '', $value)));

        foreach ($values as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new InvalidInput("[{$key}] items must be one of: ".implode(', ', $allowed));
            }
        }

        return $values;
    }

    public function time(string $key): ?CarbonImmutable
    {
        $value = $this->string($key, 64);

        if ($value === null) {
            return null;
        }

        try {
            // Inputs without an offset are taken as UTC.
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw new InvalidInput("[{$key}] must be an ISO 8601 date/time, e.g. 2026-10-14T09:00:00Z.");
        }
    }
}
