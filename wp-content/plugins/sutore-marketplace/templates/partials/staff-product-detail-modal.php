<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp-staff-product-detail-host sutore-mp-staff-manage">
    <div class="sutore-mp-manage-overlay sutore-mp-staff-manage-overlay sutore-mp-staff-product-detail-overlay" hidden>
        <div
            class="sutore-mp-manage-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-staff-manage-title"
        >
            <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-manage-modal__head">
                <div class="sutore-mp-manage-modal__media sutore-mp-staff-detail-media" hidden></div>
                <div class="sutore-mp-manage-modal__titles">
                    <h2 id="sutore-mp-staff-manage-title" class="sutore-mp-manage-modal__title sutore-mp-staff-detail-title"></h2>
                    <p class="sutore-mp-manage-modal__sub sutore-mp-staff-detail-sub"></p>
                </div>
                <span class="sutore-mp-manage-modal__badge sutore-mp-staff-detail-badge" hidden></span>
                <button
                    type="button"
                    class="sutore-mp-manage-modal__close sutore-mp-staff-manage-close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>

            <div class="sutore-mp-manage-modal__tabs sutore-mp-staff-detail-tabs" role="tablist">
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="details" aria-selected="true">
                    <?php esc_html_e('Details', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="shipping" aria-selected="false">
                    <?php esc_html_e('Shipping', 'sutore-marketplace'); ?>
                </button>
                <button type="button" class="sutore-mp-manage-tab" role="tab" data-tab="payment" aria-selected="false">
                    <?php esc_html_e('Payment', 'sutore-marketplace'); ?>
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

            <div class="sutore-mp-manage-modal__foot sutore-mp-staff-manage-foot" hidden>
                <div class="sutore-mp-staff-action-form" hidden></div>
                <div class="sutore-mp-staff-foot-bar">
                    <div class="sutore-mp-staff-foot-primary"></div>
                    <div class="sutore-mp-staff-more">
                        <button
                            type="button"
                            class="wp-element-button is-style-outline sutore-mp-staff-more-toggle"
                            aria-expanded="false"
                            aria-haspopup="true"
                            hidden
                        >
                            <?php esc_html_e('More actions', 'sutore-marketplace'); ?>
                        </button>
                        <div class="sutore-mp-staff-more-menu" role="menu" hidden></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
