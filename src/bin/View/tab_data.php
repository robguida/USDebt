<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:17 PM
 */
$data = '';

foreach ($datas->entries as $d) {
    $dateDt = new DateTime($d->effectiveDate);
    $debt_amount = '$' . number_format($d->totalDebt, 2);
    $data .= "<tr><td class=\"date\">{$dateDt->format('m/d/Y')}</td>" .
        "<td class=\"currency\">{$debt_amount}</td></tr>";
}
?>
<table class="main_content">
    <thead><tr><th>Date</th><th>Total Debt</th></tr></thead>
    <tbody><?php echo $data; ?></tbody>
    <tfoot></tfoot>
</table>
load