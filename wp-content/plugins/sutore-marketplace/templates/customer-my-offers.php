<?php

/** Customer my offers — shell + REST. */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="sutore-mp-my-offers sutore-mp-account-panel">
    <div class="sutore-mp-list-chrome" hidden>
        <div class="sutore-mp-listings-header">
            <h2 class="wp-block-heading"><?php esc_html_e('My offers', 'sutore-marketplace'); ?></h2>
        </div>
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('Price offers you sent to sellers. If accepted, use the personal coupon at checkout.', 'sutore-marketplace'); ?>
        </p>
    </div>

    <div class="sutore-mp-my-offers-results sutore-mp-list-results" aria-live="polite" aria-busy="true">
        <div class="sutore-mp-list-loading" role="status">
            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </div>
    </div>
    <div class="sutore-mp-list-pager"></div>
</div>
