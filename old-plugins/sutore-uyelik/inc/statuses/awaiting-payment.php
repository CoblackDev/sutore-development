<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_awaiting_payment_status() {
    register_post_status( 'payment', array(
        'label'                     => "Ödeme Bekleniyor",
        'public'                    => false,
        'publicly_queryable'        => false,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => true,
        'label_count'               => _n_noop( 'Ödeme Bekleniyor <span class="count">(%s)</span>', 'Ödeme Bekleniyor <span class="count">(%s)</span>' )
    ) );
}
add_action( 'init', 'register_awaiting_payment_status' );

?>
