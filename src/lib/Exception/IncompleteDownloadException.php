<?php

declare(strict_types=1);

namespace USDebt\Exception;

use RuntimeException;

/**
 * One or more pages of a multi-page Treasury download failed, or fewer rows
 * arrived than the API said exist. Partial data must never be saved.
 */
final class IncompleteDownloadException extends RuntimeException
{
    /**
     * @param array<int, string> $failedPages  Page number => why it failed.
     */
    public function __construct(string $message, private readonly array $failedPages = [])
    {
        parent::__construct($message);
    }

    /** @return array<int, string> */
    public function failedPages(): array
    {
        return $this->failedPages;
    }
}
