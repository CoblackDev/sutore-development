<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staff manage-orders shell. List loads via REST; detail / swap / detach in modals.
 *
 * @var array{
 *   detail_id: int,
 *   search: string,
 *   status_filter: string,
 *   orderby: string,
 *   page: int,
 *   base_url: string,
 *   status_labels: array<string, string>
 * } $view
 */

$baseUrl = (string) ($view['base_url'] ?? '');
$detailId = (int) ($view['detail_id'] ?? 0);
$search = (string) ($view['search'] ?? '');
$statusFilter = (string) ($view['status_filter'] ?? '');
$orderby = (string) ($view['orderby'] ?? 'date_desc');
$page = max(1, (int) ($view['page'] ?? 1));
$statusLabels = is_array($view['status_labels'] ?? null) ? $view['status_labels'] : [];
?>
<div
    class="sutore-mp sutore-mp-account-panel sutore-mp-staff-manage sutore-mp-staff-orders"
    data-open-order-id="<?php echo esc_attr((string) $detailId); ?>"
>
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2><?php esc_html_e('Manage Orders', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <?php
            $id = 'sutore-mp-staff-orders-search';
            $input_class = 'sutore-mp-staff-orders-search';
            $value = $search;
            $placeholder = __('Order ID, customer name, email…', 'sutore-marketplace');
            $show_listing_actions = false;
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
            ?>
        </div>
    </div>

    <div
        class="sutore-mp-staff-list-root sutore-mp-staff-orders-list-root"
        data-base-url="<?php echo esc_url($baseUrl); ?>"
        data-search="<?php echo esc_attr($search); ?>"
        data-status="<?php echo esc_attr($statusFilter); ?>"
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
        <form class="sutore-mp-filter-modal sutore-mp-staff-orders-filter" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-orders-filter-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-staff-orders-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-orders-status" name="status" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All statuses', 'sutore-marketplace'); ?></option>
                    <?php foreach ($statusLabels as $statusKey => $statusLabel) :
                        $normalized = str_starts_with((string) $statusKey, 'wc-')
                            ? substr((string) $statusKey, 3)
                            : (string) $statusKey;
                        ?>
                        <option value="<?php echo esc_attr($normalized); ?>" <?php selected($statusFilter, $normalized); ?>>
                            <?php echo esc_html((string) $statusLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-orders-filter-clear';
            $apply_class = 'sutore-mp-staff-orders-filter-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-sort-overlay" hidden>
        <form class="sutore-mp-sort-modal sutore-mp-staff-orders-sort" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-orders-sort-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-staff-orders-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-orders-orderby"><?php esc_html_e('Sort by', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-orders-orderby" name="orderby" class="sutore-mp-input">
                    <option value="date_desc" <?php selected($orderby, 'date_desc'); ?>><?php esc_html_e('Newest first', 'sutore-marketplace'); ?></option>
                    <option value="date_asc" <?php selected($orderby, 'date_asc'); ?>><?php esc_html_e('Oldest first', 'sutore-marketplace'); ?></option>
                    <option value="deadline_asc" <?php selected($orderby, 'deadline_asc'); ?>><?php esc_html_e('Delivery deadline (soonest)', 'sutore-marketplace'); ?></option>
                    <option value="deadline_desc" <?php selected($orderby, 'deadline_desc'); ?>><?php esc_html_e('Delivery deadline (latest)', 'sutore-marketplace'); ?></option>
                    <option value="id_desc" <?php selected($orderby, 'id_desc'); ?>><?php esc_html_e('Order ID (high → low)', 'sutore-marketplace'); ?></option>
                    <option value="id_asc" <?php selected($orderby, 'id_asc'); ?>><?php esc_html_e('Order ID (low → high)', 'sutore-marketplace'); ?></option>
                    <option value="total_desc" <?php selected($orderby, 'total_desc'); ?>><?php esc_html_e('Total (high → low)', 'sutore-marketplace'); ?></option>
                    <option value="total_asc" <?php selected($orderby, 'total_asc'); ?>><?php esc_html_e('Total (low → high)', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-orders-sort-clear';
            $apply_class = 'sutore-mp-staff-orders-sort-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

</div>
<?php
include SUTORE_MARKETPLACE_PATH . 'templates/partials/staff-order-detail-modals.php';
include SUTORE_MARKETPLACE_PATH . 'templates/partials/staff-product-detail-modal.php';
include SUTORE_MARKETPLACE_PATH . 'templates/partials/staff-merchant-detail-modal.php';
