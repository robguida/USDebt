<?php

declare(strict_types=1);

namespace USDebt\Exception;

use RuntimeException;

/**
 * The share card cannot be drawn on this server: the PHP gd extension (with
 * FreeType) is missing, or a card font file is missing. Answered with 503.
 * The message is for the log only.
 */
final class CardUnavailableException extends RuntimeException
{
}
