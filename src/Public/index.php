<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

use USDebt\Controller\FrontController;

header_remove('X-Powered-By');
echo FrontController::create()->handle($_GET, $_POST);
