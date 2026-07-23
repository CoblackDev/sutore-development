<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var string $title */
/** @var string $field */
?>
<p class="form-row form-row-wide sutore-contracts-checkbox validate-required">
    <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox custom-one">
        <input
            id="sutore-contracts-check"
            type="checkbox"
            class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
            name="<?php echo esc_attr($field); ?>"
            value="1"
        />
        <span class="checkmark"></span>
        <span class="sutore-contracts-copy woocommerce-terms-and-conditions-checkbox-text">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: contracts link label */
                    __('I have read and accept the sutore %s page.', 'sutore-marketplace'),
                    '<a class="sutore-contracts-open" href="#" role="button">' . esc_html($title) . '</a>'
                ),
                [
                    'a' => [
                        'class' => true,
                        'href' => true,
                        'role' => true,
                    ],
                ]
            );
            ?>
            <abbr class="required" title="<?php esc_attr_e('required', 'sutore-marketplace'); ?>">*</abbr>
        </span>
    </label>
</p>
