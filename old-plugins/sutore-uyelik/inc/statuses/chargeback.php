<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_chargeback_order_status() {
    register_post_status( 'chargeback', array(
        'label'                     => "Iade Edildi",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Iade Edildi <span class="count">(%s)</span>', 'Iade Edildi <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_chargeback_order_status' );

?>