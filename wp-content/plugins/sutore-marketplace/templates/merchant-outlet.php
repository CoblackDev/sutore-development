<?php

/** Merchant outlet catalog — shell + REST cards. */

if (!defined('ABSPATH')) {
    exit;
}

?>

<div class="sutore-mp-outlet sutore-mp-account-panel">

    <div class="sutore-mp-list-chrome" hidden>

        <div class="sutore-mp-listings-header">

            <h2 class="wp-block-heading"><?php esc_html_e('Outlet', 'sutore-marketplace'); ?></h2>

        </div>

        <p class="sutore-mp-panel-lead">

            <?php esc_html_e('Join an outlet window at the listed price. A product is created when the window opens and unsold products expire when it ends.', 'sutore-marketplace'); ?>

        </p>

    </div>

    <div class="sutore-mp-outlet-results sutore-mp-list-results" aria-live="polite" aria-busy="true">

        <div class="sutore-mp-list-loading" role="status">

            <span class="sutore-mp-list-spinner" aria-hidden="true"></span>

            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'sutore-marketplace'); ?></span>

        </div>

    </div>

</div>
