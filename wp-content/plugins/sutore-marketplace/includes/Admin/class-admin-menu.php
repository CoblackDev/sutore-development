<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Modules\Listings\Admin\CampaignsPage;
use SutoreMarketplace\Modules\Listings\Admin\EventsTable;
use SutoreMarketplace\Modules\Listings\Admin\OutletPage;
use SutoreMarketplace\Modules\Tasks\Admin\TasksPage;

final class AdminMenu
{
    public const CAP = 'manage_woocommerce';
    public const PARENT = 'sutore-marketplace-settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 9);
    }

    public function menu(): void
    {
        // Parent callback must be empty: WP also hooks the first submenu with the same
        // slug, and registering render on both causes the page to render twice.
        add_menu_page(
            __('Sutore Marketplace', 'sutore-marketplace'),
            __('Sutore Marketplace', 'sutore-marketplace'),
            self::CAP,
            self::PARENT,
            static function (): void {},
            'dashicons-store',
            58
        );

        $pages = [
            [self::PARENT, __('Settings', 'sutore-marketplace'), __('Settings', 'sutore-marketplace'), [new SettingsPage(), 'render']],
            ['sutore-marketplace-campaigns', __('Campaigns', 'sutore-marketplace'), __('Campaigns', 'sutore-marketplace'), [new CampaignsPage(), 'render']],
            ['sutore-marketplace-outlet', __('Outlet', 'sutore-marketplace'), __('Outlet', 'sutore-marketplace'), [new OutletPage(), 'render']],
            ['sutore-marketplace-tasks', __('Opportunities', 'sutore-marketplace'), __('Opportunity templates', 'sutore-marketplace'), [new TasksPage(), 'render']],
            ['sutore-marketplace-events', __('Events', 'sutore-marketplace'), __('Events', 'sutore-marketplace'), [$this, 'renderEvents']],
        ];

        foreach ($pages as [$slug, $menuTitle, $pageTitle, $callback]) {
            add_submenu_page(self::PARENT, $pageTitle, $menuTitle, self::CAP, $slug, $callback);
        }
    }

    public function renderEvents(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Events', 'sutore-marketplace') . '</h1>';
        echo '<hr class="wp-header-end" />';
        (new EventsTable())->renderPage();
        echo '</div>';
    }
}
