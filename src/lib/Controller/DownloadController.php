<?php

declare(strict_types=1);

namespace USDebt\Controller;

use Throwable;
use USDebt\Exception\UserFacingException;
use USDebt\Http\Response;
use USDebt\Model\RequestModel;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\ShareText;
use USDebt\Service\TableCsv;

/**
 * The Table tab's CSV (download.php): the same rows the Table shows for the
 * same date range and the same Total / per person / per household choice.
 *
 * - Same input checks as the page (FrontController::readRequest):
 *   ?start_date=&end_date=[&per=person|household]. No dates gives the page's
 *   default range. Compare has no Table, so a compare request is a 400.
 * - Cut from the one daily Treasury copy: no extra Treasury calls.
 *
 * Status codes: 200 with the CSV; 400 for input we cannot use (bad or reversed
 * dates, Compare, a range with no data); 503 when there is no Treasury data at
 * all; 500 for anything unexpected (logged). Errors are short plain text.
 * No Cache-Control is sent, the same as the page.
 */
final class DownloadController
{
    public const NO_COMPARE = 'The Table has no Compare view. Pick a date range to download its table.';

    public function __construct(
        private readonly DebtSource $debtSource,
        private readonly CompareService $compareService,
    ) {
    }

    public static function create(): self
    {
        return new self(FrontController::repository(), FrontController::compareService());
    }

    /**
     * @param array<string, mixed> $query  $_GET
     */
    public function handle(array $query): Response
    {
        $request = new RequestModel();
        try {
            // Only start_date, end_date and per are read here; compare is refused below.
            $wanted = array_intersect_key($query, ['start_date' => 1, 'end_date' => 1, 'per' => 1]);
            FrontController::readRequest($request, $wanted, [], $this->compareService);
            if (array_key_exists('compare', $query) || array_key_exists('submit', $query)) {
                throw new UserFacingException(self::NO_COMPARE);
            }
            USDebtController::resolveRange($request);

            $start = $request->getStartDate();
            $end = $request->getEndDate();
            $per = $request->per();
            $entries = $this->compareService->population()->scale(
                $this->debtSource->load()->between($start, $end),
                $per,
            );
            if ($entries === []) {
                return self::error(400, ShareText::CARD_NO_DATA);
            }
            $body = TableCsv::build(array_reverse($entries), $per);
            $name = TableCsv::fileName($start->format('Y-m-d'), $end->format('Y-m-d'), $per);
            return new Response(200, $body, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Length' => (string) strlen($body),
            ]);
        } catch (UserFacingException $e) {
            // Includes DataUnavailableException (503: no Treasury data at all).
            return self::error($e->httpStatus(), $e->getMessage());
        } catch (Throwable $e) {
            error_log('UsDebt: CSV download failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            return self::error(500, FrontController::GENERIC_ERROR);
        }
    }

    /** Plain-text error: not cached. */
    private static function error(int $status, string $message): Response
    {
        return new Response($status, $message . "\n", [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
