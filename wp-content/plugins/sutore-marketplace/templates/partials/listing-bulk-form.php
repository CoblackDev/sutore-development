<?php

declare(strict_types=1);

/**
 * Bulk listing CSV import form body (wizard steps inside bulk modal).
 */

if (!defined('ABSPATH')) {
    exit;
}

$templateUrl = SUTORE_MARKETPLACE_URL . 'assets/csv/listings-import-template.csv';
?>
<div class="sutore-mp-listing-bulk" data-bulk-mode="1">
    <section class="sutore-mp-bulk-step" data-bulk-step="1">
        <p class="sutore-mp-panel-lead">
            <?php esc_html_e('Upload a CSV file to create multiple listings at once. Review the preview, then confirm to queue a background import.', 'sutore-marketplace'); ?>
        </p>
        <label class="sutore-mp-field-label" for="sutore-mp-bulk-file"><?php esc_html_e('CSV file', 'sutore-marketplace'); ?></label>
        <input
            id="sutore-mp-bulk-file"
            type="file"
            class="sutore-mp-input sutore-mp-bulk-file"
            accept=".csv,text/csv"
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
    </section>

    <section class="sutore-mp-bulk-step" data-bulk-step="2" hidden>
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
    </section>

    <section class="sutore-mp-bulk-step sutore-mp-bulk-result" data-bulk-step="done" hidden></section>
</div>
