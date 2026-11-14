<?php

declare(strict_types=1);

/*
 * The Table tab as a CSV file, for the same view as the page.
 * Takes the page's own GET parameters (start_date, end_date, per).
 */

require __DIR__ . '/../lib/bootstrap.php';

use USDebt\Controller\DownloadController;

header_remove('X-Powered-By');
DownloadController::create()->handle($_GET)->send();
