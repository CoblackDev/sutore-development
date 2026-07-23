<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_expired_order_status() {
    register_post_status( 'expired', array(
        'label'                     => "Süresi Dolmuş",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Süresi Dolmuş <span class="count">(%s)</span>', 'Süresi Dolmuş <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_expired_order_status' );

?>