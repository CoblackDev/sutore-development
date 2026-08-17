<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp sutore-mp-tasks-page woocommerce">
    <section class="sutore-mp-tasks-page__section sutore-mp-tasks sutore-mp-account-panel">
        <h2><?php esc_html_e('Opportunities', 'sutore-marketplace'); ?></h2>
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('Personal cards refresh each month. Skipping a card has no penalty — complete them to grow, recover, or engage.', 'sutore-marketplace'); ?>
        </p>
        <div class="sutore-mp-tasks-results" aria-live="polite" aria-busy="true">
            <div class="sutore-mp-list-loading" role="status">
                <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
            </div>
        </div>
    </section>
</div>
