<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_confirmed_product_status() {
    register_post_status( 'confirmed', array(
        'label'                     => "Satıcı Onayladı",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Satıcı Onayladı <span class="count">(%s)</span>', 'Satıcı Onayladı <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_confirmed_product_status' );

?>