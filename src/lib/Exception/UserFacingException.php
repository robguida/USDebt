<?php

declare(strict_types=1);

namespace USDebt\Exception;

use RuntimeException;

/**
 * An error whose message is written by us and is safe to show to a visitor.
 * The message is still HTML-escaped when it is rendered.
 * Every other Throwable is logged and replaced by a generic message.
 */
class UserFacingException extends RuntimeException
{
    /** The visitor sent something we cannot use (bad date, too few picks). */
    public function httpStatus(): int
    {
        return 400;
    }
}
