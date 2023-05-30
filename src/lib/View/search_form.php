<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 8:29 PM
 */

$pres_nav = '';
if (!isset($compare_pres)) {
    $compare_pres = [];
}
foreach ($pres_array as $key => $pres) {
    $pres_start = new DateTime($pres['start']);
    $pres_end = new DateTime($pres['end']);
    $checked = in_array($key, $compare_pres) ? ' checked="checked"' : '';
    $pres_nav .= "<div class=\"pres\">
                    <div><a href=\"?start_date={$pres_start->format('Y-m-d')}" .
                            "&end_date={$pres_end->format('Y-m-d')}\">" .
                            "<img src=\"images/{$pres['img']}\" title=\"{$pres['pres']}\" " .
                                "id=\"{$pres['pres']}\"></a></div>" .
                    "<div><input type=\"checkbox\" name=\"compare_pres[]\" value=\"{$key}\"{$checked} /></div>" .
                "</div>";
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
                <input type="text" id="start_date" name="start_date" value="<?php echo $start_date; ?>" />
                <label for="end_date">End Date:</label>
                <input type="text" id="end_date" name="end_date" value="<?php echo $end_date; ?>" />
                <input type="submit" id="submit" name="submit" value="Fetch" />
            </form>
        </div>
    </div>
    <div class="pres_nav">
        <form id="compare" method="post">
            <div class="pres_header">
                <div class="top"><h2>Press a Prez!</h2></div>
                <div class="bottom"><input type="submit" value="Compare" id="submit" name="submit" /></div>
            </div>
            <?php echo $pres_nav; ?>
        </form>
    </div>
</div>
<script type="text/javascript">
    $(function(){
        /* bindings */
        $('#compare').on('submit', function() {
            var compare_pres = $("input[value='compare_pres']").val();
            //alert(('' == $("input[value='compare_pres']").val()));
            //return ('' == $("input[value='compare_pres']").val());
            $('#compare').submit();
        });
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
            minDate: new Date(1993, 1, 4),
        });
    });
</script>