<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use USDebt\Exception\DataUnavailableException;
use USDebt\Exception\HttpException;
use USDebt\Service\DebtRepository;
use USDebt\Service\FiscalDataClient;
use USDebt\Tests\Support\FakeHttpFetcher;
use USDebt\Tests\Support\TempDir;

final class DebtRepositoryTest extends TestCase
{
    use TempDir;

    private string $dir;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->now = new DateTimeImmutable('2026-11-12 09:00:00', new DateTimeZone('America/New_York'));
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function repo(FakeHttpFetcher $fetcher, ?string $seed = null): DebtRepository
    {
        return new DebtRepository(new FiscalDataClient($fetcher), $this->dir, $seed, fn () => $this->now, 900);
    }

    private static function okBody(): string
    {
        return FakeHttpFetcher::body([['2026-11-10', '300'], ['2026-11-09', '200'], ['2026-10-01', '100']]);
    }

    public function testFirstLoadDownloadsAndSavesTheFullDataset(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody());
        $data = $this->repo($fetcher)->load();

        self::assertCount(1, $fetcher->calls);
        self::assertSame(3, $data->count());
        self::assertSame('2026-10-01', $data->firstDate());
        self::assertSame('2026-11-12', $data->savedOn());
        self::assertFalse($data->isStale());
        self::assertFileExists($this->dir . '/' . DebtRepository::CACHE_FILE);
        self::assertSame([], glob($this->dir . '/*.tmp'), 'temp file is renamed, never left behind');
    }

    public function testSameDayCacheHitSkipsHttp(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody());
        $this->repo($fetcher)->load();
        $this->now = $this->now->setTime(23, 59);
        $again = $this->repo($fetcher)->load();

        self::assertCount(1, $fetcher->calls, 'second load the same day must not call the Treasury');
        self::assertSame(3, $again->count());
    }

    public function testNextDayDownloadsAgain(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody());
        $this->repo($fetcher)->load();
        $this->now = $this->now->modify('+1 day');
        $data = $this->repo($fetcher)->load();

        self::assertCount(2, $fetcher->calls);
        self::assertSame('2026-11-13', $data->savedOn());
    }

    public function testFailedDownloadServesLastGoodCopyAndWaitsBeforeRetrying(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody(), new HttpException('timeout'));
        $this->repo($fetcher)->load();

        $this->now = $this->now->modify('+1 day');
        $stale = $this->repo($fetcher)->load();
        self::assertTrue($stale->isStale());
        self::assertSame('2026-11-12', $stale->savedOn());
        self::assertSame(3, $stale->count());
        self::assertCount(2, $fetcher->calls);

        $this->now = $this->now->modify('+5 minutes');
        $this->repo($fetcher)->load();
        self::assertCount(2, $fetcher->calls, 'no retry inside the back-off window');

        $this->now = $this->now->modify('+20 minutes');
        $this->repo($fetcher)->load();
        self::assertCount(3, $fetcher->calls, 'retries after the back-off window');
    }

    public function testNoCopyAndFailedDownloadGivesFriendlyError(): void
    {
        $fetcher = new FakeHttpFetcher(new HttpException('connection refused to 10.0.0.1'));
        try {
            $this->repo($fetcher)->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException $e) {
            self::assertSame(DataUnavailableException::MESSAGE, $e->getMessage());
            self::assertStringNotContainsString('10.0.0.1', $e->getMessage());
        }
    }

    public function testInvalidResponseCountsAsAFailure(): void
    {
        $this->expectException(DataUnavailableException::class);
        $this->repo(new FakeHttpFetcher('<html>maintenance</html>'))->load();
    }

    public function testResponseWithNoUsableRowsCountsAsAFailure(): void
    {
        $this->expectException(DataUnavailableException::class);
        $this->repo(new FakeHttpFetcher(FakeHttpFetcher::body([['bad', 'null']])))->load();
    }

    public function testBadRowIsSkippedAndTheRestAreSaved(): void
    {
        $body = FakeHttpFetcher::body([['2026-11-10', '300'], ['2026-11-09', 'null'], ['2026-11-06', '100']]);
        $data = $this->repo(new FakeHttpFetcher($body))->load();

        self::assertSame(2, $data->count());
        $saved = json_decode((string) file_get_contents($this->dir . '/' . DebtRepository::CACHE_FILE), true);
        self::assertSame(1, $saved['skipped_rows']);
    }

    public function testCorruptCacheFileIsIgnoredAndReplaced(): void
    {
        file_put_contents($this->dir . '/' . DebtRepository::CACHE_FILE, '{not json');
        $fetcher = new FakeHttpFetcher(self::okBody());
        $data = $this->repo($fetcher)->load();

        self::assertCount(1, $fetcher->calls);
        self::assertSame(3, $data->count());
    }

    public function testSeedRowsFillDaysBeforeTheFirstDownloadedDay(): void
    {
        $seed = $this->dir . '/seed.json';
        file_put_contents($seed, (string) json_encode(['rows' => [
            ['2026-09-01', 50.0],
            ['2026-10-01', 999.0], // same day as downloaded data: downloaded value wins
            ['2026-10-02', 999.0], // after the first downloaded day: ignored
        ]]));
        $data = $this->repo(new FakeHttpFetcher(self::okBody()), $seed)->load();

        $dates = array_map(fn ($e) => $e->effectiveDate, $data->all());
        self::assertSame(['2026-09-01', '2026-10-01', '2026-11-09', '2026-11-10'], $dates);
        self::assertSame(100.0, $data->all()[1]->totalDebt);
    }

    public function testRealSeedFileCoversEarly1993(): void
    {
        $seed = dirname(__DIR__, 2) . '/src/lib/Data/debt_seed_1993.json';
        $body = FakeHttpFetcher::body([['1993-04-01', '4225873987843.44']]);
        $data = $this->repo(new FakeHttpFetcher($body), $seed)->load();

        self::assertSame('1993-01-04', $data->firstDate());
        self::assertSame(62, $data->count());
    }

    public function testWhileAnotherRequestDownloadsTheLastCopyIsServedWithoutHttp(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody());
        $this->repo($fetcher)->load();
        $this->now = $this->now->modify('+1 day');

        $other = fopen($this->dir . '/' . DebtRepository::LOCK_FILE, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, LOCK_EX));
        try {
            $data = $this->repo($fetcher)->load();
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        self::assertCount(1, $fetcher->calls);
        self::assertSame('2026-11-12', $data->savedOn());
    }

    public function testSlicingReturnsOnlyTheRequestedDays(): void
    {
        $data = $this->repo(new FakeHttpFetcher(self::okBody()))->load();
        $slice = $data->between(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-09'));

        self::assertSame(['2026-11-09'], array_map(fn ($e) => $e->effectiveDate, $slice));
        self::assertSame([], $data->between(new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-01')));
    }
}
