<?php

declare(strict_types=1);

namespace USDebt\Model;

/**
 * One day of Treasury data: the total public debt outstanding on that date.
 */
final class DebtEntry
{
    /**
     * @param string $effectiveDate Date in Y-m-d format.
     * @param float  $totalDebt     Total public debt outstanding, in dollars.
     */
    public function __construct(
        public readonly string $effectiveDate,
        public readonly float $totalDebt,
    ) {
    }
}
