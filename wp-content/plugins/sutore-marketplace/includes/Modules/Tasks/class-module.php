<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks;

use SutoreMarketplace\Modules\Tasks\Rest\AdminTasksController;
use SutoreMarketplace\Modules\Tasks\Rest\TasksController;

final class Module
{
    public static function boot(): void
    {
        (new TasksController())->register();
        (new AdminTasksController())->register();
    }
}
