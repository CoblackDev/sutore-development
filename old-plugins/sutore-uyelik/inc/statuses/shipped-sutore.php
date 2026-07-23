<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_shipment_arrival_order_status() {
    register_post_status( 'wc-shipped-to-sutore', array(
        'label'                     => "Sutore'ye Kargolandı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Sutoreye Kargolandı <span class="count">(%s)</span>', 'Sutoreye Kargolandı <span class="count">(%s)</span>' )
    ) );


    register_post_status( 'shipped-to-sutore', array(
        'label'                     => "Satıcı Sutore'ye Kargoladı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Satıcı Sutoreye Kargoladı <span class="count">(%s)</span>', 'Sutoreye Kargoladı <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_shipment_arrival_order_status' );
function add_awaiting_shipment_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-processing' === $key ) {
            $new_order_statuses['wc-shipped-to-sutore'] = "Sutore'ye Kargolandı";
        }
    }
    return $new_order_statuses;
}
add_filter( 'wc_order_statuses', 'add_awaiting_shipment_to_order_statuses' );
?>