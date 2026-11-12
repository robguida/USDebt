<?php

declare(strict_types=1);

namespace USDebt\Controller;

use Throwable;
use USDebt\Exception\CardUnavailableException;
use USDebt\Exception\UserFacingException;
use USDebt\Http\Response;
use USDebt\Model\RequestModel;
use USDebt\Service\CardCache;
use USDebt\Service\CardRenderer;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\DebtSource;
use USDebt\Service\LogOnce;
use USDebt\Service\ShareCardBuilder;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;

/**
 * The share picture card (card.php): a 1200x630 PNG for the same view as the page.
 *
 * - Same input as the page, checked the same way (FrontController::readRequest):
 *   ?start_date=&end_date=[&trend=0], or ?compare=<id>,<id>,... No parameters
 *   gives the home page's default range. Either can add per=person or
 *   per=household (anything else is the total).
 * - Drawn from the one daily Treasury copy. Never calls the Treasury itself
 *   beyond the normal once-a-day download that any page view can start.
 * - Saved on disk (CardCache), keyed by the normalized view, so the same view
 *   is drawn once per data day.
 *
 * Status codes: 200 with the PNG; 400 for input we cannot use (bad or
 * reversed dates, fewer than 2 Compare picks, a range or picks with no data);
 * 503 when there is no Treasury data at all, or this server cannot draw cards
 * (no gd); 500 for anything unexpected. Errors are short plain text, never an
 * image. Their words are in ShareText (CARD_NO_DATA, CARD_UNAVAILABLE); a 400
 * or 503 from the request checks uses the page's own message, and a 500 the
 * site-wide FrontController::GENERIC_ERROR.
 */
final class CardController
{
    public const CACHE_CONTROL = 'public, max-age=3600';

    private readonly ShareCardBuilder $shareCards;

    public function __construct(
        private readonly DebtSource $debtSource,
        private readonly CompareService $compareService,
        private readonly CardRenderer $renderer,
        private readonly ?CardCache $cache = null,
    ) {
        $this->shareCards = new ShareCardBuilder($compareService);
    }

    /**
     * Production wiring: the same Treasury cache as the page; cards in
     * <cache folder>/cards. Card cache problems are logged once per back-off
     * window, with the same marker folder as the Treasury cache.
     */
    public static function create(): self
    {
        $links = ShareLinks::fromEnvironment();
        $cacheDir = FrontController::cacheDir();
        return new self(
            FrontController::repository(),
            FrontController::compareService(),
            new CardRenderer(ShareText::siteAddress($links->baseUrl())),
            new CardCache(
                rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . CardCache::DIR_NAME,
                new LogOnce(LogOnce::defaultMarkerDir($cacheDir), DebtRepository::RETRY_AFTER_SECONDS),
            ),
        );
    }

    /**
     * @param array<string, mixed> $query  $_GET
     */
    public function handle(array $query): Response
    {
        $request = new RequestModel();
        $request->setShowTrend(($query['trend'] ?? null) !== '0');
        try {
            FrontController::readRequest($request, $query, [], $this->compareService);
            if (!$request->isCompare()) {
                USDebtController::resolveRange($request);
            }
            if (!CardRenderer::gdAvailable()) {
                throw new CardUnavailableException('The PHP gd extension with FreeType is not loaded.');
            }

            $dataset = $this->debtSource->load();
            $card = $request->isCompare()
                ? $this->shareCards->forCompare($dataset, $request->getComparePres(), $request->showTrend(), $request->per())
                : $this->shareCards->forRange($dataset, $request->getStartDate(), $request->getEndDate(), $request->showTrend(), true, $request->per());
            if ($card === null) {
                return self::error(400, ShareText::CARD_NO_DATA);
            }

            $png = $this->cache?->get($card->cacheKey);
            if ($png === null) {
                $png = $this->renderer->render($card);
                $this->cache?->put($card->cacheKey, $png);
            }
            return new Response(200, $png, [
                'Content-Type' => 'image/png',
                'Cache-Control' => self::CACHE_CONTROL,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Length' => (string) strlen($png),
            ]);
        } catch (UserFacingException $e) {
            // Includes DataUnavailableException (503: no Treasury data at all).
            return self::error($e->httpStatus(), $e->getMessage());
        } catch (CardUnavailableException $e) {
            error_log('UsDebt: share card unavailable: ' . $e->getMessage());
            return self::error(503, ShareText::CARD_UNAVAILABLE);
        } catch (Throwable $e) {
            error_log('UsDebt: share card failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            return self::error(500, FrontController::GENERIC_ERROR);
        }
    }

    /** Plain-text error: no image, not cached. */
    private static function error(int $status, string $message): Response
    {
        return new Response($status, $message . "\n", [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
