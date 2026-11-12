<?php

declare(strict_types=1);

namespace USDebt\Controller;

use Throwable;
use USDebt\Exception\UserFacingException;
use USDebt\Http\NativeHttpFetcher;
use USDebt\Http\Response;
use USDebt\Model\Per;
use USDebt\Model\RequestModel;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\FiscalDataClient;
use USDebt\Service\Html;
use USDebt\Service\ShareLinks;
use USDebt\SiteConfig;

/**
 * Turns one web request into one HTML page. Visitors only ever see messages we
 * wrote; any other error is logged in full and replaced by GENERIC_ERROR.
 */
final class FrontController
{
    public const GENERIC_ERROR = 'Sorry, something went wrong. Please try again later.';

    public function __construct(private readonly USDebtController $controller)
    {
    }

    /**
     * Production wiring. The site address for share links comes from
     * USDEBT_BASE_URL (see ShareLinks), never from the request.
     */
    public static function create(): self
    {
        return new self(new USDebtController(self::repository(), new CompareService(), ShareLinks::fromEnvironment()));
    }

    /**
     * The daily Treasury cache, shared by the page and the share card.
     */
    public static function repository(): DebtRepository
    {
        return new DebtRepository(
            new FiscalDataClient(new NativeHttpFetcher()),
            self::cacheDir(),
            dirname(__DIR__) . '/Data/debt_seed_1993.json',
        );
    }

    /**
     * The cache folder: USDEBT_CACHE_DIR, or src/var/cache (outside the web
     * root, gitignored).
     */
    public static function cacheDir(): string
    {
        return getenv('USDEBT_CACHE_DIR') ?: dirname(__DIR__, 2) . '/var/cache';
    }

    /**
     * Status codes: 200 for a page, 400 for input we cannot use, 503 when no
     * Treasury data can be loaded, 500 for anything unexpected.
     *
     * @param array<string, mixed> $query  $_GET
     * @param array<string, mixed> $post   $_POST
     */
    public function handle(array $query, array $post): Response
    {
        $request = new RequestModel();
        // The trend switch lives in the URL (?trend=0), so a shared link opens the same view.
        $request->setShowTrend(($query['trend'] ?? null) !== '0');
        try {
            self::readRequest($request, $query, $post, $this->controller->compareService());
            if ($request->isCompare()) {
                return new Response(200, $this->controller->compareView($request));
            }
            return new Response(200, $this->controller->defaultView($request));
        } catch (UserFacingException $e) {
            return $this->errorPage($e->httpStatus(), $e->getMessage(), $request);
        } catch (Throwable $e) {
            error_log('UsDebt: unhandled ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            return $this->errorPage(500, self::GENERIC_ERROR, $request);
        }
    }

    /**
     * Read the view from the request: Compare picks, or a date range.
     * Shared by the page and the share card, so both accept exactly the same input.
     *
     * - Compare by form post: submit=Compare and compare_pres[]=<term id>, ...
     * - Compare by link (the share link): ?compare=<term id>,<term id>,...
     *   Both go through CompareService::validateSelection: unknown and
     *   duplicate ids are ignored, there is no cap, and fewer than 2 valid
     *   picks is a 400 ("Please pick at least 2 presidents to compare.").
     *   A posted Compare wins over ?compare= in the address.
     * - Otherwise start_date and end_date (YYYY-MM-DD), both optional.
     * - Either way, per=person or per=household ("Your Share"). Anything else
     *   is the total, never an error. Read first, so an error page keeps it.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @throws UserFacingException  Input we cannot use.
     */
    public static function readRequest(RequestModel $request, array $query, array $post, CompareService $compareService): void
    {
        $input = $post + $query;
        $request->setPer(Per::fromInput($input['per'] ?? null));
        $action = is_string($input['submit'] ?? null) ? strtolower($input['submit']) : '';
        $picks = null;
        if ($action === 'compare') {
            $picks = $input['compare_pres'] ?? [];
        } elseif (array_key_exists('compare', $query)) {
            $picks = self::pickList($query['compare']);
        }
        if ($picks !== null) {
            $request->setComparePres($compareService->validateSelection($picks));
            return;
        }
        $start = self::text($input['start_date'] ?? null);
        $end = self::text($input['end_date'] ?? null);
        if ($start !== '') {
            $request->setStartDate($start);
        }
        if ($end !== '') {
            $request->setEndDate($end);
        }
    }

    /**
     * "1,2,3" (the share link form) or a list (compare[]=1&compare[]=2).
     * Anything else picks nothing.
     *
     * @return array<mixed>
     */
    private static function pickList(mixed $value): array
    {
        if (is_string($value)) {
            return explode(',', $value);
        }
        return is_array($value) ? $value : [];
    }

    private function errorPage(int $status, string $message, RequestModel $request): Response
    {
        try {
            return new Response($status, $this->controller->getErrorView($message, $request));
        } catch (Throwable $e) {
            error_log('UsDebt: error page failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new Response(500, '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . Html::e(SiteConfig::NAME) . '</title></head><body><p>'
                . Html::e(self::GENERIC_ERROR) . '</p></body></html>');
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
