<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:17 PM
 *
 * @var list<\USDebt\Model\DebtEntry> $datas  Newest first (per person or household: already scaled).
 * @var string $header  Amount column: "Total debt", "Debt per person" or "Debt per household".
 * @var string $download_href  Root-relative link to the CSV of this same table (download.php).
 */

use USDebt\Service\Html;

$data = '';
foreach ($datas as $d) {
    $dateDt = new DateTimeImmutable($d->effectiveDate);
    $data .= '<tr><td class="date">' . Html::e($dateDt->format('M j, Y')) . '</td>'
        . '<td class="currency">' . Html::e(Html::money($d->totalDebt)) . '</td></tr>';
}
?>
<p class="table-download">
    <a class="btn btn-quiet" href="<?php echo Html::e($download_href); ?>" download>Download table (CSV)</a>
    <span class="hint">Opens in Excel, Numbers or Google Sheets.</span>
</p>
<div class="table-scroll" tabindex="0" role="region" aria-label="Daily debt table">
    <table class="data-table num">
        <caption class="sr-only"><?php echo Html::e($header); ?> for each reported day, newest first</caption>
        <thead><tr><th scope="col">Date</th><th scope="col" class="currency"><?php echo Html::e($header); ?></th></tr></thead>
        <tbody><?php echo $data; ?></tbody>
    </table>
</div>
