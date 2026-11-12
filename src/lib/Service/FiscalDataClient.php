<?php

declare(strict_types=1);

namespace USDebt\Service;

use JsonException;
use Throwable;
use UnexpectedValueException;
use USDebt\Exception\IncompleteDownloadException;
use USDebt\Http\HttpFetcher;
use USDebt\Model\DebtEntry;

/**
 * Downloads the full "Debt to the Penny" history from the official Treasury API
 * (fiscaldata.treasury.gov), page by page, and maps it to DebtEntry objects.
 *
 * Rows are requested oldest first, so a row the Treasury adds during the download
 * lands on the last page instead of shifting rows between pages.
 * Every page is fetched even if an earlier one fails; failures are collected and,
 * if there are any, the whole download is rejected (IncompleteDownloadException)
 * so partial data is never saved.
 *
 * Field mapping (API -> app):
 *   record_date          -> DebtEntry::effectiveDate (Y-m-d)
 *   tot_pub_debt_out_amt -> DebtEntry::totalDebt (dollars)
 */
final class FiscalDataClient
{
    public const ENDPOINT = 'https://api.fiscaldata.treasury.gov/services/api/fiscal_service/v2/accounting/od/debt_to_penny';

    /** The API's largest page. */
    public const PAGE_SIZE = 10000;

    /** Safety stop: far more pages than the history needs (about 1 today). */
    public const MAX_PAGES = 20;

    /** Per page. */
    public const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly HttpFetcher $fetcher)
    {
    }

    public static function url(int $page = 1): string
    {
        return self::ENDPOINT . '?' . http_build_query([
            'fields' => 'record_date,tot_pub_debt_out_amt',
            'sort' => 'record_date',
            'page' => ['number' => $page, 'size' => self::PAGE_SIZE],
        ]);
    }

    /**
     * @return array{entries: list<DebtEntry>, skipped: int}  Entries oldest first, one per date.
     * @throws \USDebt\Exception\HttpException when page 1 cannot be downloaded.
     * @throws UnexpectedValueException when page 1 is not the expected JSON shape.
     * @throws IncompleteDownloadException when a later page fails or rows are missing.
     */
    public function fetchAll(): array
    {
        $first = $this->decode($this->fetcher->get(self::url(1), self::TIMEOUT_SECONDS));
        $totalPages = self::metaInt($first, 'total-pages') ?? 1;
        if ($totalPages < 1 || $totalPages > self::MAX_PAGES) {
            throw new UnexpectedValueException("Treasury reports {$totalPages} pages; expected 1 to " . self::MAX_PAGES);
        }

        $rows = $first['data'];
        $failed = [];
        for ($page = 2; $page <= $totalPages; $page++) {
            try {
                $json = $this->decode($this->fetcher->get(self::url($page), self::TIMEOUT_SECONDS));
                array_push($rows, ...$json['data']);
            } catch (Throwable $e) {
                // Keep going: one bad page must not hide whether the others work.
                $failed[$page] = get_class($e) . ': ' . $e->getMessage();
                error_log("UsDebt: Treasury page {$page} of {$totalPages} failed: {$failed[$page]}");
            }
        }
        if ($failed !== []) {
            throw new IncompleteDownloadException(sprintf(
                'Treasury download incomplete: %d of %d page(s) failed (page %s)',
                count($failed),
                $totalPages,
                implode(', ', array_keys($failed))
            ), $failed);
        }

        $expected = self::metaInt($first, 'total-count');
        if ($expected !== null && count($rows) < $expected) {
            throw new IncompleteDownloadException(sprintf(
                'Treasury download incomplete: got %d of %d rows',
                count($rows),
                $expected
            ));
        }

        return $this->mapRows($rows);
    }

    /**
     * Parse one response body (one page).
     *
     * @return array{entries: list<DebtEntry>, skipped: int}
     * @throws UnexpectedValueException
     */
    public function parse(string $body): array
    {
        return $this->mapRows($this->decode($body)['data']);
    }

    /**
     * @return array{data: list<mixed>, meta: array<mixed>}
     * @throws UnexpectedValueException
     */
    private function decode(string $body): array
    {
        try {
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('Treasury response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new UnexpectedValueException('Treasury response has no "data" list');
        }
        return [
            'data' => array_values($json['data']),
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : [],
        ];
    }

    /**
     * @param array{data: list<mixed>, meta: array<mixed>} $page
     */
    private static function metaInt(array $page, string $key): ?int
    {
        $value = $page['meta'][$key] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * @param list<mixed> $rows
     * @return array{entries: list<DebtEntry>, skipped: int}
     */
    private function mapRows(array $rows): array
    {
        $byDate = [];
        $skipped = 0;
        foreach ($rows as $index => $row) {
            $entry = self::mapRow($row);
            if ($entry === null) {
                $skipped++;
                error_log(sprintf('UsDebt: skipped bad Treasury row #%s: %s', $index, substr((string) json_encode($row), 0, 200)));
                continue;
            }
            $byDate[$entry->effectiveDate] = $entry;
        }
        ksort($byDate, SORT_STRING);

        return ['entries' => array_values($byDate), 'skipped' => $skipped];
    }

    /**
     * Map one API row. Returns null for a row that cannot be used.
     */
    public static function mapRow(mixed $row): ?DebtEntry
    {
        if (!is_array($row)) {
            return null;
        }
        $date = $row['record_date'] ?? null;
        $amount = $row['tot_pub_debt_out_amt'] ?? null;
        if (!is_string($date) || !self::isIsoDate($date)) {
            return null;
        }
        if (!(is_string($amount) || is_int($amount) || is_float($amount)) || !is_numeric($amount)) {
            return null;
        }
        $value = (float) $amount;
        if (!is_finite($value) || $value <= 0) {
            return null;
        }
        return new DebtEntry($date, $value);
    }

    private static function isIsoDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
