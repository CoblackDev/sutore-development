<?php
/**
 * Inline search + filter/sort toolbar as one aligned control row.
 *
 * Set $show_search = false to render toolbar only (e.g. campaign offers).
 * Set $show_listing_actions = true for Add Product / Bulk upload icons (listings).
 * Set $show_export = true for CSV export (staff Manage Products).
 *
 * @var bool        $show_search
 * @var bool        $show_listing_actions
 * @var bool        $show_export
 * @var string|null $id
 * @var string|null $input_class
 * @var string|null $value
 * @var string|null $placeholder
 */
if (!defined('ABSPATH')) {
    exit;
}

$showSearch = !isset($show_search) || (bool) $show_search;
?>
<div class="sutore-mp-list-controls">
    <?php if ($showSearch) : ?>
        <?php include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-inline-search.php'; ?>
    <?php endif; ?>
    <?php include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-filter-sort-toolbar.php'; ?>
</div>
