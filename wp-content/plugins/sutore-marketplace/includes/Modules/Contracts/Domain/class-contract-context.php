<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Domain;

final class ContractContext
{
    /**
     * @param list<array{
     *     merchant_id:int,
     *     fullname:string,
     *     city:string,
     *     is_platform:bool,
     *     items:list<array{
     *         name:string,
     *         quantity:int,
     *         product_price:string,
     *         service_cost:string,
     *         insurance_cost:string,
     *         total_price:string,
     *         total_raw:float
     *     }>
     * }> $merchantBlocks
     */
    public function __construct(
        public readonly array $merchantBlocks,
        public readonly string $billingName = '',
        public readonly string $address = '',
        public readonly string $phone = '',
        public readonly string $email = '',
        public readonly string $paymentMethod = '',
        public readonly string $shipmentLabel = '',
        public readonly bool $isCheckoutPreview = true,
    ) {
    }
}
