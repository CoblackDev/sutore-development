<?php
/**
 * @var int  $step
 * @var bool $asPage
 * @var int  $pageListingId
 * @var bool $forceEditChrome
 * @var bool $staff_create
 */
if (!defined('ABSPATH')) {
    exit;
}

$pageListingId = (int) ($pageListingId ?? 0);
$forceEditChrome = !empty($forceEditChrome);
$isEdit = $pageListingId > 0 || $forceEditChrome;
$staffCreate = !empty($staff_create);
?>
<div
    class="sutore-mp-listing-form-wrap"
    data-variation-id="<?php echo esc_attr((string) $pageListingId); ?>"
    data-price-step="<?php echo esc_attr((string) $step); ?>"
>
    <div class="sutore-mp-listing-form-loading" hidden>
        <span class="sutore-mp-staff-spinner" aria-hidden="true"></span>
        <span class="sutore-mp-listing-form-loading-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
    </div>

    <input type="hidden" class="sutore-mp-parent-id" value="" />
    <p class="sutore-mp-create-wizard-alert sutore-mp-notice" role="alert" aria-live="assertive" hidden></p>

    <?php if ($staffCreate) : ?>
    <section class="sutore-mp-form-section sutore-mp-form-section-seller" data-section="seller" hidden>
            <h3 id="sutore-mp-seller-heading"><?php esc_html_e('Seller', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Choose the seller this product will belong to.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-staff-merchant-picker">
                <input
                    id="sutore-mp-staff-merchant-search"
                    type="search"
                    class="sutore-mp-input sutore-mp-staff-merchant-search"
                    autocomplete="off"
                    aria-labelledby="sutore-mp-seller-heading"
                    placeholder="<?php esc_attr_e('Search seller by name, email, ID…', 'sutore-marketplace'); ?>"
                />
                <input type="hidden" class="sutore-mp-staff-merchant-id" value="" />
                <div class="sutore-mp-staff-merchant-results" hidden></div>
            </div>
    </section>
    <?php endif; ?>

    <section class="sutore-mp-form-section sutore-mp-form-section-product" data-section="product"<?php echo $isEdit ? ' hidden' : ''; ?>>
            <h3 id="sutore-mp-product-heading"><?php esc_html_e('Product', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Search by product name or SKU to attach a catalog product to this product.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-search-wrap">
                <input
                    id="sutore-mp-product-code"
                    type="text"
                    class="sutore-mp-input sutore-mp-product-code"
                    autocomplete="off"
                    aria-labelledby="sutore-mp-product-heading"
                    placeholder="<?php esc_attr_e('Search by product name or SKU…', 'sutore-marketplace'); ?>"
                />
                <span class="sutore-mp-spinner" hidden aria-hidden="true"></span>
            </div>
            <div class="sutore-mp-search-results" aria-live="polite"></div>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-size" data-section="size"<?php echo $isEdit ? ' hidden' : ''; ?>>
            <h3 class="sutore-mp-axis-heading"><?php esc_html_e('Variation', 'sutore-marketplace'); ?></h3>
            <p class="sutore-mp-axis-hint description">
                <?php esc_html_e('Choose the variation for this product.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-sizes">
                <div class="sutore-mp-size-options" role="group" aria-label="<?php esc_attr_e('Variation', 'sutore-marketplace'); ?>"></div>
            </div>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-condition" data-section="condition">
            <h3><?php esc_html_e('Condition', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Mark any product condition notes.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-conditions">
                <?php
                $conditionOptions = [
                    'no_box' => __('No box', 'sutore-marketplace'),
                    'box_damaged' => __('Box damaged', 'sutore-marketplace'),
                    'missing_accessory' => __('Missing accessory', 'sutore-marketplace'),
                    'damaged' => __('Damaged', 'sutore-marketplace'),
                ];
                foreach ($conditionOptions as $conditionKey => $conditionLabel) :
                    ?>
                    <div class="wc-block-components-checkbox">
                        <label>
                            <input class="wc-block-components-checkbox__input" type="checkbox" name="conditions[<?php echo esc_attr($conditionKey); ?>]" value="1" />
                            <svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
                            <span class="wc-block-components-checkbox__label"><?php echo esc_html($conditionLabel); ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-shipping" data-section="shipping">
            <h3><?php esc_html_e('Shipping options', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Standard shipping is always available. You can select one or both of the options below.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-shipping-options" role="group" aria-label="<?php esc_attr_e('Shipping options', 'sutore-marketplace'); ?>">
                <div class="wc-block-components-checkbox sutore-mp-shipping-express">
                    <label>
                        <input type="checkbox" class="wc-block-components-checkbox__input sutore-mp-shipping-express-flag" value="1" />
                        <svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
                        <span class="wc-block-components-checkbox__label"><?php esc_html_e('Fast / Express', 'sutore-marketplace'); ?></span>
                    </label>
                </div>
                <div class="wc-block-components-checkbox sutore-mp-shipping-international">
                    <label>
                        <input type="checkbox" class="wc-block-components-checkbox__input sutore-mp-shipping-intl-flag" value="1" />
                        <svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
                        <span class="wc-block-components-checkbox__label"><?php esc_html_e('International', 'sutore-marketplace'); ?></span>
                    </label>
                </div>
            </div>
            <p class="sutore-mp-shipping-hint sutore-mp-express-ineligible sutore-mp-notice" hidden>
                <?php esc_html_e('Fast shipping is available only for Confirmed / Premium sellers.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-international-commit" hidden>
                <p class="sutore-mp-notice">
                    <?php echo esc_html(\SutoreMarketplace\Shared\Settings\Settings::internationalCommitmentText()); ?>
                </p>
            </div>
            <p class="sutore-mp-step-error sutore-mp-shipping-error" hidden></p>
    </section>

    <?php if ($staffCreate) : ?>
    <section class="sutore-mp-form-section sutore-mp-form-section-imported" data-section="imported">
            <h3><?php esc_html_e('Imported', 'sutore-marketplace'); ?></h3>
            <div class="sutore-mp-imported-options" role="group" aria-label="<?php esc_attr_e('Imported', 'sutore-marketplace'); ?>">
                <div class="wc-block-components-checkbox">
                    <label>
                        <input type="checkbox" class="wc-block-components-checkbox__input sutore-mp-imported-flag" value="1" />
                        <svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
                        <span class="wc-block-components-checkbox__label"><?php esc_html_e('Imported product', 'sutore-marketplace'); ?></span>
                    </label>
                </div>
            </div>
    </section>
    <?php endif; ?>

    <section class="sutore-mp-form-section sutore-mp-form-section-duration" data-section="duration">
            <h3 id="sutore-mp-duration-heading"><?php esc_html_e('Sale duration', 'sutore-marketplace'); ?></h3>
            <p class="description sutore-mp-duration-hint">
                <?php esc_html_e('Choose how long this product stays on sale.', 'sutore-marketplace'); ?>
            </p>
            <label class="screen-reader-text" for="sutore-mp-duration-days"><?php esc_html_e('Sale duration', 'sutore-marketplace'); ?></label>
            <select id="sutore-mp-duration-days" class="sutore-mp-input sutore-mp-duration-days"></select>
            <p class="description sutore-mp-duration-preview" aria-live="polite"></p>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-price" data-section="price">
            <h3 id="sutore-mp-price-heading"><?php esc_html_e('Price', 'sutore-marketplace'); ?></h3>
            <dl class="sutore-mp-staff-meta sutore-mp-form-context-meta">
                <div class="sutore-mp-retail-price-row">
                    <dt><?php esc_html_e('Starting price', 'sutore-marketplace'); ?></dt>
                    <dd class="sutore-mp-retail-price">—</dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Commission', 'sutore-marketplace'); ?></dt>
                    <dd class="sutore-mp-commission">—</dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Lowest Price', 'sutore-marketplace'); ?></dt>
                    <dd class="sutore-mp-min-price">—</dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Current queue', 'sutore-marketplace'); ?></dt>
                    <dd class="sutore-mp-queue">—</dd>
                </div>
            </dl>

            <div class="sutore-mp-price">
                <div class="sutore-mp-row">
                    <input
                        id="sutore-mp-asking"
                        type="number"
                        class="sutore-mp-input sutore-mp-asking"
                        min="<?php echo esc_attr((string) $step); ?>"
                        step="<?php echo esc_attr((string) $step); ?>"
                        aria-labelledby="sutore-mp-price-heading"
                    />
                    <button type="button" class="wp-element-button is-style-outline sutore-mp-first-place is-hidden" hidden><?php esc_html_e('Move to First Place', 'sutore-marketplace'); ?></button>
                </div>
                <div class="sutore-mp-price-meta">
                    <button type="button" class="sutore-mp-open-size-prices">
                        <span class="sutore-mp-open-size-prices__icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="12" cy="8" r="1" fill="currentColor"/>
                            </svg>
                        </span>
                        <span><?php esc_html_e('Size price list', 'sutore-marketplace'); ?></span>
                    </button>
                    <p class="sutore-mp-net-earnings">
                        <span class="sutore-mp-net-earnings__label"><?php esc_html_e('Your net earnings', 'sutore-marketplace'); ?></span>
                        <span class="sutore-mp-payout">—</span>
                    </p>
                </div>
                <p class="sutore-mp-price-alert is-hidden" hidden role="alert"></p>
            </div>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-competing sutore-mp-competing-prices" data-section="competing-prices" hidden>
                <h3><?php esc_html_e('Size price list', 'sutore-marketplace'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Compare other products for this size by queue position, price, condition, and shipping.', 'sutore-marketplace'); ?>
                </p>
                <p class="sutore-mp-competing-prices-locked sutore-mp-notice" hidden>
                    <?php esc_html_e('Confirmed or Premium seller level is required to view this list.', 'sutore-marketplace'); ?>
                </p>
                <p class="sutore-mp-competing-prices-empty sutore-mp-notice" hidden>
                    <?php esc_html_e('No other product for sale or in queue for this size.', 'sutore-marketplace'); ?>
                </p>
                <div class="sutore-mp-staff-table-wrap sutore-mp-competing-prices-table-wrap" hidden>
                    <table class="sutore-mp-staff-table sutore-mp-competing-prices-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Queue', 'sutore-marketplace'); ?></th>
                                <th scope="col"><?php esc_html_e('Price', 'sutore-marketplace'); ?></th>
                                <th scope="col"><?php esc_html_e('Condition', 'sutore-marketplace'); ?></th>
                                <th scope="col"><?php esc_html_e('Shipping', 'sutore-marketplace'); ?></th>
                                <th scope="col"><?php esc_html_e('Time remaining', 'sutore-marketplace'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
    </section>

    <div class="sutore-mp-form-actions">
            <button type="button" class="wp-element-button sutore-mp-submit">
                <?php
                echo $isEdit
                    ? esc_html__('Update', 'sutore-marketplace')
                    : esc_html__('Submit', 'sutore-marketplace');
                ?>
            </button>
            <?php if ($isEdit) : ?>
                <button
                    type="button"
                    class="wp-element-button is-style-outline sutore-mp-remove-from-sale is-hidden"
                    hidden
                    data-variation-id="<?php echo esc_attr((string) $pageListingId); ?>"
                >
                    <?php esc_html_e('Remove from sale', 'sutore-marketplace'); ?>
                </button>
                <button
                    type="button"
                    class="wp-element-button is-style-outline sutore-mp-delete is-hidden"
                    hidden
                    data-variation-id="<?php echo esc_attr((string) $pageListingId); ?>"
                >
                    <?php esc_html_e('Delete', 'sutore-marketplace'); ?>
                </button>
            <?php endif; ?>
            <p class="sutore-mp-message" aria-live="polite"></p>
    </div>
</div>
