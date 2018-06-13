<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/4/18
 * Time: 10:01 PM
 */

/* build the head links */
$js_files = array(
    "lib/jquery.js?r=" . filemtime('lib/jquery.js'),
    "lib/jquery-ui/jquery-ui.min.js?r=" . filemtime('lib/jquery-ui/jquery-ui.min.js'),
    "lib/Chart.bundle.min.js?r=" . filemtime('lib/Chart.bundle.min.js'),
    "lib/flot-master/jquery.flot.js?r=" . filemtime('lib/flot-master/jquery.flot.js'),
);
$css_files = array(
    "lib/jquery-ui/jquery-ui.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.min.css'),
    "lib/jquery-ui/jquery-ui.structure.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.structure.min.css'),
    "lib/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.theme.min.css'),
    "css/usdebt.css?r=" . filemtime('css/usdebt.css')
);
$head = '';
foreach ($js_files as $file) {
    $head .= "<script src=\"{$file}\" type=\"text/javascript\"></script>\n";
}
foreach ($css_files as $file) {
    $head .= "<link rel=\"stylesheet\" href=\"{$file}\" />\n";
}
?>
<html>
<head>
    <?php echo $head; ?>
</head>
<body>
<h1>Our U.S. Debt</h1>
<div id="about_usdebt" style="display: none;">
    <p>The data used for this site is freely accessible from
        <a href="https://www.treasurydirect.gov/" target="_blank">treasurydirect.gov</a>,
        who publishes the national debt daily. Because the data is updated daily, it is different than the
        <a href="http://www.usdebtclock.org/" target="_blank">National Debt Clock</a>. According to
        <a href="http://zfacts.com/node/245" target="_blank">zFacts</a>, the Debt Clock gets its data from
        treasurydirect.com. In order for the Debt Clock to be accurate, it would need to update data daily,
        and then use an algorithm to show the real-time, estimated, changes in the debt.
    </p>
</div>
<h4>Data is from <a href="http://www.treasurydirect.gov" target="_blank">treasurydirect.gov</a>
    <img src="images/about_usdebt.png" id="about_usdebt_btn" class="small_icon" /></h4>
<div id="main_content">
    <?php
        echo $main_content['search_form'];
        echo $main_content['tab_data'];
    ?>
</div>
</body>
<footer>
</footer>
<script type="text/javascript">
    $(function() {
        console.log('jquery loaded');
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
