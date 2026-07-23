<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Admin;

use SutoreMarketplace\Admin\AdminMenu;

use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class EventsTable extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'event', 'plural' => 'events', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return [
            'id' => __('ID', 'sutore-marketplace'),
            'event_type' => __('Type', 'sutore-marketplace'),
            'listing_id' => __('Listing', 'sutore-marketplace'),
            'merchant_id' => __('Seller', 'sutore-marketplace'),
            'visibility' => __('Visibility', 'sutore-marketplace'),
            'created_at' => __('Date', 'sutore-marketplace'),
            'payload' => __('Payload', 'sutore-marketplace'),
        ];
    }

    public function prepare_items(): void
    {
        $result = (new ListingEventsRepository())->query([
            'page' => $this->get_pagenum(),
            'per_page' => 40,
            'event_type' => sanitize_key((string) ($_REQUEST['event_type'] ?? '')),
            'merchant_id' => (int) ($_REQUEST['merchant_id'] ?? 0) ?: null,
            'listing_id' => (int) ($_REQUEST['listing_id'] ?? 0) ?: null,
        ]);
        $this->items = $result['items'];
        $this->set_pagination_args(['total_items' => $result['total'], 'per_page' => 40]);
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="filter-event-type">' . esc_html__('Event type', 'sutore-marketplace') . '</label>';
        printf(
            '<input type="text" class="regular-text" name="event_type" id="filter-event-type" value="%s" placeholder="%s" />',
            esc_attr((string) ($_REQUEST['event_type'] ?? '')),
            esc_attr__('event_type', 'sutore-marketplace')
        );
        echo '<label class="screen-reader-text" for="filter-event-listing">' . esc_html__('Listing ID', 'sutore-marketplace') . '</label>';
        printf(
            '<input type="number" class="small-text" name="listing_id" id="filter-event-listing" value="%s" placeholder="%s" />',
            esc_attr((string) ($_REQUEST['listing_id'] ?? '')),
            esc_attr__('Listing ID', 'sutore-marketplace')
        );
        echo '<label class="screen-reader-text" for="filter-event-merchant">' . esc_html__('Merchant ID', 'sutore-marketplace') . '</label>';
        printf(
            '<input type="number" class="small-text" name="merchant_id" id="filter-event-merchant" value="%s" placeholder="%s" />',
            esc_attr((string) ($_REQUEST['merchant_id'] ?? '')),
            esc_attr__('Merchant ID', 'sutore-marketplace')
        );
        submit_button(__('Filter', 'sutore-marketplace'), '', 'filter_action', false);
        echo '</div>';
    }

    public function column_default($item, $column_name)
    {
        if ($column_name === 'payload') {
            return '<code>' . esc_html(mb_substr((string) $item->payload, 0, 160)) . '</code>';
        }
        if ($column_name === 'listing_id' && !empty($item->listing_id)) {
            $id = (int) $item->listing_id;
            $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($id);
            $label = '#' . $id;
            if (!$listing) {
                $label .= ' (' . __('deleted', 'sutore-marketplace') . ')';
            }

            return esc_html($label);
        }
        if ($column_name === 'event_type') {
            $type = (string) ($item->event_type ?? '');
            return esc_html(\SutoreMarketplace\Modules\Listings\Domain\ListingEventType::label($type))
                . '<br /><code style="font-size:11px;">' . esc_html($type) . '</code>';
        }
        return esc_html((string) ($item->{$column_name} ?? ''));
    }

    public function no_items(): void
    {
        esc_html_e('Event not found.', 'sutore-marketplace');
    }

    public function renderPage(): void
    {
        $this->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="sutore-marketplace-events" />';
        echo '<input type="hidden" name="tab" value="events" />';
        $this->display();
        echo '</form>';
    }
}
