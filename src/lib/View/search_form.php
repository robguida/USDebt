<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 8:29 PM
 *
 * @var array<int, array{pres: string, start: string, end: string, img: string}> $pres_array
 * @var string    $start_date
 * @var string    $end_date
 * @var list<int> $compare_pres
 */

use USDebt\Service\Html;

$pres_nav = '';
foreach ($pres_array as $key => $pres) {
    $pres_start = new DateTimeImmutable($pres['start']);
    $pres_end = new DateTimeImmutable($pres['end']);
    $checked = in_array($key, $compare_pres, true) ? ' checked="checked"' : '';
    $href = '?' . http_build_query(['start_date' => $pres_start->format('Y-m-d'), 'end_date' => $pres_end->format('Y-m-d')]);
    $title = $pres['pres'] . ' (' . $pres_start->format('Y') . '-' . $pres_end->format('Y') . ')';
    $pres_nav .= '<div class="pres">'
        . '<div><a href="' . Html::e($href) . '">'
        . '<img src="images/' . Html::e($pres['img']) . '" title="' . Html::e($title) . '" alt="' . Html::e($title) . '"></a></div>'
        . '<div><input type="checkbox" name="compare_pres[]" value="' . Html::e($key) . '"' . $checked . ' /></div>'
        . '</div>';
}
?>
<div class="header">
    <div class="search_form">
        <div class="search_form_top">
            <h2>Select a date range</h2>
        </div>
        <div class="search_form_bottom">
            <form id="search" class="search">
                <label for="start_date">Start Date:</label>
                <input type="text" id="start_date" name="start_date" value="<?php echo Html::e($start_date); ?>" />
                <label for="end_date">End Date:</label>
                <input type="text" id="end_date" name="end_date" value="<?php echo Html::e($end_date); ?>" />
                <input type="submit" id="submit" name="submit" value="Fetch" />
            </form>
        </div>
    </div>
    <div class="pres_nav">
        <form id="compare" method="post">
            <div class="pres_header">
                <div class="top"><h2>Press a Prez!<p class="stats_footer">Click a picture to see data for a president.</p> </h2></div>
                <div class="bottom"><input type="submit" value="Compare" id="compare_submit" name="submit" /></div>
            </div>
            <?php echo $pres_nav; ?>
        </form>
    </div>
</div>
<script type="text/javascript">
    $(function(){
        $('#start_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
            minDate: new Date(1993, 0, 4),
        });
        $('#end_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
            minDate: new Date(1993, 0, 4),
        });
    });
</script>
