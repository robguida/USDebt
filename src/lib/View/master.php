<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/4/18
 * Time: 10:01 PM
 *
 * @var array{search_form: string, tab_data: string} $main_content  Already-rendered HTML.
 */

use USDebt\Service\Html;

/* build the head links; ?r= busts the browser cache when a file changes */
$public_dir = dirname(__DIR__, 2) . '/Public/';
$asset = static function (string $path) use ($public_dir): string {
    $mtime = @filemtime($public_dir . $path);
    return Html::e($path . ($mtime ? '?r=' . $mtime : ''));
};
$js_files = [
    'lib/jquery.js',
    'lib/jquery-ui/jquery-ui.min.js',
    'lib/Chart.bundle.min.js',
    'lib/flot-master/jquery.flot.js',
];
$css_files = [
    'lib/jquery-ui/jquery-ui.min.css',
    'lib/jquery-ui/jquery-ui.structure.min.css',
    'lib/jquery-ui/jquery-ui.theme.min.css',
    'css/usdebt.css',
];
$head = '';
foreach ($js_files as $file) {
    $head .= '<script src="' . $asset($file) . "\" type=\"text/javascript\"></script>\n";
}
foreach ($css_files as $file) {
    $head .= '<link rel="stylesheet" href="' . $asset($file) . "\" />\n";
}
?>
<html>
<head>
    <meta charset="utf-8">
    <title>Our U.S. Debt</title>
    <?php echo $head; ?>
</head>
<body>
<h1>Our U.S. Debt</h1>
<div id="about_usdebt" style="display: none;">
    <p>The data used for this site is freely accessible from the U.S. Treasury
        (<a href="https://fiscaldata.treasury.gov/datasets/debt-to-the-penny/" target="_blank" rel="noopener">Debt to the Penny</a>),
        which publishes the national debt every business day. Because the data is updated daily, it is different than the
        <a href="https://www.usdebtclock.org/" target="_blank" rel="noopener">National Debt Clock</a>, which uses an
        algorithm to show real-time, estimated, changes in the debt.
    </p>
</div>
<h4>Data is from the <a href="https://fiscaldata.treasury.gov/datasets/debt-to-the-penny/" target="_blank" rel="noopener">U.S. Treasury</a>
    <img src="images/about_usdebt.png" id="about_usdebt_btn" class="small_icon" alt="About this data" /></h4>
<h4>Code is opensource and can be found <a href="https://github.com/robguida/USDebt" target="usdebt_code">here</a>.</h4>
<div id="main_content">
    <?php
        echo $main_content['search_form'];
        echo $main_content['tab_data'];
    ?>
</div>
</body>
<script type="text/javascript">
    $(function() {
        $('#about_usdebt').dialog({
            autoOpen: false,
            modal: true,
            closeOnEscape: true,
            resizable: false,
            height: "auto"
        });
        $('#about_usdebt_btn').on('click', function() {
            $('#about_usdebt').dialog('open');
        });
    });
</script>
</html>
