<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use USDebt\Service\FiscalDataClient;
use USDebt\Tests\Support\FakeHttpFetcher;

final class FiscalDataClientTest extends TestCase
{
    public function testMapsFieldsFromSavedApiResponse(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__) . '/fixtures/debt_to_penny_sample.json');
        $result = (new FiscalDataClient(new FakeHttpFetcher()))->parse($body);

        self::assertSame(0, $result['skipped']);
        self::assertCount(10, $result['entries']);
        // API sends newest first; the client returns oldest first.
        self::assertSame('2026-10-27', $result['entries'][0]->effectiveDate);
        self::assertSame('2026-11-09', $result['entries'][9]->effectiveDate);
        self::assertSame(40559796589243.37, $result['entries'][9]->totalDebt);
    }

    public function testBadRowsAreSkippedAndCountedWithoutStoppingTheRest(): void
    {
        $body = (string) json_encode(['data' => [
            ['record_date' => '2026-11-03', 'tot_pub_debt_out_amt' => '100.50'],
            ['record_date' => '2026-11-04', 'tot_pub_debt_out_amt' => 'null'],
            ['record_date' => '2026-02-30', 'tot_pub_debt_out_amt' => '5'],
            ['record_date' => 'yesterday', 'tot_pub_debt_out_amt' => '5'],
            ['tot_pub_debt_out_amt' => '5'],
            'not a row',
            ['record_date' => '2026-11-05', 'tot_pub_debt_out_amt' => '-1'],
            ['record_date' => '2026-11-06', 'tot_pub_debt_out_amt' => '200'],
        ]]);
        $result = (new FiscalDataClient(new FakeHttpFetcher()))->parse($body);

        self::assertSame(6, $result['skipped']);
        self::assertSame(['2026-11-03', '2026-11-06'], array_map(fn ($e) => $e->effectiveDate, $result['entries']));
        self::assertSame(100.5, $result['entries'][0]->totalDebt);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new FiscalDataClient(new FakeHttpFetcher()))->parse('<html>Service Unavailable</html>');
    }

    public function testMissingDataListIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new FiscalDataClient(new FakeHttpFetcher()))->parse('{"error":"bad request"}');
    }

    public function testRequestsTheWholeDatasetNewestFirstWithATenSecondTimeout(): void
    {
        $fetcher = new FakeHttpFetcher(FakeHttpFetcher::body([['2026-11-09', '1']]));
        (new FiscalDataClient($fetcher))->fetchAll();

        self::assertCount(1, $fetcher->calls);
        self::assertSame(10, $fetcher->calls[0]['timeout']);
        $url = urldecode($fetcher->calls[0]['url']);
        self::assertStringStartsWith(FiscalDataClient::ENDPOINT . '?', $url);
        self::assertStringContainsString('fields=record_date,tot_pub_debt_out_amt', $url);
        self::assertStringContainsString('sort=-record_date', $url);
        self::assertStringContainsString('page[size]=10000', $url);
    }
}
