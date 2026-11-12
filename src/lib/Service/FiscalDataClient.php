<?php

declare(strict_types=1);

namespace USDebt\Service;

use JsonException;
use UnexpectedValueException;
use USDebt\Http\HttpFetcher;
use USDebt\Model\DebtEntry;

/**
 * Downloads the full "Debt to the Penny" history from the official Treasury API
 * (fiscaldata.treasury.gov) in one request and maps it to DebtEntry objects.
 *
 * Field mapping (API -> app):
 *   record_date          -> DebtEntry::effectiveDate (Y-m-d)
 *   tot_pub_debt_out_amt -> DebtEntry::totalDebt (dollars)
 */
final class FiscalDataClient
{
    public const ENDPOINT = 'https://api.fiscaldata.treasury.gov/services/api/fiscal_service/v2/accounting/od/debt_to_penny';

    /** The API's largest page. Newest rows come first, so only the oldest would be cut off. */
    public const PAGE_SIZE = 10000;

    public const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly HttpFetcher $fetcher)
    {
    }

    public static function url(): string
    {
        return self::ENDPOINT . '?' . http_build_query([
            'fields' => 'record_date,tot_pub_debt_out_amt',
            'sort' => '-record_date',
            'page' => ['size' => self::PAGE_SIZE],
        ]);
    }

    /**
     * @return array{entries: list<DebtEntry>, skipped: int}  Entries oldest first, one per date.
     * @throws \USDebt\Exception\HttpException
     * @throws UnexpectedValueException when the body is not the expected JSON shape.
     */
    public function fetchAll(): array
    {
        $body = $this->fetcher->get(self::url(), self::TIMEOUT_SECONDS);
        return $this->parse($body);
    }

    /**
     * @return array{entries: list<DebtEntry>, skipped: int}
     * @throws UnexpectedValueException
     */
    public function parse(string $body): array
    {
        try {
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('Treasury response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new UnexpectedValueException('Treasury response has no "data" list');
        }

        $byDate = [];
        $skipped = 0;
        foreach ($json['data'] as $index => $row) {
            $entry = self::mapRow($row);
            if ($entry === null) {
                $skipped++;
                error_log(sprintf('UsDebt: skipped bad Treasury row #%s: %s', $index, substr((string) json_encode($row), 0, 200)));
                continue;
            }
            $byDate[$entry->effectiveDate] = $entry;
        }
        ksort($byDate, SORT_STRING);

        $total = $json['meta']['total-count'] ?? null;
        if (is_int($total) && $total > count($json['data'])) {
            error_log(sprintf(
                'UsDebt: Treasury has %d rows but one page holds %d; the oldest rows were not downloaded.',
                $total,
                count($json['data'])
            ));
        }

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
