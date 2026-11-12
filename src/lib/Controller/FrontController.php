<?php

declare(strict_types=1);

namespace USDebt\Controller;

use Throwable;
use USDebt\Exception\UserFacingException;
use USDebt\Http\NativeHttpFetcher;
use USDebt\Http\Response;
use USDebt\Model\RequestModel;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\FiscalDataClient;
use USDebt\Service\Html;

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
     * Production wiring. The cache folder comes from USDEBT_CACHE_DIR, or
     * defaults to src/var/cache (outside the web root, gitignored).
     */
    public static function create(): self
    {
        $libDir = dirname(__DIR__);
        $cacheDir = getenv('USDEBT_CACHE_DIR') ?: dirname($libDir) . '/var/cache';
        $repository = new DebtRepository(
            new FiscalDataClient(new NativeHttpFetcher()),
            $cacheDir,
            $libDir . '/Data/debt_seed_1993.json',
        );
        return new self(new USDebtController($repository, new CompareService()));
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
        $input = $post + $query;
        $request = new RequestModel();
        try {
            $action = is_string($input['submit'] ?? null) ? strtolower($input['submit']) : '';
            if ($action === 'compare') {
                $keys = $this->controller->compareService()->validateSelection($input['compare_pres'] ?? []);
                $request->setComparePres($keys);
                return new Response(200, $this->controller->compareView($request));
            }
            $start = self::text($input['start_date'] ?? null);
            $end = self::text($input['end_date'] ?? null);
            if ($start !== '') {
                $request->setStartDate($start);
            }
            if ($end !== '') {
                $request->setEndDate($end);
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

    private function errorPage(int $status, string $message, RequestModel $request): Response
    {
        try {
            return new Response($status, $this->controller->getErrorView($message, $request));
        } catch (Throwable $e) {
            error_log('UsDebt: error page failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new Response(500, '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Our U.S. Debt</title></head><body><p>'
                . Html::e(self::GENERIC_ERROR) . '</p></body></html>');
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
