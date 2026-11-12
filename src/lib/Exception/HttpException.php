<?php

declare(strict_types=1);

namespace USDebt\Exception;

use RuntimeException;

/**
 * A remote HTTP request failed (network error, timeout or non-2xx status).
 */
final class HttpException extends RuntimeException
{
}
