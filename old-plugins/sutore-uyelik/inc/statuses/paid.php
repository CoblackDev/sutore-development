<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_paid_order_status() {

    register_post_status( 'paid', array(
        'label'                     => "Ödendi",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Ödendi <span class="count">(%s)</span>', 'Ödendi <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_paid_order_status' );

?>