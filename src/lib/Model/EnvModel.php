<?php

namespace USDebt\Model;

use Exception;
use USDebt\Service\Cache\CacheInterface;

/**
 * @property string $cache_dir
 * @property CacheInterface $cache_machine
 * @property string $time_zone
 */
class EnvModel
{
    public function __construct($env)
    {
        foreach ($env as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * @throws Exception
     */
    public function __get(string $name)
    {
        if (!isset($this->$name)) {
            throw new Exception("The Environment Variable, \"$name\", does not exist.");
        }
        return $this->$name;;
    }
}