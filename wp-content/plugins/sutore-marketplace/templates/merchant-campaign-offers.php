<?php

/** Merchant campaign offers — shell + REST cards/modal. */

if (!defined('ABSPATH')) {

    exit;

}

?>

<div class="sutore-mp-campaign-offers sutore-mp-account-panel">

    <div class="sutore-mp-list-chrome" hidden>

        <div class="sutore-mp-listings-header">

            <h2 class="wp-block-heading"><?php esc_html_e('Campaign offers', 'sutore-marketplace'); ?></h2>

            <div class="sutore-mp-header-actions">

                <?php
                $show_search = false;
                include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-controls-row.php';
                ?>

            </div>

        </div>

        <p class="sutore-mp-panel-lead">

            <?php esc_html_e('Review platform campaign offers for your listings. Accepting updates your asking price for the campaign period.', 'sutore-marketplace'); ?>

        </p>

    </div>



    <div class="sutore-mp-campaign-offers-results sutore-mp-list-results" aria-live="polite" aria-busy="true">

        <div class="sutore-mp-list-loading" role="status">

            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>

            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>

        </div>

    </div>

    <div class="sutore-mp-list-pager"></div>



    <div class="sutore-mp-filter-overlay" hidden>

        <form class="sutore-mp-filter-modal sutore-mp-campaign-offers-filter" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-campaign-offers-filter-title" action="#">

            <div class="sutore-mp-filter-head">

                <h2 id="sutore-mp-campaign-offers-filter-title"><?php esc_html_e('Filter', 'sutore-marketplace'); ?></h2>

                <button type="button" class="sutore-mp-filter-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>

            </div>

            <div class="sutore-mp-filter-body">

                <label class="sutore-mp-field-label" for="sutore-mp-campaign-offer-status"><?php esc_html_e('Status', 'sutore-marketplace'); ?></label>

                <select id="sutore-mp-campaign-offer-status" name="status" class="sutore-mp-input">

                    <option value="pending" selected><?php esc_html_e('Pending', 'sutore-marketplace'); ?></option>

                    <option value=""><?php esc_html_e('All', 'sutore-marketplace'); ?></option>

                    <option value="accepted"><?php esc_html_e('Accepted', 'sutore-marketplace'); ?></option>

                    <option value="declined"><?php esc_html_e('Declined', 'sutore-marketplace'); ?></option>

                </select>

            </div>

            <?php

            $clear_class = 'sutore-mp-campaign-offers-filter-clear';

            $apply_class = 'sutore-mp-campaign-offers-filter-apply';

            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';

            ?>

        </form>

    </div>



    <div class="sutore-mp-sort-overlay" hidden>

        <form class="sutore-mp-sort-modal sutore-mp-campaign-offers-sort" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-campaign-offers-sort-title" action="#">

            <div class="sutore-mp-sort-head">

                <h2 id="sutore-mp-campaign-offers-sort-title"><?php esc_html_e('Sort', 'sutore-marketplace'); ?></h2>

                <button type="button" class="sutore-mp-sort-close" aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">×</button>

            </div>

            <div class="sutore-mp-sort-body">

                <label class="sutore-mp-field-label" for="sutore-mp-campaign-offer-orderby"><?php esc_html_e('Sort by', 'sutore-marketplace'); ?></label>

                <select id="sutore-mp-campaign-offer-orderby" name="orderby" class="sutore-mp-input">

                    <option value="created_desc"><?php esc_html_e('Newest first', 'sutore-marketplace'); ?></option>

                    <option value="created_asc"><?php esc_html_e('Oldest first', 'sutore-marketplace'); ?></option>

                    <option value="asking_asc"><?php esc_html_e('Price (low to high)', 'sutore-marketplace'); ?></option>

                    <option value="asking_desc"><?php esc_html_e('Price (high to low)', 'sutore-marketplace'); ?></option>

                </select>

            </div>

            <?php

            $clear_class = 'sutore-mp-campaign-offers-sort-clear';

            $apply_class = 'sutore-mp-campaign-offers-sort-apply';

            include SUTORE_MARKETPLACE_PATH . 'templates/partials/list-modal-footer.php';

            ?>

        </form>

    </div>



    <div class="sutore-mp-manage-overlay sutore-mp-offer-overlay" hidden>

        <div

            class="sutore-mp-manage-modal sutore-mp-offer-modal"

            role="dialog"

            aria-modal="true"

            aria-labelledby="sutore-mp-offer-modal-title"

        >

            <div class="sutore-mp-manage-modal__handle" aria-hidden="true"><span></span></div>
            <div class="sutore-mp-manage-modal__head">
                <div class="sutore-mp-manage-modal__media" hidden></div>
                <div class="sutore-mp-manage-modal__titles">
                    <h2 id="sutore-mp-offer-modal-title" class="sutore-mp-manage-modal__title">
                        <?php esc_html_e('Campaign offer', 'sutore-marketplace'); ?>
                    </h2>
                    <p class="sutore-mp-manage-modal__sub"></p>
                </div>
                <span class="sutore-mp-manage-modal__badge" hidden></span>
                <button
                    type="button"
                    class="sutore-mp-manage-modal__close sutore-mp-offer-close"
                    aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>"
                >×</button>
            </div>



            <div class="sutore-mp-manage-modal__body">

                <div class="sutore-mp-offer-modal-summary"></div>

            </div>



            <div class="sutore-mp-manage-modal__foot sutore-mp-offer-modal-foot" hidden>

                <button type="button" class="wp-element-button is-style-outline sutore-mp-campaign-decline">

                    <?php esc_html_e('Decline', 'sutore-marketplace'); ?>

                </button>

                <button type="button" class="wp-element-button sutore-mp-campaign-accept">

                    <?php esc_html_e('Accept', 'sutore-marketplace'); ?>

                </button>

            </div>

        </div>

    </div>

</div>


