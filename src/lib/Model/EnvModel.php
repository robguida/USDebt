<?php

namespace USDebt\Model;

use Exception;

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
            throw new Exception("Property $name does not exist");
        }
        return $this->$name;;
    }
}