<?php

declare(strict_types=1);

namespace USDebt\Service;

/**
 * Output helpers for the view templates.
 */
final class Html
{
    /** Escape text for HTML content and attribute values. */
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Encode a value as JSON that is safe to place inside a <script> block.
     *
     * @throws \JsonException
     */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }

    /** "$1,234.56" or "-$1,234.56". */
    public static function money(float $amount): string
    {
        return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2);
    }
}
