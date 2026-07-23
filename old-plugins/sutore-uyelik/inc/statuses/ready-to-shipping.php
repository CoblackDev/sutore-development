<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_ready_to_shipping_order_status() {
    register_post_status( 'ready-to-shipping', array(
        'label'                     => "Kargoya Hazır",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Kargoya Hazır <span class="count">(%s)</span>', 'Kargoya Hazır <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_ready_to_shipping_order_status' );

?>