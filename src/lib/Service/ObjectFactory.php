<?php

namespace USDebt\Service;

use USDebt\Model\EnvModel;

class ObjectFactory
{
    private EnvModel $envModel;

    public function __construct()
    {

    }

    public function getEnvModel(): EnvModel
    {
        return new EnvModel(parse_ini_file('../lib/.env'));
    }
}