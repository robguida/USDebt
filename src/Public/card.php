<?php

declare(strict_types=1);

/*
 * The share picture card: a 1200x630 PNG for the same view as the page.
 * Takes the page's own GET parameters (start_date, end_date, trend, compare).
 */

require __DIR__ . '/../lib/bootstrap.php';

use USDebt\Controller\CardController;

header_remove('X-Powered-By');
CardController::create()->handle($_GET)->send();
