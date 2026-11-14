<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\SiteConfig;

/**
 * The Table tab as a CSV file (download.php).
 *
 * - Header: "Date" and the amount column ("Total debt (USD)", "Debt per person
 *   (USD)" or "Debt per household (USD)"). Dates are YYYY-MM-DD. Amounts are
 *   plain numbers with 2 decimals (Treasury totals are whole cents): no $, no
 *   commas, so a spreadsheet reads them as numbers.
 * - Rows in the order given (the page passes newest first, like the Table).
 * - CRLF line ends and RFC 4180 quoting (fputcsv).
 * - Formula-injection guard: a text cell that starts with = + - @ TAB or CR gets
 *   a leading apostrophe. Amounts are never negative; if one ever were (or was
 *   not a number) it is written as guarded text instead of a bare "-5.00", and
 *   the problem is logged. One bad row never stops the rest.
 */
final class TableCsv
{
    private const GUARDED_FIRST = ['=', '+', '-', '@', "\t", "\r"];

    /** The amount column title for a mode. */
    public static function amountHeader(Per $per): string
    {
        return match ($per) {
            Per::Total => 'Total debt (USD)',
            Per::Person => 'Debt per person (USD)',
            Per::Household => 'Debt per household (USD)',
        };
    }

    /**
     * "our-us-debt_2026-10-01_to_2026-11-01[_per-person|_per-household].csv".
     * Only validated dates go in, so the name is always safe.
     */
    public static function fileName(string $start, string $end, Per $per): string
    {
        return SiteConfig::FILE_SLUG . '_' . $start . '_to_' . $end
            . ($per->isTotal() ? '' : '_per-' . $per->value) . '.csv';
    }

    /**
     * @param iterable<DebtEntry> $entries  Already scaled for $per, in display order.
     */
    public static function build(iterable $entries, Per $per): string
    {
        $out = fopen('php://temp/maxmemory:4194304', 'w+b');
        if ($out === false) {
            throw new \RuntimeException('Could not open the CSV buffer.');
        }
        self::row($out, ['Date', self::amountHeader($per)]);
        foreach ($entries as $entry) {
            try {
                self::row($out, [self::text($entry->effectiveDate), self::amount($entry->totalDebt)]);
            } catch (\Throwable $e) {
                error_log('UsDebt: CSV row skipped: ' . $e->getMessage());
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        if ($csv === false) {
            throw new \RuntimeException('Could not read the CSV buffer.');
        }
        return $csv;
    }


    /** A text cell, with the formula guard. */
    public static function text(string $value): string
    {
        return $value !== '' && in_array($value[0], self::GUARDED_FIRST, true) ? "'" . $value : $value;
    }

    private static function amount(float $value): string
    {
        if (!is_finite($value) || $value < 0) {
            error_log('UsDebt: CSV amount is not a positive number; written as guarded text.');
            return self::text(is_finite($value) ? number_format($value, 2, '.', '') : 'n/a');
        }
        return number_format($value, 2, '.', '');
    }

    /**
     * @param resource $out
     * @param list<string> $cells
     */
    private static function row($out, array $cells): void
    {
        if (fputcsv($out, $cells, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Could not write a CSV row.');
        }
    }
}
