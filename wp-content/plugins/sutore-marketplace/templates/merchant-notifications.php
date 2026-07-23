<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp sutore-mp-notifications sutore-mp-account-panel woocommerce">
    <div class="sutore-mp-notifications__header sutore-mp-listings-header">
        <h2><?php esc_html_e('Notifications', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <button type="button" class="wp-element-button is-style-outline sutore-mp-notifications__mark-all" hidden>
                <?php esc_html_e('Mark all as read', 'sutore-marketplace'); ?>
            </button>
        </div>
    </div>
    <div class="sutore-mp-notifications__list" data-unread="0" aria-busy="true">
        <div class="sutore-mp-list-loading" role="status" aria-live="polite">
            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </div>
    </div>
    <div class="sutore-mp-notifications__pager" hidden></div>
</div>
