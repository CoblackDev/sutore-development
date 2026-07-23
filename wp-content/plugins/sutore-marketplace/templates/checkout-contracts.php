<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array{pre_information:string,distance_sales:string} $contracts */
/** @var string $title */
?>
<div class="sutore-contracts-root">
    <dialog id="sutore-contracts-dialog" class="sutore-contracts-dialog">
        <div class="sutore-contracts-dialog__inner">
            <header class="sutore-contracts-dialog__header">
                <h2><?php echo esc_html($title); ?></h2>
                <button type="button" class="sutore-contracts-dialog__close" data-sutore-contracts-close aria-label="<?php esc_attr_e('Close', 'sutore-marketplace'); ?>">&times;</button>
            </header>
            <div class="sutore-contracts-dialog__body" id="sutore-contracts-content">
                <section class="sutore-contracts-section">
                    <h3><?php esc_html_e('Pre-Information Form', 'sutore-marketplace'); ?></h3>
                    <div class="sutore-contracts-scroll">
                        <?php echo wp_kses_post($contracts['pre_information']); ?>
                    </div>
                </section>
                <section class="sutore-contracts-section">
                    <h3><?php esc_html_e('Distance Selling Agreement', 'sutore-marketplace'); ?></h3>
                    <div class="sutore-contracts-scroll">
                        <?php echo wp_kses_post($contracts['distance_sales']); ?>
                    </div>
                </section>
            </div>
            <footer class="sutore-contracts-dialog__footer">
                <button type="button" class="button primary is-underline sutore-contracts-accept" data-sutore-contracts-accept>
                    <?php esc_html_e('I have read and agree', 'sutore-marketplace'); ?>
                </button>
            </footer>
        </div>
    </dialog>
</div>
