<?php

declare(strict_types=1);

namespace USDebt\Tests\Support;

use Throwable;
use USDebt\Http\HttpFetcher;

/**
 * Returns queued bodies (or throws queued errors) in order; the last item repeats.
 * Records every call so tests can prove when the network was NOT used.
 */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var list<array{url: string, timeout: int}> */
    public array $calls = [];

    /** @var list<string|Throwable> */
    private array $queue;

    public function __construct(string|Throwable ...$responses)
    {
        $this->queue = $responses;
    }

    public function get(string $url, int $timeoutSeconds): string
    {
        $this->calls[] = ['url' => $url, 'timeout' => $timeoutSeconds];
        $next = count($this->queue) > 1 ? array_shift($this->queue) : ($this->queue[0] ?? '');
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }

    /**
     * A fiscaldata-shaped body from [date, amount] pairs.
     *
     * @param list<array{0: mixed, 1: mixed}> $rows
     * @param int|null $totalCount  Rows in the whole dataset; defaults to the rows on this page.
     */
    public static function body(array $rows, int $totalPages = 1, ?int $totalCount = null): string
    {
        $data = [];
        foreach ($rows as [$date, $amount]) {
            $data[] = ['record_date' => $date, 'tot_pub_debt_out_amt' => $amount];
        }
        return (string) json_encode([
            'data' => $data,
            'meta' => ['count' => count($data), 'total-count' => $totalCount ?? count($data), 'total-pages' => $totalPages],
        ]);
    }
}
