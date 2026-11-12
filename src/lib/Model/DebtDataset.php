<?php

declare(strict_types=1);

namespace USDebt\Model;

use DateTimeInterface;

/**
 * The full daily debt history, oldest day first, as loaded from the daily cache.
 * Every page slices what it needs from this one copy, so a page never calls the Treasury itself.
 */
final class DebtDataset
{
    /** @var list<DebtEntry> */
    private array $entries;

    /**
     * @param list<DebtEntry> $entries  Sorted oldest first, one entry per date.
     * @param string          $savedOn  Date (Y-m-d) the copy was downloaded.
     * @param bool            $isStale  True when today's download failed and an older copy is served.
     */
    public function __construct(
        array $entries,
        private readonly string $savedOn,
        private readonly bool $isStale = false,
    ) {
        $this->entries = array_values($entries);
    }

    /**
     * Entries from $start to $end, both days included, oldest first.
     *
     * @return list<DebtEntry>
     */
    public function between(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        if ($from > $to) {
            return [];
        }
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry->effectiveDate > $to) {
                break;
            }
            if ($entry->effectiveDate >= $from) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** @return list<DebtEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function firstDate(): ?string
    {
        return $this->entries[0]->effectiveDate ?? null;
    }

    public function lastDate(): ?string
    {
        $last = end($this->entries);
        return $last === false ? null : $last->effectiveDate;
    }

    public function savedOn(): string
    {
        return $this->savedOn;
    }

    public function isStale(): bool
    {
        return $this->isStale;
    }
}
