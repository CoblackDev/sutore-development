<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_shipped_to_customer_order_status() {
    register_post_status( 'wc-shipped-customer', array(
        'label'                     => "Kargolandı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
    ) );
}
add_action( 'init', 'register_shipped_to_customer_order_status' );
function add_shipped_to_customer_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-processing' === $key ) {
            $new_order_statuses['wc-shipped-customer'] = "Kargolandı";
        }
    }
    return $new_order_statuses;
}
add_filter( 'wc_order_statuses', 'add_shipped_to_customer_to_order_statuses' );

?>