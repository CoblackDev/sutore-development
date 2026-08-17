<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp-staff-merchant-detail-host sutore-mp-staff-merchants">
    <div class="sutore-mp-manage-overlay sutore-mp-staff-merchant-detail-overlay" hidden>
        <div
            class="sutore-mp-manage-modal sutore-mp-staff-merchants-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-staff-merchants-detail-title"
        >
            <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-manage-modal__head">
                <div class="sutore-mp-manage-modal__titles">
                    <h2 id="sutore-mp-staff-merchants-detail-title" class="sutore-mp-manage-modal__title sutore-mp-staff-detail-title"></h2>
                    <p class="sutore-mp-manage-modal__sub sutore-mp-staff-detail-sub"></p>
                </div>
                <span class="sutore-mp-manage-modal__badge sutore-mp-staff-detail-badge" hidden></span>
                <button
                    type="button"
                    class="sutore-mp-manage-modal__close sutore-mp-staff-merchants-close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>

            <div class="sutore-mp-manage-modal__tabs sutore-mp-staff-detail-tabs" role="tablist">
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="profile" aria-selected="true">
                    <?php esc_html_e('Profile', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="level" aria-selected="false">
                    <?php esc_html_e('Level', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="commission" aria-selected="false">
                    <?php esc_html_e('Commission', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="restrictions" aria-selected="false">
                    <?php esc_html_e('Restrictions', 'sutore-marketplace'); ?>
                    <span class="sutore-mp-staff-tab-badge" hidden></span>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="payouts" aria-selected="false">
                    <?php esc_html_e('Payouts', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="activity" aria-selected="false">
                    <?php esc_html_e('Activity', 'sutore-marketplace'); ?>
                </button>
            </div>

            <div class="sutore-mp-manage-modal__body sutore-mp-staff-detail-root" aria-busy="false">
                <div class="sutore-mp-manage-modal__loading sutore-mp-staff-manage-loading" role="status" aria-live="polite" hidden>
                    <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
                </div>
                <div class="sutore-mp-staff-detail-panels"></div>
            </div>
        </div>
    </div>
</div>
