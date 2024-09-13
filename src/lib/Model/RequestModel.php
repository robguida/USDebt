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
    /** @var string */
    private string $startDate;

    /** @var string */
    private string $endDate;

    /** @var array */
    private array $compare_pres;

    public function __construct()
    {
        $this->startDate = '';
        $this->endDate = '';
        $this->compare_pres = [];
    }

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
     * @param bool $return_datetime
     * @return DateTime|string
     */
    public function getStartDate(bool $return_datetime = false): DateTime|string
    {
        try {
            $output = ($return_datetime) ? new DateTime($this->startDate) : $this->startDate;
        } catch (Exception $e) {
            $output = $this->startDate;
        }
        return $output;
    }

    /**
     * @param DateTime|string $start_date
     * @return RequestModel
     * @throws Exception
     */
    public function setStartDate(DateTime|string $start_date): static
    {
        if ($start_date instanceof  DateTime) {
            $this->startDate = $start_date->format('Y-m-d');
        } elseif (ValidateDateTime::validate($start_date)) {
            $this->startDate = $start_date;
        }
        return $this;
    }

    /**
     * @param bool $return_datetime
     * @return DateTime|string
     */
    public function getEndDate(bool $return_datetime = false): DateTime|string
    {
        try {
            $output = ($return_datetime) ? new DateTime($this->endDate) : $this->endDate;
        } catch (Exception $e) {
            $output = $this->endDate;
        }
        return $output;
    }

    /**
     * @param DateTime|string $end_date
     * @return RequestModel
     */
    public function setEndDate(DateTime|string $end_date): static
    {
        if ($end_date instanceof  DateTime) {
            $this->endDate = $end_date->format('Y-m-d');
        } elseif (ValidateDateTime::validate($end_date)) {
            $this->endDate = $end_date;
        }
        return $this;
    }
}
