<?php

namespace USDebt\Service\Cache;

use USDebt\Model\EnvModel;

class CacheFactory implements CacheInterface
{
    private CacheInterface $CacheMachineService;

    public function __construct(EnvModel $envModel)
    {
        $cache_service_name = __NAMESPACE__ . "\\Machines\\CacheMachine{$envModel->cache_machine}";
        $this->CacheMachineService = new $cache_service_name($envModel);
    }

    public function get(string $key): string
    {
       return $this->CacheMachineService->get($key);
    }

    public function add(string $key, string $value): static
    {
        $this->CacheMachineService->add($key, $value);
        return $this;
    }
}