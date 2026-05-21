<?php
/**
 * Modal for viewing the last job output excerpt. JS populates it before opening.
 */
?>
<div class="cra-modal" id="craOutputModal" role="dialog" aria-modal="true" aria-labelledby="craOutputModalTitle" hidden>
    <div class="cra-modal-backdrop cra-output-modal-close"></div>
    <div class="cra-modal-dialog cra-modal-dialog--lg">
        <div class="cra-modal-header">
            <h5 class="cra-modal-title" id="craOutputModalTitle"><?= htmlspecialchars(__('TEXT_CRON_VIEW_LAST_OUTPUT'), ENT_QUOTES, 'UTF-8') ?></h5>
            <button type="button" class="cra-modal-close cra-output-modal-close" aria-label="<?= htmlspecialchars(__('TEXT_BUTTON_CLOSE'), ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="cra-modal-body">
            <pre class="cra-output-pre" id="craOutputContent"></pre>
        </div>
        <div class="cra-modal-footer">
            <button type="button" class="cra-btn cra-btn--outline cra-output-modal-close"><?= htmlspecialchars(__('TEXT_BUTTON_CLOSE'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>
