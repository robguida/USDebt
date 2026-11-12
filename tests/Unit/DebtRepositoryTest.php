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

    private function repo(
        FakeHttpFetcher $fetcher,
        ?string $seed = null,
        float $lockWait = DebtRepository::LOCK_WAIT_SECONDS,
        ?\Closure $sleep = null,
    ): DebtRepository {
        return new DebtRepository(new FiscalDataClient($fetcher), $this->dir, $seed, fn () => $this->now, 900, $lockWait, $sleep, $this->markerDir());
    }

    /** Folder for the "already logged" markers, inside this test's temp folder. */
    private function markerDir(): string
    {
        $dir = $this->dir . '/log-markers';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return resource  The lock, held as if another request were downloading. */
    private function holdLock()
    {
        $other = fopen($this->dir . '/' . DebtRepository::LOCK_FILE, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, LOCK_EX));
        return $other;
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
        self::assertFalse($data->isStale(), 'a normal refresh in progress is not a failure');
    }

    public function testNewYorkMidnightDecidesTheDayEvenWithAUtcClock(): void
    {
        $utc = new DateTimeZone('UTC');
        $fetcher = new FakeHttpFetcher(self::okBody());
        // 04:59 UTC on Nov 13 is 23:59 on Nov 12 in New York (EST, UTC-5).
        $this->now = new DateTimeImmutable('2026-11-13 04:59:00', $utc);
        self::assertSame('2026-11-12', $this->repo($fetcher)->load()->savedOn());

        $this->now = new DateTimeImmutable('2026-11-13 04:59:59', $utc);
        $this->repo($fetcher)->load();
        self::assertCount(1, $fetcher->calls, 'still Nov 12 in New York: no new download');

        // 05:00 UTC is midnight in New York: a new day.
        $this->now = new DateTimeImmutable('2026-11-13 05:00:00', $utc);
        self::assertSame('2026-11-13', $this->repo($fetcher)->load()->savedOn());
        self::assertCount(2, $fetcher->calls);
    }

    public function testUnwritableCacheFolderNeverDownloads(): void
    {
        // A file where the folder should be: the folder cannot be created or written.
        $blocked = $this->dir . '/not-a-folder';
        file_put_contents($blocked, 'x');
        $fetcher = new FakeHttpFetcher(self::okBody());
        $repo = new DebtRepository(new FiscalDataClient($fetcher), $blocked, null, fn () => $this->now, 900, 1.0, null, $this->markerDir());

        for ($i = 0; $i < 3; $i++) {
            try {
                $repo->load();
                self::fail('expected DataUnavailableException');
            } catch (DataUnavailableException) {
                // Friendly error, no download.
            }
        }
        self::assertCount(0, $fetcher->calls, 'no Treasury call per page view when nothing can be saved');
    }

    public function testUnwritableCacheFolderServesTheSavedCopyWithoutDownloading(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('needs POSIX folder permissions and a non-root user');
        }
        $fetcher = new FakeHttpFetcher(self::okBody());
        $this->repo($fetcher)->load();
        chmod($this->dir, 0555);
        try {
            $this->now = $this->now->modify('+1 day');
            $data = $this->repo($fetcher)->load();
        } finally {
            chmod($this->dir, 0775);
        }
        self::assertCount(1, $fetcher->calls);
        self::assertTrue($data->isStale());
        self::assertSame('2026-11-12', $data->savedOn());
    }

    public function testLockThatCannotBeOpenedServesTheSavedCopyWithoutDownloading(): void
    {
        $fetcher = new FakeHttpFetcher(self::okBody());
        $this->repo($fetcher)->load();
        $lock = $this->dir . '/' . DebtRepository::LOCK_FILE;
        unlink($lock);
        mkdir($lock); // a folder where the lock file should be, so it cannot be opened

        $this->now = $this->now->modify('+1 day');
        $data = $this->repo($fetcher)->load();
        $this->repo($fetcher)->load();

        self::assertCount(1, $fetcher->calls, 'no download without the lock');
        self::assertTrue($data->isStale());
        self::assertSame('2026-11-12', $data->savedOn());
    }

    public function testLockThatCannotBeOpenedWithNoCopyGivesFriendlyError(): void
    {
        mkdir($this->dir . '/' . DebtRepository::LOCK_FILE);
        $fetcher = new FakeHttpFetcher(self::okBody());
        try {
            $this->repo($fetcher)->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException) {
            self::assertCount(0, $fetcher->calls);
        }
    }

    public function testColdCacheWaitsForTheOtherDownloadAndUsesItsCopy(): void
    {
        $other = $this->holdLock();
        $sleeps = 0;
        // The other request saves its copy and lets go of the lock during our second pause.
        $sleep = function () use (&$sleeps, $other): void {
            $sleeps++;
            if ($sleeps === 2) {
                file_put_contents($this->dir . '/' . DebtRepository::CACHE_FILE, (string) json_encode([
                    'version' => 1,
                    'saved_on' => '2026-11-12',
                    'rows' => [['2026-11-09', 200.0], ['2026-11-10', 300.0]],
                ]));
                flock($other, LOCK_UN);
            }
        };
        $fetcher = new FakeHttpFetcher(self::okBody());
        try {
            $data = $this->repo($fetcher, null, 5.0, $sleep)->load();
        } finally {
            fclose($other);
        }

        self::assertCount(0, $fetcher->calls, 'the waiting request does not download');
        self::assertSame(2, $sleeps);
        self::assertSame(2, $data->count());
        self::assertFalse($data->isStale());
    }

    public function testColdCacheGivesUpWaitingAfterTheLimit(): void
    {
        $other = $this->holdLock();
        $sleeps = 0;
        $fetcher = new FakeHttpFetcher(self::okBody());
        try {
            $this->repo($fetcher, null, 0.3, function () use (&$sleeps): void {
                $sleeps++;
            })->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException) {
            self::assertSame(3, $sleeps, '0.3 s limit, one check every 0.1 s');
            self::assertCount(0, $fetcher->calls);
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }
    }

    public function testColdCacheWhoseOtherDownloadFailedDoesNotRetryInsideTheBackOff(): void
    {
        $other = $this->holdLock();
        $sleep = function () use ($other): void {
            file_put_contents($this->dir . '/' . DebtRepository::FAIL_FILE, (string) $this->now->getTimestamp());
            flock($other, LOCK_UN);
        };
        $fetcher = new FakeHttpFetcher(self::okBody());
        try {
            $this->repo($fetcher, null, 5.0, $sleep)->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException) {
            self::assertCount(0, $fetcher->calls);
        } finally {
            fclose($other);
        }
    }

    public function testFailedPageKeepsTheLastCompleteCopyAndSavesNothing(): void
    {
        $this->repo(new FakeHttpFetcher(self::okBody()))->load();
        $file = $this->dir . '/' . DebtRepository::CACHE_FILE;
        $before = (string) file_get_contents($file);

        $this->now = $this->now->modify('+1 day');
        $twoPages = new FakeHttpFetcher(
            FakeHttpFetcher::body([['2026-10-01', '100'], ['2026-11-09', '200']], 2, 4),
            new HttpException('page 2 timed out'),
        );
        $data = $this->repo($twoPages)->load();

        self::assertCount(2, $twoPages->calls);
        self::assertTrue($data->isStale(), 'last complete copy, flagged stale');
        self::assertSame('2026-11-12', $data->savedOn());
        self::assertSame(3, $data->count());
        self::assertSame($before, (string) file_get_contents($file), 'partial data is never saved');
        self::assertFileExists($this->dir . '/' . DebtRepository::FAIL_FILE, 'the back-off starts');
    }

    public function testFailedPageWithNoCopyGivesFriendlyErrorAndSavesNothing(): void
    {
        $twoPages = new FakeHttpFetcher(
            FakeHttpFetcher::body([['2026-10-01', '100']], 2, 2),
            new HttpException('page 2 timed out'),
        );
        try {
            $this->repo($twoPages)->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException) {
            self::assertFileDoesNotExist($this->dir . '/' . DebtRepository::CACHE_FILE);
        }
    }

    public function testCacheFolderAndFilesAreNotOpenToOtherUsers(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows does not use POSIX permission bits');
        }
        $folder = $this->dir . '/cache';
        $old = umask(0);
        try {
            $repo = new DebtRepository(new FiscalDataClient(new FakeHttpFetcher(self::okBody())), $folder, null, fn () => $this->now, 900);
            $repo->load();
        } finally {
            umask($old);
        }
        clearstatcache();
        self::assertSame(0750, fileperms($folder) & 0777);
        self::assertSame(0640, fileperms($folder . '/' . DebtRepository::CACHE_FILE) & 0777);
        self::assertSame(0640, fileperms($folder . '/' . DebtRepository::LOCK_FILE) & 0777);
    }

    public function testSlicingReturnsOnlyTheRequestedDays(): void
    {
        $data = $this->repo(new FakeHttpFetcher(self::okBody()))->load();
        $slice = $data->between(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-09'));

        self::assertSame(['2026-11-09'], array_map(fn ($e) => $e->effectiveDate, $slice));
        self::assertSame([], $data->between(new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-01')));
    }

    public function testDownloadThatCannotBeSavedStartsTheBackOff(): void
    {
        // A folder where the cache file should be: the rename onto it fails.
        mkdir($this->dir . '/' . DebtRepository::CACHE_FILE);
        $fetcher = new FakeHttpFetcher(self::okBody());

        $data = $this->repo($fetcher)->load();
        self::assertSame(3, $data->count(), 'this request still shows the new data');
        self::assertFalse($data->isStale());
        self::assertFileExists($this->dir . '/' . DebtRepository::FAIL_FILE);
        self::assertSame([], glob($this->dir . '/*.tmp'), 'the temp file is cleaned up');

        for ($i = 0; $i < 3; $i++) {
            $this->now = $this->now->modify('+1 minute');
            try {
                $this->repo($fetcher)->load();
                self::fail('expected DataUnavailableException');
            } catch (DataUnavailableException) {
                // No saved copy and inside the back-off: friendly error, no download.
            }
        }
        self::assertCount(1, $fetcher->calls, 'no download per page view while the copy cannot be saved');

        $this->now = $this->now->modify('+20 minutes');
        $this->repo($fetcher)->load();
        self::assertCount(2, $fetcher->calls, 'tries again after the back-off window');
    }

    public function testLastingProblemIsLoggedOncePerBackOffWindowAcrossProcesses(): void
    {
        $blocked = $this->dir . '/not-a-folder';
        file_put_contents($blocked, 'x');
        $log = $this->dir . '/php-error.log';
        $count = fn (): int => is_file($log) ? substr_count((string) file_get_contents($log), 'is missing or not writable') : 0;
        $oldLog = ini_set('error_log', $log);
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->loadIgnoringFriendlyError($blocked, $this->markerDir());
            }
            self::assertSame(1, $count(), 'three page views, one log line');

            // A new PHP-FPM request starts with empty static state; the marker file still holds.
            self::forgetWhatThisProcessLogged();
            $this->now = $this->now->modify('+5 minutes');
            $this->loadIgnoringFriendlyError($blocked, $this->markerDir());
            self::assertSame(1, $count(), 'still inside the window in a new process');

            self::forgetWhatThisProcessLogged();
            $this->now = $this->now->modify('+11 minutes');
            $this->loadIgnoringFriendlyError($blocked, $this->markerDir());
            self::assertSame(2, $count(), 'logged again once the window has passed');
        } finally {
            ini_set('error_log', (string) $oldLog);
        }
    }

    public function testLastingProblemIsLoggedOncePerWindowEvenWhenNoMarkerCanBeWritten(): void
    {
        $blocked = $this->dir . '/not-a-folder';
        file_put_contents($blocked, 'x');
        $log = $this->dir . '/php-error.log';
        $oldLog = ini_set('error_log', $log);
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->loadIgnoringFriendlyError($blocked, $this->dir . '/no-such-folder');
            }
        } finally {
            ini_set('error_log', (string) $oldLog);
        }
        self::assertSame(1, substr_count((string) file_get_contents($log), 'is missing or not writable'));
    }

    private function loadIgnoringFriendlyError(string $cacheDir, string $markerDir): void
    {
        $repo = new DebtRepository(
            new FiscalDataClient(new FakeHttpFetcher(self::okBody())), $cacheDir, null, fn () => $this->now, 900, 1.0, null, $markerDir
        );
        try {
            $repo->load();
            self::fail('expected DataUnavailableException');
        } catch (DataUnavailableException) {
            // Nothing can be saved, so the visitor gets the friendly error.
        }
    }

    private static function forgetWhatThisProcessLogged(): void
    {
        (new \ReflectionProperty(DebtRepository::class, 'logged'))->setValue(null, []);
    }
}
