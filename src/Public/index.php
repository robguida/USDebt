<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

use USDebt\Controller\FrontController;

header_remove('X-Powered-By');
$response = FrontController::create()->handle($_GET, $_POST);
http_response_code($response->status);
echo $response->body;
