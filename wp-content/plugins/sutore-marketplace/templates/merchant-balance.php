<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp sutore-mp-merchant-balance woocommerce">
    <section class="sutore-mp-merchant-balance__section sutore-mp-account-panel">
        <h2><?php esc_html_e('My Balance', 'sutore-marketplace'); ?></h2>
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('Your commission rate, pending and paid payouts, and recent payout movements.', 'sutore-marketplace'); ?>
        </p>
        <div class="sutore-mp-merchant-balance__root" data-rest-boot="1" aria-live="polite" aria-busy="true">
            <div class="sutore-mp-list-loading" role="status">
                <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
            </div>
        </div>
    </section>
</div>
