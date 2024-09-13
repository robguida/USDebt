<?php

namespace USDebt\Service\Cache;

interface CacheInterface
{
    public function get(string $key): string;
    public function add(string $key, string $value): static;
}