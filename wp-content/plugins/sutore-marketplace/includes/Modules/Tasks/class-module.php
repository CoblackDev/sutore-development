<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks;

use SutoreMarketplace\Modules\Tasks\Rest\AdminTasksController;
use SutoreMarketplace\Modules\Tasks\Rest\TasksController;
use SutoreMarketplace\Modules\Tasks\Services\OpportunityCardService;

final class Module
{
    public static function boot(): void
    {
        (new TasksController())->register();
        (new AdminTasksController())->register();
        add_action('init', static function (): void {
            (new OpportunityCardService())->ensureSystemTemplates();
        }, 20);
    }
}
