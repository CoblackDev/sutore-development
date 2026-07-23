<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Domain;

enum ContractType: string
{
    case PreInformation = 'pre_information';
    case DistanceSales = 'distance_sales';

    public function label(): string
    {
        return match ($this) {
            self::PreInformation => __('Pre-Information Form', 'sutore-marketplace'),
            self::DistanceSales => __('Distance Selling Agreement', 'sutore-marketplace'),
        };
    }

    public function templateFile(): string
    {
        return match ($this) {
            self::PreInformation => 'pre-information.php',
            self::DistanceSales => 'distance-sales.php',
        };
    }
}
