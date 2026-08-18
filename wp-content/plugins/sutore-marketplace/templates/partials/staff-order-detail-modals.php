<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sutore-mp-staff-order-detail-host sutore-mp-staff-orders">
<div class="sutore-mp-manage-overlay sutore-mp-staff-orders-overlay" hidden>
    <div
        class="sutore-mp-manage-modal sutore-mp-staff-orders-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sutore-mp-staff-orders-detail-title"
    >
        <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
        <div class="sutore-mp-manage-modal__head">
            <div class="sutore-mp-manage-modal__titles">
                <h2 id="sutore-mp-staff-orders-detail-title" class="sutore-mp-manage-modal__title sutore-mp-staff-detail-title"></h2>
                <p class="sutore-mp-manage-modal__sub sutore-mp-staff-detail-sub"></p>
            </div>
            <span class="sutore-mp-manage-modal__badge sutore-mp-staff-detail-badge" hidden></span>
            <button
                type="button"
                class="sutore-mp-manage-modal__close sutore-mp-staff-orders-close"
                aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
            >×</button>
        </div>

        <div class="sutore-mp-manage-modal__body sutore-mp-staff-detail-root" aria-busy="false">
            <div class="sutore-mp-manage-modal__loading sutore-mp-staff-manage-loading" role="status" aria-live="polite" hidden>
                <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
            </div>
            <div class="sutore-mp-staff-detail-panels"></div>
        </div>

        <div class="sutore-mp-manage-modal__foot sutore-mp-staff-orders-foot">
            <div class="sutore-mp-staff-foot-bar">
                <button
                    type="button"
                    class="wp-element-button is-style-outline sutore-mp-staff-orders-edit-cancel"
                    hidden
                >
                    <?php esc_html_e('Cancel', 'sutore-marketplace'); ?>
                </button>
                <button
                    type="button"
                    class="wp-element-button is-style-outline sutore-mp-staff-orders-edit-toggle"
                    hidden
                >
                    <?php esc_html_e('Update order', 'sutore-marketplace'); ?>
                </button>
                <label class="sutore-mp-staff-orders-status-field" hidden>
                    <span class="screen-reader-text"><?php esc_html_e('Status', 'sutore-marketplace'); ?></span>
                    <select class="sutore-mp-input sutore-mp-staff-orders-status-select" disabled></select>
                </label>
            </div>
        </div>
    </div>
</div>

<div class="sutore-mp-manage-overlay sutore-mp-staff-orders-swap-overlay" hidden>
    <div
        class="sutore-mp-manage-modal sutore-mp-staff-orders-swap-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sutore-mp-staff-orders-swap-title"
    >
        <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
        <div class="sutore-mp-manage-modal__head">
            <div class="sutore-mp-manage-modal__titles">
                <h2 id="sutore-mp-staff-orders-swap-title" class="sutore-mp-manage-modal__title">
                    <?php esc_html_e('Change product', 'sutore-marketplace'); ?>
                </h2>
                <p class="sutore-mp-manage-modal__sub sutore-mp-staff-orders-swap-sub"></p>
            </div>
            <button
                type="button"
                class="sutore-mp-manage-modal__close sutore-mp-staff-orders-swap-close"
                aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
            >×</button>
        </div>
        <div class="sutore-mp-manage-modal__body sutore-mp-staff-orders-swap-body">
            <div class="sutore-mp-staff-orders-swap-previews">
                <section class="sutore-mp-staff-orders-swap-card" data-role="current">
                    <h3 class="sutore-mp-staff-order-section-title"><?php esc_html_e('Current product', 'sutore-marketplace'); ?></h3>
                    <div class="sutore-mp-staff-orders-swap-preview sutore-mp-staff-orders-swap-current"></div>
                </section>
                <section class="sutore-mp-staff-orders-swap-card" data-role="replacement">
                    <h3 class="sutore-mp-staff-order-section-title"><?php esc_html_e('Replacement', 'sutore-marketplace'); ?></h3>
                    <div class="sutore-mp-staff-orders-swap-preview sutore-mp-staff-orders-swap-replacement">
                        <p class="sutore-mp-empty"><?php esc_html_e('Select a replacement product.', 'sutore-marketplace'); ?></p>
                    </div>
                </section>
            </div>
            <div class="sutore-mp-staff-orders-swap-diff" hidden></div>
            <p class="sutore-mp-staff-orders-modal-alert" role="alert" aria-live="assertive" hidden></p>
            <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-swap-search">
                <?php esc_html_e('Search replacement', 'sutore-marketplace'); ?>
            </label>
            <input
                type="search"
                id="sutore-mp-staff-orders-swap-search"
                class="sutore-mp-input sutore-mp-staff-orders-swap-search"
                placeholder="<?php esc_attr_e('Product, seller, variation ID…', 'sutore-marketplace'); ?>"
                autocomplete="off"
            />
            <div class="sutore-mp-staff-orders-swap-candidates" role="listbox" aria-label="<?php esc_attr_e('Replacement products', 'sutore-marketplace'); ?>"></div>
            <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-swap-note">
                <?php esc_html_e('Staff note', 'sutore-marketplace'); ?>
            </label>
            <textarea
                id="sutore-mp-staff-orders-swap-note"
                class="sutore-mp-input sutore-mp-staff-orders-reason sutore-mp-staff-orders-swap-note"
                rows="3"
                placeholder="<?php esc_attr_e('Required when replacing with a different product…', 'sutore-marketplace'); ?>"
            ></textarea>
            <label class="sutore-mp-staff-orders-check sutore-mp-staff-check">
                <input
                    type="checkbox"
                    class="sutore-mp-staff-orders-return-queue"
                    checked
                />
                <span><?php esc_html_e('Return detached product to the sale queue', 'sutore-marketplace'); ?></span>
            </label>
            <p class="sutore-mp-staff-orders-check-hint">
                <?php esc_html_e('If eligible, the winner algorithm may put it back on sale.', 'sutore-marketplace'); ?>
            </p>
        </div>
        <div class="sutore-mp-manage-modal__foot">
            <button type="button" class="wp-element-button is-style-outline sutore-mp-staff-orders-swap-close">
                <?php esc_html_e('Cancel', 'sutore-marketplace'); ?>
            </button>
            <button type="button" class="wp-element-button sutore-mp-staff-orders-swap-confirm" disabled>
                <?php esc_html_e('Confirm change', 'sutore-marketplace'); ?>
            </button>
        </div>
    </div>
</div>

<div class="sutore-mp-manage-overlay sutore-mp-staff-orders-detach-overlay" hidden>
    <div
        class="sutore-mp-manage-modal sutore-mp-staff-orders-detach-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sutore-mp-staff-orders-detach-title"
    >
        <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
        <div class="sutore-mp-manage-modal__head">
            <div class="sutore-mp-manage-modal__titles">
                <h2 id="sutore-mp-staff-orders-detach-title" class="sutore-mp-manage-modal__title">
                    <?php esc_html_e('Detach from order', 'sutore-marketplace'); ?>
                </h2>
                <p class="sutore-mp-manage-modal__sub sutore-mp-staff-orders-detach-sub"></p>
            </div>
            <button
                type="button"
                class="sutore-mp-manage-modal__close sutore-mp-staff-orders-detach-close"
                aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
            >×</button>
        </div>
        <div class="sutore-mp-manage-modal__body">
            <p class="sutore-mp-staff-orders-detach-text">
                <?php esc_html_e('This product will be unlinked from the order. Continue?', 'sutore-marketplace'); ?>
            </p>
            <p class="sutore-mp-staff-orders-modal-alert" role="alert" aria-live="assertive" hidden></p>
            <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-detach-note">
                <?php esc_html_e('Staff note', 'sutore-marketplace'); ?>
            </label>
            <textarea
                id="sutore-mp-staff-orders-detach-note"
                class="sutore-mp-input sutore-mp-staff-orders-reason sutore-mp-staff-orders-detach-note"
                rows="3"
                required
                placeholder="<?php esc_attr_e('Explain why this action is taken…', 'sutore-marketplace'); ?>"
            ></textarea>
            <label class="sutore-mp-staff-orders-check sutore-mp-staff-check">
                <input
                    type="checkbox"
                    class="sutore-mp-staff-orders-return-queue"
                    checked
                />
                <span><?php esc_html_e('Return detached product to the sale queue', 'sutore-marketplace'); ?></span>
            </label>
            <p class="sutore-mp-staff-orders-check-hint">
                <?php esc_html_e('If eligible, the winner algorithm may put it back on sale.', 'sutore-marketplace'); ?>
            </p>
        </div>
        <div class="sutore-mp-manage-modal__foot">
            <button type="button" class="wp-element-button is-style-outline sutore-mp-staff-orders-detach-close">
                <?php esc_html_e('Cancel', 'sutore-marketplace'); ?>
            </button>
            <button type="button" class="wp-element-button sutore-mp-staff-orders-detach-confirm">
                <?php esc_html_e('Detach', 'sutore-marketplace'); ?>
            </button>
        </div>
    </div>
</div>

<div class="sutore-mp-manage-overlay sutore-mp-staff-orders-attach-overlay" hidden>
    <div
        class="sutore-mp-manage-modal sutore-mp-staff-orders-attach-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sutore-mp-staff-orders-attach-title"
    >
        <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
        <div class="sutore-mp-manage-modal__head">
            <div class="sutore-mp-manage-modal__titles">
                <h2 id="sutore-mp-staff-orders-attach-title" class="sutore-mp-manage-modal__title sutore-mp-staff-orders-attach-title">
                    <?php esc_html_e('Add product', 'sutore-marketplace'); ?>
                </h2>
                <p class="sutore-mp-manage-modal__sub sutore-mp-staff-orders-attach-sub"></p>
            </div>
            <button
                type="button"
                class="sutore-mp-manage-modal__close sutore-mp-staff-orders-attach-close"
                aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
            >×</button>
        </div>
        <div class="sutore-mp-manage-modal__body sutore-mp-staff-orders-attach-body">
            <section class="sutore-mp-staff-orders-swap-card" data-role="selected" hidden>
                <h3 class="sutore-mp-staff-order-section-title"><?php esc_html_e('Selected product', 'sutore-marketplace'); ?></h3>
                <div class="sutore-mp-staff-orders-swap-preview sutore-mp-staff-orders-attach-selected"></div>
            </section>
            <p class="sutore-mp-staff-orders-modal-alert" role="alert" aria-live="assertive" hidden></p>
            <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-attach-search">
                <?php esc_html_e('Search product', 'sutore-marketplace'); ?>
            </label>
            <input
                type="search"
                id="sutore-mp-staff-orders-attach-search"
                class="sutore-mp-input sutore-mp-staff-orders-attach-search"
                placeholder="<?php esc_attr_e('Product, seller, variation ID…', 'sutore-marketplace'); ?>"
                autocomplete="off"
            />
            <div class="sutore-mp-staff-orders-attach-candidates" role="listbox" aria-label="<?php esc_attr_e('Products', 'sutore-marketplace'); ?>"></div>
            <div class="sutore-mp-staff-orders-attach-replace-opts" hidden>
                <label class="sutore-mp-staff-orders-check sutore-mp-staff-check">
                    <input
                        type="checkbox"
                        class="sutore-mp-staff-orders-return-queue"
                        checked
                    />
                    <span><?php esc_html_e('Return detached product to the sale queue', 'sutore-marketplace'); ?></span>
                </label>
                <p class="sutore-mp-staff-orders-check-hint">
                    <?php esc_html_e('If eligible, the winner algorithm may put it back on sale.', 'sutore-marketplace'); ?>
                </p>
            </div>
        </div>
        <div class="sutore-mp-manage-modal__foot">
            <button type="button" class="wp-element-button is-style-outline sutore-mp-staff-orders-attach-close">
                <?php esc_html_e('Cancel', 'sutore-marketplace'); ?>
            </button>
            <button type="button" class="wp-element-button sutore-mp-staff-orders-attach-confirm" disabled>
                <?php esc_html_e('Add product', 'sutore-marketplace'); ?>
            </button>
        </div>
    </div>
</div>

<div class="sutore-mp-manage-overlay sutore-mp-staff-orders-apply-overlay" hidden>
    <div
        class="sutore-mp-manage-modal sutore-mp-staff-orders-apply-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sutore-mp-staff-orders-apply-title"
    >
        <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
        <div class="sutore-mp-manage-modal__head">
            <div class="sutore-mp-manage-modal__titles">
                <h2 id="sutore-mp-staff-orders-apply-title" class="sutore-mp-manage-modal__title">
                    <?php esc_html_e('Confirm order update', 'sutore-marketplace'); ?>
                </h2>
                <p class="sutore-mp-manage-modal__sub">
                    <?php esc_html_e('Review the pending changes before applying them.', 'sutore-marketplace'); ?>
                </p>
            </div>
            <button
                type="button"
                class="sutore-mp-manage-modal__close sutore-mp-staff-orders-apply-close"
                aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
            >×</button>
        </div>
        <div class="sutore-mp-manage-modal__body">
            <ul class="sutore-mp-staff-orders-apply-summary"></ul>
        </div>
        <div class="sutore-mp-manage-modal__foot">
            <button type="button" class="wp-element-button is-style-outline sutore-mp-staff-orders-apply-close">
                <?php esc_html_e('Cancel', 'sutore-marketplace'); ?>
            </button>
            <button type="button" class="wp-element-button sutore-mp-staff-orders-apply-confirm">
                <?php esc_html_e('Confirm update', 'sutore-marketplace'); ?>
            </button>
        </div>
    </div>
</div>
</div>
