<?php

declare(strict_types=1);

namespace USDebt\Model;

/**
 * How the debt is shown ("Your Share"): the total, per person, or per household.
 *
 * From the URL as ?per=person or ?per=household. Anything else (missing,
 * empty, an array, an unknown word) is the total, which is never written in
 * a link: the total view's links stay exactly as they were before this mode.
 *
 * Per person and per household values are plain dollars (for example
 * $118,672.45). Total values on the charts are in trillions of dollars.
 */
enum Per: string
{
    case Total = 'total';
    case Person = 'person';
    case Household = 'household';

    /** Chart unit for the total view: values are in trillions of dollars. */
    public const UNIT_TRILLION_USD = 'trillion_usd';
    /** Chart unit for per person and per household: values are in dollars. */
    public const UNIT_USD = 'usd';

    /** The mode from request input. Garbage gives the total, never an error. */
    public static function fromInput(mixed $value): self
    {
        if (!is_string($value)) {
            return self::Total;
        }
        return self::tryFrom(strtolower(trim($value))) ?? self::Total;
    }

    public function isTotal(): bool
    {
        return $this === self::Total;
    }

    /** "per=person" for a link, or '' for the total (the default is never written). */
    public function query(): string
    {
        return $this->isTotal() ? '' : 'per=' . $this->value;
    }

    /** Chart unit name sent to the page script: "trillion_usd" or "usd". */
    public function unit(): string
    {
        return $this->isTotal() ? self::UNIT_TRILLION_USD : self::UNIT_USD;
    }

    /** Dollars in one chart unit: 1e12 for the total (trillions), 1 per person or household. */
    public function unitDollars(): float
    {
        return $this->isTotal() ? 1_000_000_000_000.0 : 1.0;
    }
}
