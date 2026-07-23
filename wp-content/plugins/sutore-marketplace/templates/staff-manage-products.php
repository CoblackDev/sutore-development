<?php
if (!defined('ABSPATH')) {
    exit;
}

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Orders\Domain\StaffQueueFilter;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;

/**
 * Staff manage-products shell. List loads via REST; detail opens in manage modal.
 *
 * @var array{
 *   detail_id: int,
 *   search: string,
 *   status_filter: string,
 *   queue_filter: string,
 *   payout_status: string,
 *   orderby: string,
 *   page: int,
 *   base_url: string
 * } $view
 */

$baseUrl = (string) ($view['base_url'] ?? '');
$detailId = (int) ($view['detail_id'] ?? 0);
$search = (string) ($view['search'] ?? '');
$statusFilter = (string) ($view['status_filter'] ?? '');
$queueFilter = (string) ($view['queue_filter'] ?? '');
$payoutStatus = (string) ($view['payout_status'] ?? '');
$orderby = (string) ($view['orderby'] ?? 'id_desc');
$page = max(1, (int) ($view['page'] ?? 1));

$saleStatuses = array_merge(
    ListingStatus::market(),
    ListingStatus::saleActive(),
    ListingStatus::saleTerminal()
);
$campaignFilter = (string) ($view['campaign'] ?? '');
$sourcingFilter = (string) ($view['is_sourcing'] ?? '');
$shipmentTypeFilter = (string) ($view['shipment_type'] ?? '');
$importedFilter = (string) ($view['is_imported'] ?? '');
?>
<div
    class="sutore-mp sutore-mp-account-panel sutore-mp-staff-manage"
    data-open-listing-id="<?php echo esc_attr((string) $detailId); ?>"
>
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2><?php esc_html_e('Manage Products', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <?php
            $id = 'sutore-mp-staff-manage-search';
            $input_class = 'sutore-mp-staff-manage-search';
            $value = $search;
            $placeholder = __('Product, seller, order, listing ID…', 'sutore-marketplace');
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
            ?>
        </div>
    </div>

    <div
        class="sutore-mp-staff-list-root"
        data-base-url="<?php echo esc_url($baseUrl); ?>"
        data-search="<?php echo esc_attr($search); ?>"
        data-status="<?php echo esc_attr($statusFilter); ?>"
        data-queue="<?php echo esc_attr($queueFilter); ?>"
        data-payout-status="<?php echo esc_attr($payoutStatus); ?>"
        data-campaign="<?php echo esc_attr($campaignFilter); ?>"
        data-is-sourcing="<?php echo esc_attr($sourcingFilter); ?>"
        data-shipment-type="<?php echo esc_attr($shipmentTypeFilter); ?>"
        data-is-imported="<?php echo esc_attr($importedFilter); ?>"
        data-orderby="<?php echo esc_attr($orderby); ?>"
        data-page="<?php echo esc_attr((string) $page); ?>"
        data-per-page="30"
        aria-busy="true"
    >
        <p class="sutore-mp-staff-loading" role="status" aria-live="polite">
            <span class="sutore-mp-staff-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </p>
    </div>

    <div class="sutore-mp-filter-overlay" hidden>
        <form class="sutore-mp-filter-modal sutore-mp-staff-manage-filter" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-manage-filter-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-staff-manage-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-queue"><?php esc_html_e('Queue', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-queue" name="queue" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All queues', 'sutore-marketplace'); ?></option>
                    <?php foreach (StaffQueueFilter::labels() as $queueKey => $queueLabel) : ?>
                        <option value="<?php echo esc_attr($queueKey); ?>" <?php selected($queueFilter, $queueKey); ?>>
                            <?php echo esc_html($queueLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></label>
                <select
                    id="sutore-mp-staff-manage-status"
                    name="status"
                    class="sutore-mp-input"
                    <?php disabled($queueFilter !== ''); ?>
                >
                    <option value=""><?php esc_html_e('All statuses', 'sutore-marketplace'); ?></option>
                    <?php foreach ($saleStatuses as $statusKey) : ?>
                        <option value="<?php echo esc_attr($statusKey); ?>" <?php selected($statusFilter, $statusKey); ?>>
                            <?php echo esc_html(ListingStatus::label($statusKey)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-payout-status"><?php esc_html_e('Payment status', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-payout-status" name="payout_status" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All payment statuses', 'sutore-marketplace'); ?></option>
                    <option value="none" <?php selected($payoutStatus, 'none'); ?>><?php esc_html_e('Not created yet', 'sutore-marketplace'); ?></option>
                    <?php foreach (PayoutStatus::labels() as $payoutKey => $payoutLabel) : ?>
                        <option value="<?php echo esc_attr($payoutKey); ?>" <?php selected($payoutStatus, $payoutKey); ?>>
                            <?php echo esc_html($payoutLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-campaign"><?php esc_html_e('Campaign', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-campaign" name="campaign" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="none" <?php selected($campaignFilter, 'none'); ?>><?php esc_html_e('No campaign', 'sutore-marketplace'); ?></option>
                    <option value="offer" <?php selected($campaignFilter, 'offer'); ?>><?php esc_html_e('Campaign offer pending', 'sutore-marketplace'); ?></option>
                    <option value="active" <?php selected($campaignFilter, 'active'); ?>><?php esc_html_e('On campaign', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-sourcing"><?php esc_html_e('Pre-order', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-sourcing" name="is_sourcing" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="yes" <?php selected($sourcingFilter, 'yes'); ?>><?php esc_html_e('Pre-order products', 'sutore-marketplace'); ?></option>
                    <option value="no" <?php selected($sourcingFilter, 'no'); ?>><?php esc_html_e('Regular products', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-shipment-type"><?php esc_html_e('Shipment type', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-shipment-type" name="shipment_type" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All shipment types', 'sutore-marketplace'); ?></option>
                    <option value="none" <?php selected($shipmentTypeFilter, 'none'); ?>><?php esc_html_e('Not set', 'sutore-marketplace'); ?></option>
                    <?php foreach (ShipmentType::labels() as $shipKey => $shipLabel) : ?>
                        <option value="<?php echo esc_attr($shipKey); ?>" <?php selected($shipmentTypeFilter, $shipKey); ?>>
                            <?php echo esc_html($shipLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-imported"><?php esc_html_e('Imported', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-imported" name="is_imported" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <option value="yes" <?php selected($importedFilter, 'yes'); ?>><?php esc_html_e('Imported products', 'sutore-marketplace'); ?></option>
                    <option value="no" <?php selected($importedFilter, 'no'); ?>><?php esc_html_e('Non-imported products', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-manage-filter-clear';
            $apply_class = 'sutore-mp-staff-manage-filter-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-sort-overlay" hidden>
        <form class="sutore-mp-sort-modal sutore-mp-staff-manage-sort" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-manage-sort-title" action="#">
            <div class="sutore-mp-sort-head">
                <h2 id="sutore-mp-staff-manage-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-sort-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-manage-orderby"><?php esc_html_e('Sort by', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-manage-orderby" name="orderby" class="sutore-mp-input">
                    <option value="id_desc" <?php selected($orderby, 'id_desc'); ?>><?php esc_html_e('Newest first', 'sutore-marketplace'); ?></option>
                    <option value="id_asc" <?php selected($orderby, 'id_asc'); ?>><?php esc_html_e('Oldest first', 'sutore-marketplace'); ?></option>
                    <option value="deadline_asc" <?php selected($orderby, 'deadline_asc'); ?>><?php esc_html_e('Deadline soonest', 'sutore-marketplace'); ?></option>
                    <option value="deadline_desc" <?php selected($orderby, 'deadline_desc'); ?>><?php esc_html_e('Deadline latest', 'sutore-marketplace'); ?></option>
                    <option value="sold_at_desc" <?php selected($orderby, 'sold_at_desc'); ?>><?php esc_html_e('Sold date (newest)', 'sutore-marketplace'); ?></option>
                    <option value="sold_at_asc" <?php selected($orderby, 'sold_at_asc'); ?>><?php esc_html_e('Sold date (oldest)', 'sutore-marketplace'); ?></option>
                    <option value="status_asc" <?php selected($orderby, 'status_asc'); ?>><?php esc_html_e('Status A–Z', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-manage-sort-clear';
            $apply_class = 'sutore-mp-staff-manage-sort-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-manage-overlay sutore-mp-staff-manage-overlay" hidden>
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
