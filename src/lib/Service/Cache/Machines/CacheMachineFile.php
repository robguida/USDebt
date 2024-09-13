<?php

namespace USDebt\Service\Cache\Machines;

use USDebt\Model\EnvModel;
use USDebt\Service\Cache\CacheInterface;

class CacheMachineFile implements CacheInterface
{
    private string $content;
    /**
     * @var false|resource
     */
    private $fileObj;

    public function __construct(EnvModel $envModel)
    {
        $this->fileObj = fopen($envModel->cache_dir . '/machine.cache', 'a+');
        $this->content = json_decode(fread($this->fileObj, filesize($envModel->cache_dir . '/machine.cache')));
    }

    public function get(string $key): string
    {
        return $this->content->{$key};
    }

    public function add(string $key, string $value): static
    {
        $this->content->{$key} = $value;
        return $this;
    }

    public function __destruct()
    {
        fwrite($this->fileObj, json_encode($this->content, JSON_PRETTY_PRINT));
        fclose($this->fileObj);
    }
}