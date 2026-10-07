<?php

namespace App\Application\Concerns;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * A pair of calendar days a list may be narrowed to, as the client names them.
 *
 * Either side may be missing, which means "open on that side". A day that does not
 * parse as `Y-m-d` is not a day — it is null, because a listing answers with a page and
 * a fallback, never with a 422. The bounds are read in the app timezone, so "today"
 * means the user's today, not UTC's.
 */
final readonly class DateRange
{
    public const FORMAT = 'Y-m-d';

    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}

    /**
     * A reversed pair is swapped rather than left to match nothing — the user picked two
     * days, which two is what they meant.
     */
    public static function fromInput(string $from, string $to): self
    {
        $start = self::parseDay($from);
        $end = self::parseDay($to);

        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start, $end);
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * The keys here are what the frontend reads back as the range in force.
     *
     * @return array{created_from: string|null, created_to: string|null}
     */
    public function toFilters(): array
    {
        return [
            'created_from' => $this->from?->format(self::FORMAT),
            'created_to' => $this->to?->format(self::FORMAT),
        ];
    }

    /**
     * `!` resets the unlisted fields to midnight so two parses of one day compare equal.
     * PHP's own parser answers false for a value that does not fit the format at all, where
     * Carbon's would throw, so a bad day never needs a catch (exceptions.md). The round trip
     * guard catches what it would otherwise "fix" (a 32nd day rolls into the next month).
     */
    private static function parseDay(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value);

        if ($day === false || $day->format(self::FORMAT) !== $value) {
            return null;
        }

        return CarbonImmutable::instance($day);
    }
}
