<?php
/**
 * Icon toolbar: filter + sort (+ optional listing add/bulk).
 *
 * Set $show_export = true for CSV export (staff Manage Products).
 *
 * @var bool $show_filter
 * @var bool $show_listing_actions
 * @var bool $show_export
 */
if (!defined('ABSPATH')) {
    exit;
}

$showFilter = !isset($show_filter) || (bool) $show_filter;
$showListingActions = !empty($show_listing_actions);
$showExport = !empty($show_export);
?>
<div class="sutore-mp-list-toolbar">
    <?php if ($showFilter) : ?>
        <button
            type="button"
            class="wp-element-button is-style-outline sutore-mp-toolbar-btn sutore-mp-open-filters"
            aria-label="<?php esc_attr_e('Filter', 'sutore-marketplace'); ?>"
        >
            <span class="sutore-mp-btn-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="screen-reader-text"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></span>
            <span class="sutore-mp-filter-badge" hidden></span>
        </button>
    <?php endif; ?>
    <button
        type="button"
        class="wp-element-button is-style-outline sutore-mp-toolbar-btn sutore-mp-open-sort"
        aria-label="<?php esc_attr_e('Sort', 'sutore-marketplace'); ?>"
    >
        <span class="sutore-mp-btn-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <span class="screen-reader-text"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></span>
        <span class="sutore-mp-sort-badge" hidden></span>
    </button>
    <?php if ($showExport) : ?>
        <button
            type="button"
            class="wp-element-button is-style-outline sutore-mp-toolbar-btn sutore-mp-staff-export-csv"
            aria-label="<?php esc_attr_e('Export CSV', 'sutore-marketplace'); ?>"
        >
            <span class="sutore-mp-btn-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3v12M8 11l4 4 4-4M4 21h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="screen-reader-text"><?php esc_html_e('Export CSV', 'sutore-marketplace'); ?></span>
        </button>
    <?php endif; ?>
    <?php if ($showListingActions) : ?>
        <button
            type="button"
            class="wp-element-button is-style-outline sutore-mp-toolbar-btn sutore-mp-open-create"
            aria-label="<?php esc_attr_e('Add Product', 'sutore-marketplace'); ?>"
        >
            <span class="sutore-mp-btn-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="screen-reader-text"><?php esc_html_e('Add Product', 'sutore-marketplace'); ?></span>
        </button>
        <button
            type="button"
            class="wp-element-button is-style-outline sutore-mp-toolbar-btn sutore-mp-open-bulk"
            aria-label="<?php esc_attr_e('Bulk upload', 'sutore-marketplace'); ?>"
        >
            <span class="sutore-mp-btn-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 16V4M12 4l-4 4M12 4l4 4M4 20h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="screen-reader-text"><?php esc_html_e('Bulk upload', 'sutore-marketplace'); ?></span>
        </button>
    <?php endif; ?>
</div>
