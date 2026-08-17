<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Domain;

final class ShipmentMeta
{
    public const TYPE = '_sutore_marketplace_shipment_type';
    public const ETA_DAYS = '_sutore_marketplace_eta_days';
    public const DEADLINE_LABEL = 'sutore_shipment_deadline';
    public const DEADLINE_TIMESTAMP = 'sutore_shipment_deadline_timestamp';
}
