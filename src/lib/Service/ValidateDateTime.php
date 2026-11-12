<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 7:39 PM
 */

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;

class ValidateDateTime
{
    /**
     * Strict Y-m-d check: the date must exist (no 2020-02-30) and match the format exactly.
     */
    public static function isValid(string $input): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        return $date !== false && $date->format('Y-m-d') === $input;
    }
}
