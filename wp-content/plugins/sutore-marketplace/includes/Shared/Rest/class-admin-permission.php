<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Rest;

use SutoreMarketplace\Admin\AdminMenu;

final class AdminPermission
{
    public static function canManage(): bool
    {
        return is_user_logged_in() && current_user_can(AdminMenu::CAP);
    }
}
