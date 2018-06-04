<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 7:39 PM
 */

namespace USDebt\Lib;

use DateTime;
use InvalidArgumentException;

class ValidateDateTime
{
    /**
     * @param $input
     * @param bool $suppress
     * @return bool
     */
    public static function validate($input, $suppress = false)
    {
        DateTime::createFromFormat('Y-m-d', $input);
        if ($date_errors = DateTime::getLastErrors()) {
            if (!$suppress && !empty($date_errors['errors'])) {
                throw new InvalidArgumentException(print_r($date_errors['errors'], true));
            } else {
                $output = empty($date_errors['errors']);
            }
        } else {
            $output = true;
        }
        return $output;
    }
}