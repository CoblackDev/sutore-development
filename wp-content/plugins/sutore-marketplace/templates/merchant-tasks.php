<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp sutore-mp-tasks-page woocommerce">
    <section class="sutore-mp-tasks-page__section sutore-mp-tasks sutore-mp-account-panel">
        <h2><?php esc_html_e('My Tasks', 'sutore-marketplace'); ?></h2>
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('As you complete your tasks, progress updates automatically and rewards are assigned.', 'sutore-marketplace'); ?>
        </p>
        <div class="sutore-mp-tasks-results" aria-live="polite" aria-busy="true">
            <div class="sutore-mp-list-loading" role="status">
                <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
            </div>
        </div>
        <h3 class="sutore-mp-section-title"><?php esc_html_e('Rewards', 'sutore-marketplace'); ?></h3>
        <div class="sutore-mp-rewards-results" aria-live="polite" aria-busy="true">
            <div class="sutore-mp-list-loading" role="status">
                <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
            </div>
        </div>
    </section>
</div>
