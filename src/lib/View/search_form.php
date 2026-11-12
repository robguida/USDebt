<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 8:29 PM
 *
 * Date-range controls and the presidential term strip.
 *
 * @var array<int, array{pres: string, start: string, end: string, img: string}> $pres_array
 * @var string    $start_date  Y-m-d or ''.
 * @var string    $end_date    Y-m-d or ''.
 * @var list<int> $compare_pres
 * @var list<array{label: string, title: string, start: string, end: string, current: bool}> $quick_ranges
 * @var string    $today       Y-m-d.
 */

use USDebt\Service\Html;

$range_href = static fn (string $start, string $end): string
    => '?' . http_build_query(['start_date' => $start, 'end_date' => $end]) . '#results';
?>
<section class="card controls" aria-labelledby="controls-title">
    <h2 id="controls-title" class="card-title">Pick a date range</h2>
    <form id="search" class="range-form" method="get" action="./#results">
        <div class="field">
            <label for="start_date">Start date</label>
            <input type="date" id="start_date" name="start_date" min="1993-01-04" max="<?php echo Html::e($today); ?>"
                   value="<?php echo Html::e($start_date); ?>" required>
        </div>
        <div class="field">
            <label for="end_date">End date</label>
            <input type="date" id="end_date" name="end_date" min="1993-01-04" max="<?php echo Html::e($today); ?>"
                   value="<?php echo Html::e($end_date); ?>" required>
        </div>
        <button type="submit" class="btn btn-primary">Show range</button>
    </form>
    <nav class="chips" aria-label="Quick ranges">
        <?php foreach ($quick_ranges as $range) { ?>
        <a class="chip" href="<?php echo Html::e($range_href($range['start'], $range['end'])); ?>"
           title="<?php echo Html::e($range['title']); ?>"<?php echo $range['current'] ? ' aria-current="true"' : ''; ?>><?php echo Html::e($range['label']); ?></a>
        <?php } ?>
    </nav>
</section>

<section class="card terms" id="terms" aria-labelledby="terms-title">
    <div class="card-head">
        <h2 id="terms-title" class="card-title">View by presidential term</h2>
        <button type="button" class="btn btn-quiet" id="select_all" hidden>Select all</button>
    </div>
    <p class="caption">Congress sets spending and taxes. Presidential terms are shown for reference only.</p>
    <form id="compare" method="post" action="./#results">
        <ul class="term-strip">
            <?php foreach ($pres_array as $key => $pres) {
                $pres_start = new DateTimeImmutable($pres['start']);
                $pres_end = new DateTimeImmutable($pres['end']);
                $link_end = min($pres_end->format('Y-m-d'), $today);
                $years = $pres_start->format('Y') . "\u{2013}" . $pres_end->format('Y');
                $checked = in_array($key, $compare_pres, true) ? ' checked="checked"' : '';
                $box_id = 'pres_' . $key;
                ?>
            <li class="term">
                <a class="term-link" href="<?php echo Html::e($range_href($pres_start->format('Y-m-d'), $link_end)); ?>">
                    <img src="images/<?php echo Html::e($pres['img']); ?>" alt="" width="56" height="72" loading="lazy">
                    <span class="term-name"><?php echo Html::e($pres['pres']); ?></span>
                    <span class="term-years num"><?php echo Html::e($years); ?></span>
                </a>
                <label class="term-pick" for="<?php echo Html::e($box_id); ?>">
                    <input type="checkbox" id="<?php echo Html::e($box_id); ?>" name="compare_pres[]" value="<?php echo Html::e($key); ?>"<?php echo $checked; ?>>
                    Compare<span class="sr-only"> <?php echo Html::e($pres['pres'] . ' ' . $years); ?></span>
                </label>
            </li>
            <?php } ?>
        </ul>
        <div class="form-actions">
            <p class="hint">Tap a term to see its dates. Tick two or more to compare them.</p>
            <button type="submit" class="btn btn-primary" id="compare_submit" name="submit" value="Compare">Compare selected</button>
        </div>
    </form>
</section>
