<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use USDebt\Controller\DownloadController;
use USDebt\Controller\FrontController;
use USDebt\Controller\USDebtController;
use USDebt\Exception\DataUnavailableException;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\ShareLinks;
use USDebt\Service\TableCsv;

/** The Table tab's CSV download (download.php) and its button. */
final class DownloadTest extends TestCase
{
    private const RANGE = ['start_date' => '2026-09-01', 'end_date' => '2026-10-31'];

    protected function tearDown(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
    }

    private static function controller(?DebtSource $source = null): DownloadController
    {
        return new DownloadController($source ?? FrontControllerTest::source(), new CompareService());
    }

    /** @return list<string> */
    private static function lines(string $csv): array
    {
        self::assertStringEndsWith("\r\n", $csv);
        return explode("\r\n", substr($csv, 0, -2));
    }

    /** @return list<string> the dates in the Table, in order */
    private static function tableDates(string $html): array
    {
        $table = substr($html, (int) strpos($html, 'data-table'));
        preg_match_all('#<td class="date">([^<]+)</td>#', $table, $m);
        return array_map(static fn (string $d): string => date('Y-m-d', strtotime($d)), $m[1]);
    }

    private static function page(array $query): string
    {
        $app = new FrontController(new USDebtController(FrontControllerTest::source(), new CompareService(), ShareLinks::fromEnvironment()));
        return $app->handle($query, [])->body;
    }

    public function testTotalCsv(): void
    {
        $r = self::controller()->handle(self::RANGE);
        self::assertSame(200, $r->status);
        self::assertSame('text/csv; charset=utf-8', $r->headers['Content-Type']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertSame('attachment; filename="our-us-debt_2026-09-01_to_2026-10-31.csv"', $r->headers['Content-Disposition']);
        self::assertSame((string) strlen($r->body), $r->headers['Content-Length']);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $r->body), 'CRLF only');
        $lines = self::lines($r->body);
        self::assertSame('Date,"Total debt (USD)"', $lines[0]);
        self::assertMatchesRegularExpression('/^2026-10-\d\d,\d+\.\d{2}$/', $lines[1]);
        self::assertStringNotContainsString('$', $r->body);
    }

    public function testPerPersonAndHouseholdCsv(): void
    {
        $p = self::controller()->handle(self::RANGE + ['per' => 'person']);
        self::assertSame('Date,"Debt per person (USD)"', self::lines($p->body)[0]);
        self::assertStringEndsWith('_per-person.csv"', $p->headers['Content-Disposition']);
        self::assertMatchesRegularExpression('/^2026-10-\d\d,\d{4,6}\.\d{2}$/', self::lines($p->body)[1]);

        $h = self::controller()->handle(self::RANGE + ['per' => 'household']);
        self::assertSame('Date,"Debt per household (USD)"', self::lines($h->body)[0]);
        self::assertStringEndsWith('_per-household.csv"', $h->headers['Content-Disposition']);
        self::assertGreaterThan((float) explode(',', self::lines($p->body)[1])[1], (float) explode(',', self::lines($h->body)[1])[1]);
    }

    /** Same rows, same order, same numbers as the Table on the page. */
    public function testMatchesTheTable(): void
    {
        foreach (['', 'person', 'household'] as $per) {
            $query = self::RANGE + ($per === '' ? [] : ['per' => $per]);
            $lines = self::lines(self::controller()->handle($query)->body);
            array_shift($lines);
            $dates = array_map(static fn (string $l): string => explode(',', $l)[0], $lines);
            self::assertSame(self::tableDates(self::page($query)), $dates, "per=$per");
            self::assertSame($dates, array_reverse(array_values(array_unique(array_reverse($dates)))), 'unique');
            self::assertGreaterThan($dates[1], $dates[0], 'newest first');
        }
    }

    public function testFullHistoryIsFine(): void
    {
        $r = self::controller()->handle(['start_date' => '1993-01-01', 'end_date' => '2026-11-09']);
        self::assertSame(200, $r->status);
        self::assertGreaterThan(1700, count(self::lines($r->body)));
    }

    public function testNoDatesUsesThePagesDefaultRange(): void
    {
        $r = self::controller(FrontControllerTest::sourceOf(new DebtEntry(date('Y-m-d'), 1.5e13)))->handle([]);
        self::assertSame(200, $r->status);
        self::assertMatchesRegularExpression('/filename="our-us-debt_\d{4}-\d\d-\d\d_to_\d{4}-\d\d-\d\d\.csv"/', $r->headers['Content-Disposition']);
    }

    public function testOnlyThePageQueryNamesAreRead(): void
    {
        $r = self::controller()->handle(self::RANGE + ['trend' => '0', 'r' => 'abc', 'x' => '"; evil']);
        self::assertSame(200, $r->status);
        self::assertStringNotContainsString('evil', $r->headers['Content-Disposition']);
    }

    public function testBadInputIs400(): void
    {
        foreach ([
            ['start_date' => '2026-02-30'],
            ['start_date' => '2026-10-31', 'end_date' => '2026-09-01'],
            ['start_date' => ['a'], 'end_date' => '2026-09-01x'],
            ['compare' => '1,2'],
            ['compare' => '1,2', 'start_date' => '2026-09-01', 'end_date' => '2026-10-31'],
        ] as $query) {
            $r = self::controller()->handle($query);
            self::assertSame(400, $r->status, json_encode($query));
            self::assertSame('text/plain; charset=utf-8', $r->headers['Content-Type']);
            self::assertArrayNotHasKey('Content-Disposition', $r->headers);
        }
        self::assertStringContainsString('no Compare', self::controller()->handle(['compare' => '1,2'])->body);
    }

    public function testRangeWithNoDataIs400(): void
    {
        $r = self::controller()->handle(['start_date' => '1980-01-01', 'end_date' => '1980-02-01']);
        self::assertSame(400, $r->status);
    }

    public function testNoTreasuryDataIs503(): void
    {
        $source = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new DataUnavailableException();
            }
        };
        $r = self::controller($source)->handle(self::RANGE);
        self::assertSame(503, $r->status);
        self::assertSame(DataUnavailableException::MESSAGE . "\n", $r->body);
    }

    public function testUnexpectedFailureIs500WithTheGenericMessage(): void
    {
        $source = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $r = self::controller($source)->handle(self::RANGE);
        self::assertSame(500, $r->status);
        self::assertStringNotContainsString('secret', $r->body);
        self::assertStringContainsString(FrontController::GENERIC_ERROR, $r->body);
    }

    public function testFormulaGuard(): void
    {
        foreach (['=1+1', '+1', '-1', '@x', "\tx", "\rx"] as $bad) {
            self::assertSame("'" . $bad, TableCsv::text($bad));
        }
        self::assertSame('2026-10-31', TableCsv::text('2026-10-31'));
        self::assertSame('', TableCsv::text(''));
        // A bad amount is written as guarded text, never as a bare "-5.00"; the other rows stay.
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $csv = TableCsv::build([new DebtEntry('2026-10-31', -5.0), new DebtEntry('2026-10-30', 10.0)], Per::Total);
        } finally {
            ini_set('error_log', (string) $prev);
        }
        self::assertSame(["Date,\"Total debt (USD)\"", "2026-10-31,'-5.00", '2026-10-30,10.00'], self::lines($csv));
    }

    public function testQuotingFollowsRfc4180(): void
    {
        $csv = TableCsv::build([new DebtEntry('2026-10-31', 1234567.891)], Per::Person);
        self::assertSame('2026-10-31,1234567.89', self::lines($csv)[1]);
    }

    public function testFileNames(): void
    {
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Total));
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01_per-person.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Person));
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01_per-household.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Household));
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function buttons(): array
    {
        $range = 'start_date=2026-09-01&end_date=2026-10-31';
        return [
            'default base, total' => ['', self::RANGE, "/ourusdebt/download.php?$range"],
            'default base, person' => ['', self::RANGE + ['per' => 'person'], "/ourusdebt/download.php?$range&per=person"],
            'subpath base, household' => ['https://bengusa.pro/ourusdebt', self::RANGE + ['per' => 'household'], "/ourusdebt/download.php?$range&per=household"],
            'root base (local)' => ['http://localhost:8000', self::RANGE, "/download.php?$range"],
            'other folder' => ['https://example.org/a/b/', self::RANGE + ['per' => 'person'], "/a/b/download.php?$range&per=person"],
        ];
    }

    /** @dataProvider buttons */
    #[\PHPUnit\Framework\Attributes\DataProvider('buttons')]
    public function testButtonOnTheTableTab(string $base, array $query, string $href): void
    {
        $base === '' ? putenv(ShareLinks::ENV_BASE_URL) : putenv(ShareLinks::ENV_BASE_URL . '=' . $base);
        $html = self::page($query);
        self::assertStringContainsString(
            '<a class="btn btn-quiet" href="' . htmlspecialchars($href, ENT_QUOTES) . '" download>Download table (CSV)</a>',
            $html,
        );
        self::assertStringContainsString('Opens in Excel, Numbers or Google Sheets.', $html);
    }

    public function testNoButtonOnCompare(): void
    {
        self::assertStringNotContainsString('Download table', self::page(['compare' => '1,2']));
    }
}
