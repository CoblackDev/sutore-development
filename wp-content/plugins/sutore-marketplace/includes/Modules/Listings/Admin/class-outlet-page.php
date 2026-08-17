<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Admin;

use SutoreMarketplace\Admin\AdminAssets;
use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus;
use SutoreMarketplace\Modules\Listings\Services\OutletQueryPresenter;

final class OutletPage
{
    public function render(): void
    {
        if (!current_user_can(AdminMenu::CAP)) {
            return;
        }

        (new \SutoreMarketplace\Modules\Listings\Services\OutletService())->runPass();
        AdminAssets::enqueue();

        $windows = (new OutletQueryPresenter())->listForAdmin();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Outlet', 'sutore-marketplace') . '</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<p class="description">' . esc_html__(
            'Manual outlet windows. Add product + size with a customer sale price and seller asking. Sellers opt in from My Account. Listings are created when the window opens and unsold ones expire when it ends.',
            'sutore-marketplace'
        ) . '</p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ([
            'ID',
            __('Name', 'sutore-marketplace'),
            __('Status', 'sutore-marketplace'),
            __('Starts', 'sutore-marketplace'),
            __('Ends', 'sutore-marketplace'),
            __('Items', 'sutore-marketplace'),
            __('Opt-ins', 'sutore-marketplace'),
            __('Action', 'sutore-marketplace'),
        ] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($windows === []) {
            echo '<tr><td colspan="8">' . esc_html__('No outlet window.', 'sutore-marketplace') . '</td></tr>';
        }

        foreach ($windows as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td><strong>' . esc_html((string) $row['name']) . '</strong></td>';
            echo '<td><span class="post-state">' . esc_html((string) $row['status_label']) . '</span></td>';
            echo '<td>' . esc_html((string) ($row['starts_at_label'] ?: '—')) . '</td>';
            echo '<td>' . esc_html((string) ($row['ends_at_label'] ?: '—')) . '</td>';
            echo '<td>' . (int) $row['item_count'] . '</td>';
            echo '<td>' . sprintf(
                /* translators: 1: pending opt-in count, 2: live listing count */
                esc_html__('%1$d waiting / %2$d live', 'sutore-marketplace'),
                (int) $row['optins_pending'],
                (int) $row['optins_live']
            ) . '</td>';
            echo '<td>';
            $status = (string) $row['status'];
            if ($status === OutletWindowStatus::DRAFT || $status === OutletWindowStatus::SCHEDULED) {
                echo '<button type="button" class="button button-primary" data-rest-click data-rest-path="admin/outlet-windows/'
                    . (int) $row['id'] . '/publish" data-rest-method="POST">'
                    . esc_html__('Publish', 'sutore-marketplace') . '</button> ';
            }
            if ($status === OutletWindowStatus::ACTIVE || $status === OutletWindowStatus::SCHEDULED) {
                echo '<button type="button" class="button" data-rest-click data-rest-path="admin/outlet-windows/'
                    . (int) $row['id'] . '/end" data-rest-method="POST" data-rest-confirm="'
                    . esc_attr__('End this outlet window and expire unsold listings?', 'sutore-marketplace') . '">'
                    . esc_html__('End', 'sutore-marketplace') . '</button>';
            }
            echo '</td>';
            echo '</tr>';

            $itemRows = is_array($row['items'] ?? null) ? $row['items'] : [];
            if ($itemRows !== []) {
                echo '<tr><td colspan="8"><ul style="margin:0.4em 0 0.8em 1.2em;">';
                foreach ($itemRows as $item) {
                    echo '<li>';
                    echo esc_html(sprintf(
                        '%s · %s · %s / %s',
                        (string) ($item['product_title'] ?? ''),
                        (string) ($item['size_label'] ?? ''),
                        (string) ($item['customer_sale_display'] ?? ''),
                        (string) ($item['seller_net_display'] ?? '')
                    ));
                    if ($status !== OutletWindowStatus::ENDED && (int) ($item['optins_live'] ?? 0) === 0) {
                        echo ' <button type="button" class="button-link-delete" data-rest-click data-rest-path="admin/outlet-windows/'
                            . (int) $row['id'] . '/items/' . (int) $item['id'] . '" data-rest-method="DELETE" data-rest-confirm="'
                            . esc_attr__('Remove this outlet item?', 'sutore-marketplace') . '">'
                            . esc_html__('Remove', 'sutore-marketplace') . '</button>';
                    }
                    echo '</li>';
                }
                echo '</ul></td></tr>';
            }
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('New outlet window', 'sutore-marketplace') . '</h2>';
        echo '<form class="sutore-mp-admin-rest" data-rest-path="admin/outlet-windows" data-rest-method="POST">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="outlet_name">' . esc_html__('Name', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="name" type="text" id="outlet_name" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="outlet_starts_at">' . esc_html__('Starts at', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="starts_at" type="datetime-local" id="outlet_starts_at" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="outlet_ends_at">' . esc_html__('Ends at', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="ends_at" type="datetime-local" id="outlet_ends_at" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="outlet_notes">' . esc_html__('Note', 'sutore-marketplace') . '</label></th>';
        echo '<td><textarea name="notes" id="outlet_notes" class="large-text" rows="3"></textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p>';
        submit_button(__('Create draft', 'sutore-marketplace'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';

        $openWindows = array_values(array_filter(
            $windows,
            static fn (array $row): bool => in_array((string) $row['status'], [
                OutletWindowStatus::DRAFT,
                OutletWindowStatus::SCHEDULED,
                OutletWindowStatus::ACTIVE,
            ], true)
        ));

        echo '<h2>' . esc_html__('Add outlet item', 'sutore-marketplace') . '</h2>';
        if ($openWindows === []) {
            echo '<p>' . esc_html__('Create a draft window first.', 'sutore-marketplace') . '</p>';
        } else {
            echo '<form class="sutore-mp-admin-rest" id="sutore-mp-outlet-item-form" data-rest-path="admin/outlet-windows/0/items" data-rest-method="POST">';
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row"><label for="outlet_window_id">' . esc_html__('Window', 'sutore-marketplace') . '</label></th><td>';
            echo '<select id="outlet_window_id" name="window_id" class="regular-text">';
            foreach ($openWindows as $row) {
                echo '<option value="' . (int) $row['id'] . '">' . esc_html((string) $row['name']) . ' (#' . (int) $row['id'] . ')</option>';
            }
            echo '</select></td></tr>';
            echo '<tr><th scope="row"><label for="outlet_parent_product_id">' . esc_html__('Parent product ID', 'sutore-marketplace') . '</label></th>';
            echo '<td><input name="parent_product_id" type="number" id="outlet_parent_product_id" class="regular-text" min="1" step="1" required />';
            echo '<p class="description">' . esc_html__('WooCommerce variable product ID.', 'sutore-marketplace') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="outlet_size_term_id">' . esc_html__('Size term ID', 'sutore-marketplace') . '</label></th>';
            echo '<td><input name="size_term_id" type="number" id="outlet_size_term_id" class="regular-text" min="1" step="1" required /></td></tr>';
            echo '<tr><th scope="row"><label for="outlet_customer_sale">' . esc_html__('Customer sale (TL)', 'sutore-marketplace') . '</label></th>';
            echo '<td><input name="customer_sale" type="number" id="outlet_customer_sale" class="regular-text" min="0" step="1" required />';
            echo '<p class="description">' . esc_html__('Price the customer pays during the window.', 'sutore-marketplace') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="outlet_seller_net">' . esc_html__('Seller asking (TL)', 'sutore-marketplace') . '</label></th>';
            echo '<td><input name="seller_net" type="number" id="outlet_seller_net" class="regular-text" min="0" step="1" required />';
            echo '<p class="description">' . esc_html__('Listing asking. Commission is still applied on payout.', 'sutore-marketplace') . '</p></td></tr>';
            echo '</tbody></table>';
            echo '<p>';
            submit_button(__('Add item', 'sutore-marketplace'), 'secondary', 'submit', false);
            echo '</p>';
            echo '</form>';
            echo '<script>document.getElementById("sutore-mp-outlet-item-form")?.addEventListener("submit",function(){var s=document.getElementById("outlet_window_id");if(s){this.setAttribute("data-rest-path","admin/outlet-windows/"+s.value+"/items");}});</script>';
        }

        echo '</div>';
    }
}
