<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 8:29 PM
 */

$pres_nav = '';
foreach ($pres_array as $pres) {
    $pres_start = new DateTime($pres['start']);
    $pres_end = new DateTime($pres['end']);
    $pres_nav .= "<a href=\"?start_date={$pres_start->format('Y-m-d')}&end_date={$pres_end->format('Y-m-d')}\">" .
        "<img src=\"images/{$pres['img']}\" title=\"{$pres['pres']}\"></a>";
}
?>
<table>
    <tr>
        <td>
            <h2>Select a date range, or a President</h2>
            <form id="search" class="search">
                <label for="start_date">Start Date:</label>
                <input type="text" id="start_date" name="start_date" value="<?php echo $start_date; ?>" />
                <label for="end_date">End Date:</label>
                <input type="text" id="end_date" name="end_date" value="<?php echo $end_date; ?>" />
                <input type="submit" id="submit" name="submit" value="Fetch" />
            </form>
        </td>
        <td>&nbsp;</td>
        <td><div class="pres_nav">
                <?php echo $pres_nav; ?>
                <a href="index.php">
                    <img class="home" src="images/home.png" />
                </a>
            </div>
        </td>
    </tr>
</table>
<script type="text/javascript">
    $(function(){
        /* bindings */
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