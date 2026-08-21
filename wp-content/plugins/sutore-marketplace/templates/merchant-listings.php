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
?>
<div class="sutore-mp-listings wp-block-group" data-price-step="<?php echo esc_attr((string) $step); ?>">
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2 class="wp-block-heading"><?php esc_html_e('My products', 'sutore-marketplace'); ?></h2>
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

    <div class="sutore-mp-list-bulk-chrome" hidden>
        <div class="sutore-mp-list-bulk-bar" hidden>
            <span class="sutore-mp-list-bulk-count" aria-live="polite"></span>
            <label class="sutore-mp-list-bulk-action-label screen-reader-text" for="sutore-mp-list-bulk-action">
                <?php esc_html_e('Bulk actions', 'sutore-marketplace'); ?>
            </label>
            <select id="sutore-mp-list-bulk-action" class="sutore-mp-input sutore-mp-list-bulk-action" disabled>
                <option value=""><?php esc_html_e('Bulk actions', 'sutore-marketplace'); ?></option>
            </select>
            <button type="button" class="wp-element-button sutore-mp-list-bulk-apply" disabled>
                <?php esc_html_e('Apply', 'sutore-marketplace'); ?>
            </button>
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
                    <option value="order_detached"><?php esc_html_e('Detached from order / Could not be sourced', 'sutore-marketplace'); ?></option>
                    <option value="pre_order"><?php esc_html_e('Pre-order', 'sutore-marketplace'); ?></option>
                    <option value="in_sale"><?php esc_html_e('In sale / shipping', 'sutore-marketplace'); ?></option>
                    <option value="sale_ended"><?php esc_html_e('Sale ended', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-list-size"><?php esc_html_e('Variation', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-list-size" name="size_term_id" class="sutore-mp-input sutore-mp-list-size" aria-busy="true">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
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

                <label class="sutore-mp-field-label" for="sutore-mp-list-sourcing"><?php esc_html_e('Product source', 'sutore-marketplace'); ?></label>
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
                <p class="description"><?php esc_html_e('Campaign and pre-order filters can be combined — a product may be both.', 'sutore-marketplace'); ?></p>
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

    <?php
    $staff_create = false;
    include SUTORE_MARKETPLACE_PATH . 'templates/partials/listing-create-modals.php';
    ?>
</div>
