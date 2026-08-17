<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staff catalog product requests — shell + REST queue.
 *
 * @var array{
 *   search: string,
 *   status_filter: string,
 *   page: int,
 *   base_url: string,
 *   status_labels: array<string, string>
 * } $view
 */

$baseUrl = (string) ($view['base_url'] ?? '');
$search = (string) ($view['search'] ?? '');
$statusFilter = (string) ($view['status_filter'] ?? 'pending');
$page = max(1, (int) ($view['page'] ?? 1));
$statusLabels = is_array($view['status_labels'] ?? null) ? $view['status_labels'] : [];
?>
<div class="sutore-mp sutore-mp-account-panel sutore-mp-staff-catalog-requests">
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2><?php esc_html_e('Catalog requests', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <?php
            $id = 'sutore-mp-staff-catalog-search';
            $input_class = 'sutore-mp-staff-catalog-search';
            $value = $search;
            $placeholder = __('SKU, link, size, note…', 'sutore-marketplace');
            $show_listing_actions = false;
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
            ?>
        </div>
    </div>

    <p class="sutore-mp-panel-lead">
        <?php esc_html_e('Sellers request products that are not in the catalog. Add the product in WooCommerce, then mark the request as added so the seller can open a listing.', 'sutore-marketplace'); ?>
    </p>

    <div
        class="sutore-mp-staff-catalog-results sutore-mp-list-results"
        data-base-url="<?php echo esc_url($baseUrl); ?>"
        data-search="<?php echo esc_attr($search); ?>"
        data-status="<?php echo esc_attr($statusFilter); ?>"
        data-page="<?php echo esc_attr((string) $page); ?>"
        aria-live="polite"
        aria-busy="true"
    >
        <div class="sutore-mp-list-loading" role="status">
            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
        </div>
    </div>
    <div class="sutore-mp-list-pager"></div>

    <div class="sutore-mp-filter-overlay" hidden>
        <form class="sutore-mp-filter-modal sutore-mp-staff-catalog-filter" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-catalog-filter-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-staff-catalog-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-catalog-status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-catalog-status" name="status" class="sutore-mp-input">
                    <option value="pending" <?php selected($statusFilter, 'pending'); ?>><?php esc_html_e('Pending', 'sutore-marketplace'); ?></option>
                    <option value="" <?php selected($statusFilter, ''); ?>><?php esc_html_e('All', 'sutore-marketplace'); ?></option>
                    <?php foreach ($statusLabels as $statusKey => $statusLabel) :
                        if ($statusKey === 'pending') {
                            continue;
                        }
                        ?>
                        <option value="<?php echo esc_attr((string) $statusKey); ?>" <?php selected($statusFilter, (string) $statusKey); ?>>
                            <?php echo esc_html((string) $statusLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sutore-mp-filter-foot">
                <button type="button" class="wp-element-button sutore-mp-staff-catalog-filter-apply"><?php esc_html_e('Apply', 'sutore-marketplace'); ?></button>
            </div>
        </form>
    </div>
</div>
