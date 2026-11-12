<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:55 PM
 */

declare(strict_types=1);

namespace USDebt\Model;

use DateTimeImmutable;
use USDebt\Exception\UserFacingException;
use USDebt\Service\ValidateDateTime;

class RequestModel
{
    private ?DateTimeImmutable $startDate = null;

    private ?DateTimeImmutable $endDate = null;

    /** @var list<int> */
    private array $comparePres = [];

    /** Trend line on the charts. Off only when the URL says trend=0. */
    private bool $showTrend = true;

    public function setShowTrend(bool $show): void
    {
        $this->showTrend = $show;
    }

    public function showTrend(): bool
    {
        return $this->showTrend;
    }

    /**
     * @param list<int> $keys  Validated term ids (no cap on how many).
     */
    public function setComparePres(array $keys): void
    {
        $this->comparePres = array_values($keys);
        if (count($this->comparePres) < 2) {
            throw new UserFacingException('Please pick at least 2 presidents to compare.');
        }
    }

    /** @return list<int> */
    public function getComparePres(): array
    {
        return $this->comparePres;
    }

    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeImmutable|string $startDate): void
    {
        $this->startDate = self::toDate($startDate, 'Start date');
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(DateTimeImmutable|string $endDate): void
    {
        $this->endDate = self::toDate($endDate, 'End date');
    }

    private static function toDate(DateTimeImmutable|string $value, string $label): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTime(0, 0);
        }
        if (!ValidateDateTime::isValid($value)) {
            throw new UserFacingException("{$label} must be a real date written as YYYY-MM-DD.");
        }
        return new DateTimeImmutable($value);
    }
}
