<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_verified_order_status() {
    register_post_status( 'verified', array(
        'label'                     => "Doğrulandı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Doğrulandı <span class="count">(%s)</span>', 'Doğrulandı <span class="count">(%s)</span>' )
    ) );

    register_post_status( 'pre-order', array(
        'label'                     => "Ön Sipariş",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Ön Sipariş <span class="count">(%s)</span>', 'Ön Sipariş <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_verified_order_status' );

?>