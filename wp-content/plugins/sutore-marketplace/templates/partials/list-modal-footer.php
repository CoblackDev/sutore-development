<?php
/**
 * Shared modal footer: Clear + Apply.
 *
 * @var string $clear_class CSS class for clear button
 * @var string $apply_class CSS class for apply button
 */
if (!defined('ABSPATH')) {
    exit;
}

$clearClass = isset($clear_class) ? (string) $clear_class : 'sutore-mp-list-clear';
$applyClass = isset($apply_class) ? (string) $apply_class : 'sutore-mp-list-apply';
?>
<div class="sutore-mp-filter-footer">
    <button type="button" class="wp-element-button is-style-outline <?php echo esc_attr($clearClass); ?>">
        <?php esc_html_e('Clear', 'sutore-marketplace'); ?>
    </button>
    <button type="button" class="wp-element-button <?php echo esc_attr($applyClass); ?>">
        <?php esc_html_e('Apply', 'sutore-marketplace'); ?>
    </button>
</div>
