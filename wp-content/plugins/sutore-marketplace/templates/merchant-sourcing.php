<?php
/** Merchant sourcing (ön sipariş) — WooCommerce My Account */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp-sourcing sutore-mp-account-panel">
    <div class="sutore-mp-list-chrome" hidden>
        <div class="sutore-mp-listings-header">
            <h2 class="wp-block-heading"><?php esc_html_e('Pre-order', 'sutore-marketplace'); ?></h2>
            <div class="sutore-mp-header-actions">
                <?php
                $id = 'sutore-mp-sourcing-search';
                $input_class = 'sutore-mp-sourcing-search';
                $value = '';
                $placeholder = __('product code, name…', 'sutore-marketplace');
                $show_filter = false;
                include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
                ?>
            </div>
        </div>
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('Accept an open request to add it to your products. Accepted pre-orders are tracked under My products.', 'sutore-marketplace'); ?>
        </p>
    </div>
    <div class="sutore-mp-sourcing-results" aria-live="polite" aria-busy="true">
        <div class="sutore-mp-list-loading" role="status">
            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </div>
    </div>

    <div class="sutore-mp-sort-overlay" hidden>
        <form class="sutore-mp-sort-modal sutore-mp-sourcing-sort" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-sourcing-sort-title" action="#">
            <div class="sutore-mp-sort-head">
                <h2 id="sutore-mp-sourcing-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-sort-body">
                <label class="sutore-mp-field-label" for="sutore-mp-sourcing-orderby"><?php esc_html_e('Sort by', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-sourcing-orderby" name="orderby" class="sutore-mp-input">
                    <option value="default"><?php esc_html_e('Status priority', 'sutore-marketplace'); ?></option>
                    <option value="created_desc"><?php esc_html_e('Newest first', 'sutore-marketplace'); ?></option>
                    <option value="created_asc"><?php esc_html_e('Oldest first', 'sutore-marketplace'); ?></option>
                    <option value="price_desc"><?php esc_html_e('Price (high to low)', 'sutore-marketplace'); ?></option>
                    <option value="price_asc"><?php esc_html_e('Price (low to high)', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-sourcing-sort-clear';
            $apply_class = 'sutore-mp-sourcing-sort-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-manage-overlay sutore-mp-sourcing-overlay" hidden>
        <div
            class="sutore-mp-manage-modal sutore-mp-sourcing-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-sourcing-modal-title"
        >
            <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-manage-modal__head">
                <div class="sutore-mp-manage-modal__media" hidden></div>
                <div class="sutore-mp-manage-modal__titles">
                    <h2 id="sutore-mp-sourcing-modal-title" class="sutore-mp-manage-modal__title">
                        <?php esc_html_e('Pre-order', 'sutore-marketplace'); ?>
                    </h2>
                    <p class="sutore-mp-manage-modal__sub"></p>
                </div>
                <span class="sutore-mp-manage-modal__badge" hidden></span>
                <button
                    type="button"
                    class="sutore-mp-manage-modal__close sutore-mp-sourcing-close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>

            <div class="sutore-mp-manage-modal__body" aria-busy="false">
                <div class="sutore-mp-sourcing-modal-summary"></div>
                <div class="sutore-mp-sourcing-modal-accept" hidden></div>
            </div>

            <div class="sutore-mp-manage-modal__foot sutore-mp-sourcing-modal-foot" hidden>
                <button type="button" class="wp-element-button sutore-mp-sourcing-accept-submit" disabled>
                    <?php esc_html_e('Accept sale', 'sutore-marketplace'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
