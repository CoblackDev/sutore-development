<?php
/**
 * Inline list search field (lives in page chrome, not filter modal).
 *
 * @var string $id          Input element id
 * @var string $input_class Extra class(es) on the input, e.g. sutore-mp-list-search
 * @var string $value       Initial value
 * @var string $placeholder Placeholder text
 */
if (!defined('ABSPATH')) {
    exit;
}

$id = isset($id) ? (string) $id : 'sutore-mp-list-search';
$inputClass = isset($input_class) ? trim((string) $input_class) : 'sutore-mp-list-search';
$value = isset($value) ? (string) $value : '';
$placeholder = isset($placeholder) ? (string) $placeholder : '';
?>
<div class="sutore-mp-inline-search">
    <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Search', 'sutore-marketplace'); ?></label>
    <input
        id="<?php echo esc_attr($id); ?>"
        name="search"
        type="search"
        class="sutore-mp-input sutore-mp-inline-search-input <?php echo esc_attr($inputClass); ?>"
        value="<?php echo esc_attr($value); ?>"
        placeholder="<?php echo esc_attr($placeholder); ?>"
        autocomplete="off"
    />
</div>
