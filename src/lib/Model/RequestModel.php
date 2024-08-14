<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:55 PM
 */

namespace USDebt\Model;

use DateTime;
use Exception;
use InvalidArgumentException;
use USDebt\Service\ValidateDateTime;

/**
 * This is basically a Data Transfer Object
 */
class RequestModel
{
    /** @var DateTime */
    private DateTime $startDate;

    /** @var DateTime */
    private DateTime $endDate;

    /** @var array */
    private array $compare_pres;

    /**
     * @param int $input
     * @return RequestModel
     */
    public function addComparePres(int $input): static
    {
        $this->compare_pres[] = $input;
        return $this;
    }

    /**
     * @param array $input
     * @return RequestModel
     */
    public function setComparePres(array $input): static
    {
        if (1 >= count($input)) {
            throw new InvalidArgumentException("You must select at least 2 presidents");
        }
        $this->compare_pres = $input;
        return $this;
    }

    /**
     * @return array
     */
    public function getComparePres(): array
    {
        return $this->compare_pres;
    }

    /**
     * @param bool $ref
     * @return DateTime
     */
    public function getStartDate(bool $ref = false): DateTime
    {
        return ($ref) ? $this->startDate : clone $this->startDate;
    }

    /**
     * @param DateTime|string $start_date
     * @return RequestModel
     * @throws Exception
     */
    public function setStartDate(DateTime|string $start_date): static
    {
        if ($start_date instanceof  DateTime) {
            $this->startDate = $start_date;
        } elseif (ValidateDateTime::validate($start_date)) {
            $this->startDate = $start_date;
        }
        return $this;
    }

    /**
     * @param bool $ref
     * @return DateTime
     */
    public function getEndDate(bool $ref = false): DateTime
    {
        return ($ref) ? $this->endDate : clone $this->endDate;
    }

    /**
     * @param DateTime|string $end_date
     * @return RequestModel
     */
    public function setEndDate(DateTime|string $end_date): static
    {
        if ($end_date instanceof  DateTime) {
            $this->endDate = $end_date;
        } elseif (ValidateDateTime::validate($end_date)) {
            $this->endDate = $end_date;
        }
        return $this;
    }
}
