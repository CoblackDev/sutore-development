<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array{pre_information:string,distance_sales:string} $contracts */
?>
<div class="sutore-contracts-email">
    <h2><?php esc_html_e('Pre-Information Form', 'sutore-marketplace'); ?></h2>
    <div class="sutore-contracts-email__scroll">
        <?php echo wp_kses_post($contracts['pre_information']); ?>
    </div>

    <h2><?php esc_html_e('Distance Selling Agreement', 'sutore-marketplace'); ?></h2>
    <div class="sutore-contracts-email__scroll">
        <?php echo wp_kses_post($contracts['distance_sales']); ?>
    </div>
</div>
