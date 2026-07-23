<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_arrived_to_sutore_order_status() {
    register_post_status( 'wc-arrived-to-sutore', array(
        'label'                     => "Sutore'ye Ulaştı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
    ) );

    register_post_status( 'arrived-to-sutore', array(
        'label'                     => "Sutore'ye Ulaştı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Sutoreye Ulaştı <span class="count">(%s)</span>', 'Sutoreye Ulaştı <span class="count">(%s)</span>' )
    ) );

    register_post_status( 'sold', array(
        'label'                     => "Satıldı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Satıldı <span class="count">(%s)</span>', 'Satıldı <span class="count">(%s)</span>' )
    ) );

    register_post_status( 'not-sale', array(
        'label'                     => "Satışta Değil",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Satışta Değil <span class="count">(%s)</span>', 'Satışta Değil <span class="count">(%s)</span>' )
    ) );


}
add_action( 'init', 'register_arrived_to_sutore_order_status' );
function add_arrived_to_sutore_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-shipped-to-sutore' === $key ) {
            $new_order_statuses['wc-arrived-to-sutore'] = "Sutore'ye Ulaştı";
        }
    }
    return $new_order_statuses;
}
add_filter( 'wc_order_statuses', 'add_arrived_to_sutore_to_order_statuses' );
?>