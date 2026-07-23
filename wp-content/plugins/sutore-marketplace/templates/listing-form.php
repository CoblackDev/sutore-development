<?php
/** @var int $step */
/** @var bool $asPage */
/** @var int $pageListingId */
/** @var bool $forceEditChrome */
if (!defined('ABSPATH')) {
    exit;
}

$pageListingId = (int) ($pageListingId ?? 0);
$forceEditChrome = !empty($forceEditChrome);
$isEdit = $pageListingId > 0 || $forceEditChrome;
?>
<div
    class="sutore-mp-listing-form-wrap"
    data-listing-id="<?php echo esc_attr((string) $pageListingId); ?>"
    data-price-step="<?php echo esc_attr((string) $step); ?>"
>
    <div class="sutore-mp-listing-form-loading" hidden>
        <span class="sutore-mp-staff-spinner" aria-hidden="true"></span>
        <span class="sutore-mp-listing-form-loading-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>
    </div>

    <input type="hidden" class="sutore-mp-parent-id" value="" />

    <section class="sutore-mp-form-section sutore-mp-form-section-product" data-section="product"<?php echo $isEdit ? ' hidden' : ''; ?>>
            <h3><?php esc_html_e('Product', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Search by product code or SKU to attach a catalog product to this listing.', 'sutore-marketplace'); ?>
            </p>
            <label class="sutore-mp-field-label" for="sutore-mp-product-code"><?php esc_html_e('Search product code', 'sutore-marketplace'); ?></label>
            <div class="sutore-mp-search-wrap">
                <input
                    id="sutore-mp-product-code"
                    type="text"
                    class="sutore-mp-input sutore-mp-product-code"
                    autocomplete="off"
                    placeholder="<?php esc_attr_e('Enter product code / SKU…', 'sutore-marketplace'); ?>"
                />
                <span class="sutore-mp-spinner" hidden aria-hidden="true"></span>
            </div>
            <div class="sutore-mp-search-results" aria-live="polite"></div>
    </section>

    <section class="sutore-mp-form-section sutore-mp-form-section-size" data-section="size"<?php echo $isEdit ? ' hidden' : ''; ?>>
            <h3><?php esc_html_e('Size', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Choose the size for this listing.', 'sutore-marketplace'); ?>
            </p>
            <div class="sutore-mp-sizes">
                <div class="sutore-mp-size-options" role="group" aria-label="<?php esc_attr_e('Size', 'sutore-marketplace'); ?>"></div>
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

    <section class="sutore-mp-form-section sutore-mp-form-section-price" data-section="price">
            <h3><?php esc_html_e('Price', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Set your asking price and review queue position and estimated payout.', 'sutore-marketplace'); ?>
            </p>
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
                <label class="sutore-mp-field-label" for="sutore-mp-asking"><?php esc_html_e('Price', 'sutore-marketplace'); ?></label>
                <div class="sutore-mp-row">
                    <input id="sutore-mp-asking" type="number" class="sutore-mp-input sutore-mp-asking" min="<?php echo esc_attr((string) $step); ?>" step="<?php echo esc_attr((string) $step); ?>" />
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
                    <?php esc_html_e('Compare other listings for this size by queue position, price, condition, and shipping.', 'sutore-marketplace'); ?>
                </p>
                <p class="sutore-mp-competing-prices-locked sutore-mp-notice" hidden>
                    <?php esc_html_e('Confirmed or Premium seller level is required to view this list.', 'sutore-marketplace'); ?>
                </p>
                <p class="sutore-mp-competing-prices-empty sutore-mp-notice" hidden>
                    <?php esc_html_e('No other Listing for sale or in queue for this size.', 'sutore-marketplace'); ?>
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
                    data-listing-id="<?php echo esc_attr((string) $pageListingId); ?>"
                >
                    <?php esc_html_e('Remove from sale', 'sutore-marketplace'); ?>
                </button>
                <button
                    type="button"
                    class="wp-element-button is-style-outline sutore-mp-delete is-hidden"
                    hidden
                    data-listing-id="<?php echo esc_attr((string) $pageListingId); ?>"
                >
                    <?php esc_html_e('Delete', 'sutore-marketplace'); ?>
                </button>
            <?php endif; ?>
            <p class="sutore-mp-message" aria-live="polite"></p>
    </div>
</div>
