<?php

namespace LloydsDigital\TelescopeMcp\Support;

use Carbon\CarbonImmutable;

/**
 * A UTC time window. Telescope stores created_at in the app timezone, so
 * conversion happens only at the database boundary (see Entries).
 */
final class Window
{
    public const MAX_MINUTES = 60 * 24 * 90;

    public function __construct(
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {
    }

    /**
     * Builds a window from `from`/`to`/`minutes`. `minutes` counts back from
     * `to` (or now). Returns an unbounded window when nothing is given and
     * there is no default.
     */
    public static function fromInput(Input $input, ?int $defaultMinutes = null): self
    {
        $from = $input->time('from');
        $to = $input->time('to');
        $minutes = $input->int('minutes', null, 1, self::MAX_MINUTES);

        if ($from && $to && $from->greaterThan($to)) {
            throw new InvalidInput('[from] must be before [to].');
        }

        if ($from && $minutes) {
            throw new InvalidInput('Use either [from] or [minutes], not both.');
        }

        if (! $from && ($minutes ?? $defaultMinutes)) {
            $from = ($to ?? CarbonImmutable::now('UTC'))->subMinutes($minutes ?? $defaultMinutes);
        }

        return new self($from, $to);
    }

    public function isBounded(): bool
    {
        return $this->from !== null;
    }

    public function hours(): float
    {
        if (! $this->from) {
            return INF;
        }

        return $this->from->diffInSeconds($this->to ?? CarbonImmutable::now('UTC'), true) / 3600;
    }

    /**
     * The newest $hours of this window (all of it when shorter).
     */
    public function newest(float $hours): self
    {
        $to = $this->to ?? CarbonImmutable::now('UTC');
        $from = $to->subSeconds((int) round($hours * 3600));

        return new self($this->from && $this->from->greaterThan($from) ? $this->from : $from, $to);
    }

    /**
     * The window of the same length just before this one.
     */
    public function previous(): ?self
    {
        if (! $this->from) {
            return null;
        }

        $to = $this->to ?? CarbonImmutable::now('UTC');

        return new self($this->from->subSeconds($this->from->diffInSeconds($to, true)), $this->from);
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toIso8601ZuluString(),
            'to' => ($this->to ?? ($this->from ? CarbonImmutable::now('UTC') : null))?->toIso8601ZuluString(),
        ];
    }
}
