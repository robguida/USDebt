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
 * @var bool   $trend_toggle     Show the "Trend line" button (the chart has a trend line).
 * @var bool   $show_trend       Trend line on (false when the URL says trend=0).
 * @var array{title: string, description: string, url: string, image: string, image_alt: string} $share
 *        Link preview tags: plain text and absolute links from the configured site address.
 * @var string $share_menu       Already-rendered HTML (Share button and menu, share_menu.php) or ''.
 */

use USDebt\Service\Html;

/* ?r= busts the browser cache when a file changes */
$public_dir = dirname(__DIR__, 2) . '/Public/';
$asset = static function (string $path) use ($public_dir): string {
    $mtime = @filemtime($public_dir . $path);
    return Html::e($path . ($mtime ? '?r=' . $mtime : ''));
};
$treasury_url = 'https://fiscaldata.treasury.gov/datasets/debt-to-the-penny/';
/* links on the page keep the trend switch; the page script updates them when it changes */
$home_href = $show_trend ? './' : './?trend=0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?php echo Html::e($title); ?></title>
    <meta name="description" content="<?php echo Html::e(\USDebt\Service\ShareText::SITE_DESCRIPTION); ?>">
    <?php /* link preview for social sites and chat apps (Open Graph, Twitter) */ ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo Html::e(\USDebt\Service\ShareText::SITE_NAME); ?>">
    <meta property="og:title" content="<?php echo Html::e($share['title']); ?>">
    <meta property="og:description" content="<?php echo Html::e($share['description']); ?>">
    <meta property="og:url" content="<?php echo Html::e($share['url']); ?>">
    <meta property="og:image" content="<?php echo Html::e($share['image']); ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?php echo Html::e($share['image_alt']); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo Html::e($share['title']); ?>">
    <meta name="twitter:description" content="<?php echo Html::e($share['description']); ?>">
    <meta name="twitter:image" content="<?php echo Html::e($share['image']); ?>">
    <meta name="twitter:image:alt" content="<?php echo Html::e($share['image_alt']); ?>">
    <link rel="icon" href="<?php echo $asset('favicon.svg'); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo $asset('css/usdebt.css'); ?>">
    <script src="<?php echo $asset('lib/chart.umd.min.js'); ?>" defer></script>
    <script src="<?php echo $asset('js/usdebt.js'); ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#results">Skip to the chart</a>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="<?php echo Html::e($home_href); ?>" data-trend-link><?php echo Html::e(\USDebt\Controller\USDebtController::SITE_TITLE); ?></a>
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
        <div class="card-head">
            <h2 id="results-title" class="card-title"><?php echo Html::e($results_heading !== '' ? $results_heading : 'The debt over time'); ?></h2>
            <?php if ($trend_toggle || $share_menu !== '') { ?>
            <div class="card-actions">
                <?php if ($trend_toggle) { ?>
                <?php /* hidden until the page script runs: without it there is no chart to switch */ ?>
                <button type="button" class="btn btn-quiet btn-toggle" id="trend_toggle" aria-pressed="<?php echo $show_trend ? 'true' : 'false'; ?>" hidden>Trend line<span class="toggle-state" aria-hidden="true">: <?php echo $show_trend ? 'On' : 'Off'; ?></span></button>
                <?php } ?>
                <?php echo $share_menu; ?>
            </div>
            <?php } ?>
        </div>
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
