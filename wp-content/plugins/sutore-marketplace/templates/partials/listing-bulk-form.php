<?php

declare(strict_types=1);

/**
 * Bulk listing CSV import form body (wizard steps inside bulk modal).
 */

if (!defined('ABSPATH')) {
    exit;
}

$templateUrl = SUTORE_MARKETPLACE_URL . 'assets/csv/listings-import-template.csv';
$staffCreate = !empty($staff_create);
?>
<div class="sutore-mp-listing-bulk" data-bulk-mode="1">
    <p class="sutore-mp-bulk-alert sutore-mp-notice" role="alert" aria-live="assertive" hidden></p>

    <section class="sutore-mp-bulk-step" data-bulk-step="1">
        <div class="sutore-mp-form-section sutore-mp-bulk-section-upload">
            <h3><?php esc_html_e('CSV file', 'sutore-marketplace'); ?></h3>
            <p class="description">
                <?php esc_html_e('Upload a CSV file to create multiple products at once. Review the preview, then confirm to queue a background import.', 'sutore-marketplace'); ?>
            </p>
            <input
                id="sutore-mp-bulk-file"
                type="file"
                class="sutore-mp-input sutore-mp-bulk-file"
                accept=".csv,text/csv"
                aria-label="<?php esc_attr_e('CSV file', 'sutore-marketplace'); ?>"
            />
            <div class="sutore-mp-bulk-file-meta">
                <a
                    class="sutore-mp-bulk-template-download"
                    href="<?php echo esc_url($templateUrl); ?>"
                    download="sutore-listings-import-template.csv"
                >
                    <span class="sutore-mp-bulk-template-download__icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 16V4M12 4l-4 4M12 4l4 4M4 20h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span><?php esc_html_e('Download sample template', 'sutore-marketplace'); ?></span>
                </a>
            </div>
            <p class="sutore-mp-bulk-upload-message" aria-live="polite"></p>
        </div>
    </section>

    <section class="sutore-mp-bulk-step" data-bulk-step="2" hidden>
        <?php if ($staffCreate) : ?>
        <div class="sutore-mp-form-section sutore-mp-bulk-section-seller">
            <h3 id="sutore-mp-bulk-seller-heading"><?php esc_html_e('Seller', 'sutore-marketplace'); ?></h3>
            <div class="sutore-mp-staff-merchant-picker sutore-mp-staff-merchant-picker--bulk">
                <input
                    id="sutore-mp-staff-bulk-merchant-search"
                    type="search"
                    class="sutore-mp-input sutore-mp-staff-merchant-search"
                    autocomplete="off"
                    aria-labelledby="sutore-mp-bulk-seller-heading"
                    placeholder="<?php esc_attr_e('Search seller by name, email, ID…', 'sutore-marketplace'); ?>"
                />
                <input type="hidden" class="sutore-mp-staff-merchant-id" value="" />
                <div class="sutore-mp-staff-merchant-results" hidden></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="sutore-mp-form-section sutore-mp-bulk-section-preview">
            <div class="sutore-mp-bulk-summary" aria-live="polite"></div>
            <div class="sutore-mp-bulk-table-wrap">
                <table class="sutore-mp-bulk-table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col"><?php esc_html_e('Product', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Size', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Condition', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Shipping', 'sutore-marketplace'); ?></th>
                            <?php if ($staffCreate) : ?>
                            <th scope="col" data-col="imported"><?php esc_html_e('Imported', 'sutore-marketplace'); ?></th>
                            <?php endif; ?>
                            <th scope="col"><?php esc_html_e('Lowest on sale', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Price', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Queue preview', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'sutore-marketplace'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'sutore-marketplace'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="sutore-mp-bulk-commit-message" aria-live="polite"></p>
        </div>
    </section>

    <section class="sutore-mp-bulk-step sutore-mp-bulk-result" data-bulk-step="done" hidden></section>
</div>
