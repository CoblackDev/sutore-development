<?php
if (!defined('ABSPATH')) {
    exit;
}

use SutoreMarketplace\Shared\Domain\MerchantLevels;

/**
 * Staff merchants shell. List loads via REST; seller detail opens in a modal.
 *
 * @var array{
 *   detail_id: int,
 *   search: string,
 *   level: string,
 *   tc_verified: string,
 *   has_restriction: string,
 *   balance: string,
 *   sales: string,
 *   orderby: string,
 *   page: int,
 *   base_url: string
 * } $view
 */

$baseUrl = (string) ($view['base_url'] ?? '');
$detailId = (int) ($view['detail_id'] ?? 0);
$search = (string) ($view['search'] ?? '');
$level = (string) ($view['level'] ?? '');
$tcVerified = (string) ($view['tc_verified'] ?? '');
$hasRestriction = (string) ($view['has_restriction'] ?? '');
$balance = (string) ($view['balance'] ?? '');
$sales = (string) ($view['sales'] ?? '');
$orderby = (string) ($view['orderby'] ?? 'id_desc');
$page = max(1, (int) ($view['page'] ?? 1));
?>
<div
    class="sutore-mp sutore-mp-account-panel sutore-mp-staff-manage sutore-mp-staff-merchants"
    data-open-merchant-id="<?php echo esc_attr((string) $detailId); ?>"
>
    <div class="sutore-mp-listings-header sutore-mp-list-chrome" hidden>
        <h2><?php esc_html_e('Sellers', 'sutore-marketplace'); ?></h2>
        <div class="sutore-mp-header-actions">
            <?php
            $id = 'sutore-mp-staff-merchants-search';
            $input_class = 'sutore-mp-staff-merchants-search';
            $value = $search;
            $placeholder = __('First name, last name, email, phone, ID…', 'sutore-marketplace');
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
            ?>
        </div>
    </div>

    <div
        class="sutore-mp-staff-merchants-list-root"
        data-base-url="<?php echo esc_url($baseUrl); ?>"
        data-search="<?php echo esc_attr($search); ?>"
        data-level="<?php echo esc_attr($level); ?>"
        data-tc-verified="<?php echo esc_attr($tcVerified); ?>"
        data-has-restriction="<?php echo esc_attr($hasRestriction); ?>"
        data-balance="<?php echo esc_attr($balance); ?>"
        data-sales="<?php echo esc_attr($sales); ?>"
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
        <form class="sutore-mp-filter-modal sutore-mp-staff-merchants-filter" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-merchants-filter-title" action="#">
            <div class="sutore-mp-filter-head">
                <h2 id="sutore-mp-staff-merchants-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-filter-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-level"><?php esc_html_e('Level', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-level" name="level" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All levels', 'sutore-marketplace'); ?></option>
                    <?php foreach ([MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM] as $levelKey) : ?>
                        <option value="<?php echo esc_attr($levelKey); ?>" <?php selected($level, $levelKey); ?>>
                            <?php echo esc_html(MerchantLevels::labelForStatus($levelKey)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-tc"><?php esc_html_e('TC identity', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-tc" name="tc_verified" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All TC statuses', 'sutore-marketplace'); ?></option>
                    <option value="1" <?php selected($tcVerified, '1'); ?>><?php esc_html_e('TC verified', 'sutore-marketplace'); ?></option>
                    <option value="0" <?php selected($tcVerified, '0'); ?>><?php esc_html_e('TC not verified', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-restriction"><?php esc_html_e('Restrictions', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-restriction" name="has_restriction" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All restriction statuses', 'sutore-marketplace'); ?></option>
                    <option value="1" <?php selected($hasRestriction, '1'); ?>><?php esc_html_e('Restricted', 'sutore-marketplace'); ?></option>
                    <option value="0" <?php selected($hasRestriction, '0'); ?>><?php esc_html_e('None', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-balance"><?php esc_html_e('Balance', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-balance" name="balance" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All balances', 'sutore-marketplace'); ?></option>
                    <option value="has_pending" <?php selected($balance, 'has_pending'); ?>><?php esc_html_e('Has pending balance', 'sutore-marketplace'); ?></option>
                    <option value="no_pending" <?php selected($balance, 'no_pending'); ?>><?php esc_html_e('No pending balance', 'sutore-marketplace'); ?></option>
                    <option value="has_paid" <?php selected($balance, 'has_paid'); ?>><?php esc_html_e('Has paid total', 'sutore-marketplace'); ?></option>
                </select>

                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-sales"><?php esc_html_e('Sales', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-sales" name="sales" class="sutore-mp-input">
                    <option value=""><?php esc_html_e('All sales', 'sutore-marketplace'); ?></option>
                    <option value="has_sales" <?php selected($sales, 'has_sales'); ?>><?php esc_html_e('Has sales', 'sutore-marketplace'); ?></option>
                    <option value="no_sales" <?php selected($sales, 'no_sales'); ?>><?php esc_html_e('No sales', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-merchants-filter-clear';
            $apply_class = 'sutore-mp-staff-merchants-filter-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

    <div class="sutore-mp-sort-overlay" hidden>
        <form class="sutore-mp-sort-modal sutore-mp-staff-merchants-sort" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-staff-merchants-sort-title" action="#">
            <div class="sutore-mp-sort-head">
                <h2 id="sutore-mp-staff-merchants-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>
                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>
            </div>
            <div class="sutore-mp-sort-body">
                <label class="sutore-mp-field-label" for="sutore-mp-staff-merchants-orderby"><?php esc_html_e('Sort by', 'sutore-marketplace'); ?></label>
                <select id="sutore-mp-staff-merchants-orderby" name="orderby" class="sutore-mp-input">
                    <option value="id_desc" <?php selected($orderby, 'id_desc'); ?>><?php esc_html_e('Newest first', 'sutore-marketplace'); ?></option>
                    <option value="name_asc" <?php selected($orderby, 'name_asc'); ?>><?php esc_html_e('Name A–Z', 'sutore-marketplace'); ?></option>
                    <option value="pending_desc" <?php selected($orderby, 'pending_desc'); ?>><?php esc_html_e('Pending balance', 'sutore-marketplace'); ?></option>
                    <option value="paid_desc" <?php selected($orderby, 'paid_desc'); ?>><?php esc_html_e('Paid total', 'sutore-marketplace'); ?></option>
                    <option value="sold_desc" <?php selected($orderby, 'sold_desc'); ?>><?php esc_html_e('Sold count', 'sutore-marketplace'); ?></option>
                </select>
            </div>
            <?php
            $clear_class = 'sutore-mp-staff-merchants-sort-clear';
            $apply_class = 'sutore-mp-staff-merchants-sort-apply';
            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';
            ?>
        </form>
    </div>

</div>
<?php
include SUTORE_MARKETPLACE_PATH . 'templates/partials/staff-product-detail-modal.php';
include SUTORE_MARKETPLACE_PATH . 'templates/partials/staff-merchant-detail-modal.php';
?>