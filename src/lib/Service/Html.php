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

    /**
     * Short money for headline numbers: "$38.12 trillion", "$6.04 billion".
     * Pass $signed = true to get "+$1.20 trillion" for a rise.
     */
    public static function compactMoney(float $amount, bool $signed = false): string
    {
        $sign = $amount < 0 ? '-' : ($signed && $amount > 0 ? '+' : '');
        $abs = abs($amount);
        foreach ([1_000_000_000_000 => 'trillion', 1_000_000_000 => 'billion', 1_000_000 => 'million'] as $unit => $word) {
            if ($abs >= $unit) {
                return $sign . '$' . number_format($abs / $unit, 2) . ' ' . $word;
            }
        }
        return $sign . '$' . number_format($abs, 2);
    }

    /**
     * Signed percent with one decimal: "+8.1%", "-2.3%". A value that rounds
     * to zero is "0.0%", never "+0.0%" or "-0.0%".
     */
    public static function signedPercent(float $percent): string
    {
        $rounded = round($percent, 1);
        if ($rounded == 0.0) {
            return '0.0%';
        }
        return ($rounded > 0 ? '+' : '-') . number_format(abs($rounded), 1) . '%';
    }

    /** "Nov 9, 2026" from "2026-11-09". */
    public static function shortDate(string $isoDate): string
    {
        return (new \DateTimeImmutable($isoDate))->format('M j, Y');
    }
}
