<?php

declare(strict_types=1);

namespace USDebt\Exception;

use Throwable;

/**
 * The Treasury data could not be downloaded and no saved copy exists.
 */
final class DataUnavailableException extends UserFacingException
{
    public const MESSAGE = 'The U.S. Treasury data is not available right now. Please try again in a few minutes.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
