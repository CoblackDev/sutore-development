<?php
/**
 * Merchant listings list shell — cards/pager load via GET /listings.
 *
 * @var int $step
 */
if (!defined('ABSPATH')) {
    exit;
}

use SutoreMarketplace\Shared\Settings\Settings;

if (!isset($step)) {
    $step = Settings::listingPriceStep();
}

$sizeTerms = get_terms([
    'taxonomy' => 'pa_beden-numara',
    'hide_empty' => false,
]);
if (is_wp_error($sizeTerms)) {
    $sizeTerms = [];
}
?>
<div class="sutore-mp-listings wp-block-group" data-price-step="<?php echo esc_attr((string) $step); ?>">
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2 class="wp-block-heading"><?php esc_html_e('My Listings', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <?php
            $id = 'sutore-mp-list-search';
            $input_class = 'sutore-mp-list-search';
            $value = '';
            $placeholder = __('product code, name, ID…', 'sutore-marketplace');
            $show_listing_actions = true;
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
            ?>
        </div>
    </div>

    <div class="sutore-mp-list-results" aria-busy="true">
        <div class="sutore-mp-list-loading" role="status" aria-live="polite">
            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </div>
    </div>
    <div class="sutore-mp-list-pager"></div>

    <div class="sutore-mp-filter-overlay" hidden>
        <form class="sutore-mp-filter-modal" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-filter-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-list-status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-status" name="status" class="sutore-mp-input sutore-mp-list-status">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="winner"><?php esc_html_e('For sale (winner)', 'sutore-marketplace'); ?></option>
                    <option value="queued"><?php esc_html_e('In queue', 'sutore-marketplace'); ?></option>
                    <option value="pending"><?php esc_html_e('Awaiting approval', 'sutore-marketplace'); ?></option>
                    <option value="expired"><?php esc_html_e('Expired', 'sutore-marketplace'); ?></option>
                    <option value="not_sale"><?php esc_html_e('Not for sale', 'sutore-marketplace'); ?></option>
                    <option value="in_sale"><?php esc_html_e('In sale / fulfillment', 'sutore-marketplace'); ?></option>
                    <option value="sale_ended"><?php esc_html_e('Sale ended', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-size"><?php esc_html_e('Size', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-size" name="size_term_id" class="sutore-mp-input sutore-mp-list-size">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <?php foreach ($sizeTerms as $term) : ?>
                        <option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-condition"><?php esc_html_e('Condition', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-condition" name="condition_key" class="sutore-mp-input sutore-mp-list-condition">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="no_box"><?php esc_html_e('No box', 'sutore-marketplace'); ?></option>
                    <option value="box_damaged"><?php esc_html_e('Box damaged', 'sutore-marketplace'); ?></option>
                    <option value="missing_accessory"><?php esc_html_e('Missing accessory', 'sutore-marketplace'); ?></option>
                    <option value="damaged"><?php esc_html_e('Damaged', 'sutore-marketplace'); ?></option>
                    <option value="fast_shipment"><?php esc_html_e('Fast shipping', 'sutore-marketplace'); ?></option>
                    <option value="has_invoice"><?php esc_html_e('International shipping', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-campaign"><?php esc_html_e('Campaign', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-campaign" name="campaign" class="sutore-mp-input sutore-mp-list-campaign">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="none"><?php esc_html_e('No campaign', 'sutore-marketplace'); ?></option>
                    <option value="offer"><?php esc_html_e('Campaign offer pending', 'sutore-marketplace'); ?></option>
                    <option value="active"><?php esc_html_e('On campaign', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-sourcing"><?php esc_html_e('Listing source', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-sourcing" name="is_sourcing" class="sutore-mp-input sutore-mp-list-sourcing">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="yes"><?php esc_html_e('Pre-order products', 'sutore-marketplace'); ?></option>
                    <option value="no"><?php esc_html_e('Regular products', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-imported"><?php esc_html_e('Imported', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-imported" name="is_imported" class="sutore-mp-input sutore-mp-list-imported">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="yes"><?php esc_html_e('Imported products', 'sutore-marketplace'); ?></option>
                    <option value="no"><?php esc_html_e('Non-imported products', 'sutore-marketplace'); ?></option>
                </select>
                <p class="description"><?php esc_html_e('Campaign and pre-order filters can be combined — a listing may be both.', 'sutore-marketplace'); ?></p>
            </div>
            <?php
            $clear_class = 'sutore-mp-list-clear';
            $apply_class = 'sutore-mp-list-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-sort-overlay" hidden>
        <form class="sutore-mp-sort-modal" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-sort-title" action="#">
            <div class="sutore-mp-sort-head">
                <h2 id="sutore-mp-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-sort-body">
                <label class="sutore-mp-field-label" for="sutore-mp-list-orderby"><?php esc_html_e('Sorting', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-orderby" name="orderby" class="sutore-mp-input sutore-mp-list-orderby">
                    <option value="created_at"><?php esc_html_e('Date', 'sutore-marketplace'); ?></option>
                    <option value="asking"><?php esc_html_e('Price', 'sutore-marketplace'); ?></option>
                    <option value="title"><?php esc_html_e('Name', 'sutore-marketplace'); ?></option>
                    <option value="expire_at"><?php esc_html_e('Time remaining', 'sutore-marketplace'); ?></option>
                    <option value="listing_status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></option>
                    <option value="queue_position"><?php esc_html_e('Queue', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-order"><?php esc_html_e('Direction', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-order" name="order" class="sutore-mp-input sutore-mp-list-order">
                    <option value="DESC"><?php esc_html_e('Descending / Newest', 'sutore-marketplace'); ?></option>
                    <option value="ASC"><?php esc_html_e('Ascending / Oldest', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-list-sort-clear';
            $apply_class = 'sutore-mp-list-sort-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

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
                        <span class="sutore-mp-create-wizard-label"><?php esc_html_e('Size', 'sutore-marketplace'); ?></span>
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
</div>
