<?php
/**
 * Share button and menu, in the chart card's header.
 *
 * Works without JavaScript: <details> opens and closes by itself, the page
 * link sits in a read-only box to copy by hand, and every share option is a
 * plain link (no scripts or SDKs from the social sites). The page script adds
 * "Copy link", the phone's own share sheet, Esc and click-outside to close,
 * and keeps every link in step with the trend switch.
 *
 * @var string $url            The page link to share (same as og:url, absolute).
 * @var string $card_href      The picture card on this site, relative (card.php?...).
 * @var bool   $card_trend     The card changes with the trend switch.
 * @var string $download_name  File name for "Download picture".
 * @var string $image_alt      Alt text for the card preview.
 * @var string $share_text     Share text without the link.
 * @var string $email_subject  Email subject.
 * @var string $email_note     Last line of the email.
 * @var array{x: string, facebook: string, linkedin: string, reddit: string, email: string} $networks  Share links.
 */

use USDebt\Service\Html;

$network_labels = [
    'x' => 'Share to X',
    'facebook' => 'Share to Facebook',
    'linkedin' => 'Share to LinkedIn',
    'reddit' => 'Share to Reddit',
    'email' => 'Share by email',
];
?>
<details class="share" id="share_menu"
         data-card="<?php echo Html::e($card_href); ?>"
         data-card-trend="<?php echo $card_trend ? '1' : '0'; ?>"
         data-text="<?php echo Html::e($share_text); ?>"
         data-subject="<?php echo Html::e($email_subject); ?>"
         data-note="<?php echo Html::e($email_note); ?>">
    <summary class="btn btn-quiet share-button">Share</summary>
    <div class="share-panel" role="group" aria-labelledby="share_title">
        <p class="share-title" id="share_title">Share this view</p>
        <?php /* phones only, shown by the page script when the system share sheet exists */ ?>
        <button type="button" class="btn btn-primary share-native" id="share_native" hidden>Share with an app</button>
        <img class="share-thumb" id="share_thumb" src="<?php echo Html::e($card_href); ?>" width="1200" height="630" loading="lazy" decoding="async" alt="<?php echo Html::e($image_alt); ?>">
        <div class="share-copy">
            <label class="sr-only" for="share_url">Link to this view</label>
            <input type="text" class="share-url" id="share_url" value="<?php echo Html::e($url); ?>" readonly spellcheck="false">
            <button type="button" class="btn btn-quiet" id="share_copy" hidden>Copy link</button>
        </div>
        <p class="share-status" id="share_status" role="status"></p>
        <ul class="share-list">
            <li><a class="share-item" id="share_download" href="<?php echo Html::e($card_href); ?>" download="<?php echo Html::e($download_name); ?>">Download picture</a></li>
            <?php foreach ($network_labels as $key => $label) { ?>
            <?php if ($key === 'email') { ?>
            <li><a class="share-item" data-share="email" href="<?php echo Html::e($networks['email']); ?>"><?php echo Html::e($label); ?></a></li>
            <?php } else { ?>
            <li><a class="share-item" data-share="<?php echo Html::e($key); ?>" href="<?php echo Html::e($networks[$key]); ?>" target="_blank" rel="noopener noreferrer"><?php echo Html::e($label); ?><span class="sr-only"> (opens in a new tab)</span></a></li>
            <?php } ?>
            <?php } ?>
        </ul>
    </div>
</details>
