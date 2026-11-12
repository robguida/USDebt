<?php
/**
 * Page frame. No inline scripts or inline event handlers: the page script is
 * a separate file, and chart data travels in a JSON data block.
 *
 * @var string $title            Page title (plain text).
 * @var string $notice           Already-rendered HTML (stale-data notice) or ''.
 * @var string $cards            Already-rendered HTML (headline numbers) or ''.
 * @var string $controls         Already-rendered HTML (date range + term strip).
 * @var string $results_heading  Plain text heading for the results card, or '' for the default.
 * @var string $results          Already-rendered HTML (chart, stats, table, or a message).
 */

use USDebt\Service\Html;

/* ?r= busts the browser cache when a file changes */
$public_dir = dirname(__DIR__, 2) . '/Public/';
$asset = static function (string $path) use ($public_dir): string {
    $mtime = @filemtime($public_dir . $path);
    return Html::e($path . ($mtime ? '?r=' . $mtime : ''));
};
$treasury_url = 'https://fiscaldata.treasury.gov/datasets/debt-to-the-penny/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?php echo Html::e($title); ?></title>
    <meta name="description" content="The U.S. national debt from U.S. Treasury data, as a chart, stats and a table. Compare how the debt changed during each presidential term.">
    <link rel="icon" href="<?php echo $asset('favicon.svg'); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo $asset('css/usdebt.css'); ?>">
    <script src="<?php echo $asset('lib/chart.umd.min.js'); ?>" defer></script>
    <script src="<?php echo $asset('js/usdebt.js'); ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#results">Skip to the chart</a>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="./"><?php echo Html::e(\USDebt\Controller\USDebtController::SITE_TITLE); ?></a>
        <nav class="topnav" aria-label="Site">
            <a href="#terms">Compare terms</a>
            <a href="#about">About the data</a>
        </nav>
    </div>
</header>

<main class="wrap" id="main_content">
    <?php echo $notice; ?>
    <?php echo $cards; ?>
    <?php echo $controls; ?>

    <section class="card results" id="results" aria-labelledby="results-title" tabindex="-1">
        <h2 id="results-title" class="card-title"><?php echo Html::e($results_heading !== '' ? $results_heading : 'The debt over time'); ?></h2>
        <?php echo $results; ?>
    </section>
</main>

<footer class="site-footer" id="about">
    <div class="wrap">
        <h2>About the data</h2>
        <p>Numbers come from the U.S. Treasury's
            <a href="<?php echo Html::e($treasury_url); ?>" rel="noopener">Debt to the Penny</a> data,
            which is published for every business day. This site saves a fresh copy once a day.
            Because it uses reported figures, it can differ from debt clocks, which show real-time estimates.</p>
        <p>The code is open source on <a href="https://github.com/robguida/USDebt" rel="noopener">GitHub</a>.</p>
    </div>
</footer>
</body>
</html>
