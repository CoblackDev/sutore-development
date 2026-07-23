<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_shipped_order_status() {
//    register_post_status( 'wc-verified', array(
//        'label'                     => "Doğrulandı",
//        'public'                    => true,
//        'show_in_admin_status_list' => true,
//        'show_in_admin_all_list'    => true,
//        'exclude_from_search'       => false,
//        'label_count'               => _n_noop( 'Doğrulandı <span class="count">(%s)</span>', 'Doğrulandı <span class="count">(%s)</span>' )
//    ) );
    register_post_status( 'shipped', array(
        'label'                     => "Kargolandı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Kargolandı <span class="count">(%s)</span>', 'Kargolandı <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_shipped_order_status' );

function register_new_custom_wc_order_statuses( $order_statuses ) {
    $order_statuses['shipped'] = _x( 'Kargolandı', 'Order status', 'flatsome' );
    return $order_statuses;
}
//add_filter( 'wc_order_statuses', 'register_new_custom_wc_order_statuses' );

//function add_verified_to_order_statuses( $order_statuses ) {
//    $new_order_statuses = array();
//    foreach ( $order_statuses as $key => $status ) {
//        $new_order_statuses[ $key ] = $status;
//        if ( 'wc-processing' === $key ) {
//            $new_order_statuses['wc-verified'] = "Doğrulandı";
//        }
//    }
//    return $new_order_statuses;
//}
//add_filter( 'wc_order_statuses', 'add_verified_to_order_statuses' );
?>