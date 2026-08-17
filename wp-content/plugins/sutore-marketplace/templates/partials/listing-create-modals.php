<?php
/**
 * Create / bulk / size-price modals shared by merchant listings and staff manage.
 *
 * @var int  $step
 * @var bool $staff_create
 */
if (!defined('ABSPATH')) {
    exit;
}

use SutoreMarketplace\Shared\Settings\Settings;

if (!isset($step)) {
    $step = Settings::listingPriceStep();
}
$staff_create = !empty($staff_create);
?>
    <div class="sutore-mp-manage-overlay" hidden>
        <div
            class="sutore-mp-manage-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-manage-title"
        >
            <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-manage-modal__head">
                <div class="sutore-mp-manage-modal__media" hidden></div>
                <div class="sutore-mp-manage-modal__titles">
                    <h2 id="sutore-mp-manage-title" class="sutore-mp-manage-modal__title"></h2>
                    <p class="sutore-mp-manage-modal__sub"></p>
                </div>
                <span class="sutore-mp-manage-modal__badge" hidden></span>
                <button
                    type="button"
                    class="sutore-mp-manage-modal__close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>

            <div class="sutore-mp-manage-modal__tabs" role="tablist" hidden>
                <button
                    type="button"
                    class="sutore-mp-manage-tab"
                    role="tab"
                    data-tab="details"
                    aria-selected="true"
                ><?php esc_html_e('Details', 'sutore-marketplace'); ?></button>
                <button
                    type="button"
                    class="sutore-mp-manage-tab sutore-mp-manage-tab--prices"
                    role="tab"
                    data-tab="prices"
                    aria-selected="false"
                    hidden
                ><?php esc_html_e('Size price list', 'sutore-marketplace'); ?></button>
                <button
                    type="button"
                    class="sutore-mp-manage-tab"
                    role="tab"
                    data-tab="activity"
                    aria-selected="false"
                ><?php esc_html_e('Activity', 'sutore-marketplace'); ?></button>
            </div>

            <nav class="sutore-mp-create-wizard-steps" hidden aria-label="<?php esc_attr_e('Add product steps', 'sutore-marketplace'); ?>">
                <ol class="sutore-mp-create-wizard-list">
                    <li class="sutore-mp-create-wizard-step is-current" data-step="1">
                        <span class="sutore-mp-create-wizard-index" aria-hidden="true">1</span>
                        <span class="sutore-mp-create-wizard-label"><?php esc_html_e('Product', 'sutore-marketplace'); ?></span>
                    </li>
                    <li class="sutore-mp-create-wizard-step" data-step="2">
                        <span class="sutore-mp-create-wizard-index" aria-hidden="true">2</span>
                        <span class="sutore-mp-create-wizard-label sutore-mp-wizard-axis-step-label"><?php esc_html_e('Variation', 'sutore-marketplace'); ?></span>
                    </li>
                    <li class="sutore-mp-create-wizard-step" data-step="3">
                        <span class="sutore-mp-create-wizard-index" aria-hidden="true">3</span>
                        <span class="sutore-mp-create-wizard-label"><?php esc_html_e('Details', 'sutore-marketplace'); ?></span>
                    </li>
                    <li class="sutore-mp-create-wizard-step" data-step="4">
                        <span class="sutore-mp-create-wizard-index" aria-hidden="true">4</span>
                        <span class="sutore-mp-create-wizard-label"><?php esc_html_e('Price', 'sutore-marketplace'); ?></span>
                    </li>
                </ol>
            </nav>

            <div class="sutore-mp-manage-modal__body" aria-busy="true">
                <div class="sutore-mp-manage-modal__loading" role="status" aria-live="polite">
                    <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
                </div>
                <div class="sutore-mp-create-success" hidden>
                    <div class="sutore-mp-create-success__icon" aria-hidden="true">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.75"/>
                            <path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="sutore-mp-create-success__text" role="status" aria-live="polite"></p>
                </div>
                <div class="sutore-mp-manage-panel" data-panel="details" hidden>
                    <div class="sutore-mp-manage-summary"></div>
                    <div class="sutore-mp-manage-edit" hidden>
                        <?php
                        $asPage = true;
                        $pageListingId = 0;
                        $forceEditChrome = true;
                        include SUTORE_MARKETPLACE_PATH . 'templates/listing-form.php';
                        ?>
                    </div>
                </div>
                <div class="sutore-mp-manage-panel" data-panel="prices" hidden></div>
                <div class="sutore-mp-manage-panel" data-panel="activity" hidden></div>
            </div>

            <div class="sutore-mp-wizard-context" hidden>
                <div class="sutore-mp-wizard-context__media" hidden></div>
                <div class="sutore-mp-wizard-context__info">
                    <div class="sutore-mp-wizard-context__title"></div>
                    <div class="sutore-mp-wizard-context__meta"></div>
                    <div class="sutore-mp-wizard-context__seller" hidden></div>
                </div>
                <div class="sutore-mp-wizard-context__price" hidden></div>
            </div>

            <div class="sutore-mp-manage-modal__foot" hidden></div>
        </div>
    </div>

    <div class="sutore-mp-bulk-overlay" hidden>
        <div
            class="sutore-mp-bulk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-bulk-title"
            data-bulk-wizard-step="1"
        >
            <div class="sutore-mp-bulk-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-bulk-modal__head">
                <div class="sutore-mp-bulk-modal__titles">
                    <h2 id="sutore-mp-bulk-title" class="sutore-mp-bulk-modal__title">
                        <?php esc_html_e('Bulk upload', 'sutore-marketplace'); ?>
                    </h2>
                    <p class="sutore-mp-bulk-modal__sub"></p>
                </div>
                <button
                    type="button"
                    class="sutore-mp-bulk-modal__close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>

            <nav class="sutore-mp-bulk-wizard-steps" aria-label="<?php esc_attr_e('Bulk upload steps', 'sutore-marketplace'); ?>">
                <ol class="sutore-mp-bulk-wizard-list">
                    <li class="sutore-mp-bulk-wizard-step is-current" data-step="1">
                        <span class="sutore-mp-bulk-wizard-index" aria-hidden="true">1</span>
                        <span class="sutore-mp-bulk-wizard-label"><?php esc_html_e('Upload', 'sutore-marketplace'); ?></span>
                    </li>
                    <li class="sutore-mp-bulk-wizard-step" data-step="2">
                        <span class="sutore-mp-bulk-wizard-index" aria-hidden="true">2</span>
                        <span class="sutore-mp-bulk-wizard-label"><?php esc_html_e('Review', 'sutore-marketplace'); ?></span>
                    </li>
                </ol>
            </nav>


            <div class="sutore-mp-bulk-modal__body">
                <?php include SUTORE_MARKETPLACE_PATH . 'templates/partials/listing-bulk-form.php'; ?>
            </div>
            <div class="sutore-mp-bulk-modal__foot" hidden></div>
        </div>
    </div>

    <div class="sutore-mp-size-prices-overlay" hidden>
        <div
            class="sutore-mp-size-prices-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sutore-mp-size-prices-title"
        >
            <div class="sutore-mp-size-prices-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-size-prices-modal__head">
                <h2 id="sutore-mp-size-prices-title"><?php esc_html_e('Size price list', 'sutore-marketplace'); ?></h2>
                <button
                    type="button"
                    class="sutore-mp-size-prices-close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>
            <div class="sutore-mp-size-prices-modal__body">
                <div class="sutore-mp-competing-prices">
                    <p class="description">
                        <?php esc_html_e('Compare other listings for this size by queue position, price, condition, and shipping.', 'sutore-marketplace'); ?>
                    </p>
                    <p class="sutore-mp-competing-prices-locked sutore-mp-notice" hidden>
                        <?php esc_html_e('Confirmed or Premium seller level is required to view this list.', 'sutore-marketplace'); ?>
                    </p>
                    <p class="sutore-mp-competing-prices-empty sutore-mp-notice" hidden>
                        <?php esc_html_e('No other Listing for sale or in queue for this size.', 'sutore-marketplace'); ?>
                    </p>
                    <div class="sutore-mp-staff-table-wrap sutore-mp-competing-prices-table-wrap" hidden>
                        <table class="sutore-mp-staff-table sutore-mp-competing-prices-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Queue', 'sutore-marketplace'); ?></th>
                                    <th scope="col"><?php esc_html_e('Price', 'sutore-marketplace'); ?></th>
                                    <th scope="col"><?php esc_html_e('Condition', 'sutore-marketplace'); ?></th>
                                    <th scope="col"><?php esc_html_e('Shipping', 'sutore-marketplace'); ?></th>
                                    <th scope="col"><?php esc_html_e('Time remaining', 'sutore-marketplace'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
