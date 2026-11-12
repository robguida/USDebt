<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Exception\DataUnavailableException;
use USDebt\Model\DebtDataset;

interface DebtSource
{
    /**
     * @throws DataUnavailableException when there is no data to show at all.
     */
    public function load(): DebtDataset;
}
