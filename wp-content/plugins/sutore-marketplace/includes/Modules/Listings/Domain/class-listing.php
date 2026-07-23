<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class Listing
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $variationId,
        public readonly int $parentProductId,
        public readonly int $sizeTermId,
        public readonly int $merchantId,
        public readonly string $listingStatus,
        public readonly float $asking,
        public readonly string $conditionFingerprint,
        public readonly string $campaignStatus = 'none',
        public readonly ?int $campaignId = null,
        public readonly ?string $expireAt = null,
        public readonly bool $fastShipment = false,
        public readonly bool $hasInvoice = false,
        public readonly bool $isImported = false,
        public readonly ?string $productDesc = null,
        public readonly bool $isWinner = false,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly array $conditions = [],
        public readonly ?int $orderId = null,
        public readonly ?int $orderItemId = null,
        public readonly ?string $soldAt = null,
        public readonly ?int $sourcingRequestId = null,
        public readonly ?string $confirmDeadlineAt = null,
        public readonly ?string $sellerConfirmedAt = null,
        public readonly ?string $cargoDeadlineAt = null,
        public readonly ?string $merchantShippedAt = null,
        public readonly ?string $merchantShipmentCode = null,
        public readonly ?string $sutoreShipmentCode = null,
        public readonly ?string $sutoreShippedAt = null,
        public readonly ?string $merchantSnapshot = null,
        public readonly bool $confirmNoticeSent = false,
        public readonly bool $confirmPunished = false,
        public readonly bool $cargoNoticeSent = false,
        public readonly bool $cargoExpiredFlag = false,
        public readonly ?string $deliveredAt = null,
        public readonly ?string $returnWindowEndsAt = null,
        public readonly ?string $notes = null,
    ) {
    }

    public static function fromRow(object $row, array $conditions = []): self
    {
        return new self(
            id: isset($row->id) ? (int) $row->id : null,
            variationId: (int) $row->variation_id,
            parentProductId: (int) $row->parent_product_id,
            sizeTermId: (int) $row->size_term_id,
            merchantId: (int) $row->merchant_id,
            listingStatus: (string) $row->listing_status,
            asking: (float) $row->asking,
            conditionFingerprint: (string) $row->condition_fingerprint,
            campaignStatus: (string) ($row->campaign_status ?? 'none'),
            campaignId: isset($row->campaign_id) && $row->campaign_id !== null ? (int) $row->campaign_id : null,
            expireAt: $row->expire_at ?? null,
            fastShipment: !empty($row->fast_shipment),
            hasInvoice: !empty($row->has_invoice),
            isImported: !empty($row->is_imported),
            productDesc: $row->product_desc ?? null,
            isWinner: !empty($row->is_winner),
            createdAt: $row->created_at ?? null,
            updatedAt: $row->updated_at ?? null,
            conditions: $conditions,
            orderId: isset($row->order_id) && $row->order_id !== null ? (int) $row->order_id : null,
            orderItemId: isset($row->order_item_id) && $row->order_item_id !== null ? (int) $row->order_item_id : null,
            soldAt: $row->sold_at ?? null,
            sourcingRequestId: isset($row->sourcing_request_id) && $row->sourcing_request_id !== null
                ? (int) $row->sourcing_request_id
                : null,
            confirmDeadlineAt: $row->confirm_deadline_at ?? null,
            sellerConfirmedAt: $row->seller_confirmed_at ?? null,
            cargoDeadlineAt: $row->cargo_deadline_at ?? null,
            merchantShippedAt: $row->merchant_shipped_at ?? null,
            merchantShipmentCode: isset($row->merchant_shipment_code) && $row->merchant_shipment_code !== null
                ? (string) $row->merchant_shipment_code
                : null,
            sutoreShipmentCode: isset($row->sutore_shipment_code) && $row->sutore_shipment_code !== null
                ? (string) $row->sutore_shipment_code
                : null,
            sutoreShippedAt: $row->sutore_shipped_at ?? null,
            merchantSnapshot: isset($row->merchant_snapshot) && $row->merchant_snapshot !== null
                ? (string) $row->merchant_snapshot
                : null,
            confirmNoticeSent: !empty($row->confirm_notice_sent),
            confirmPunished: !empty($row->confirm_punished),
            cargoNoticeSent: !empty($row->cargo_notice_sent),
            cargoExpiredFlag: !empty($row->cargo_expired_flag),
            deliveredAt: $row->delivered_at ?? null,
            returnWindowEndsAt: $row->return_window_ends_at ?? null,
            notes: isset($row->notes) && $row->notes !== null ? (string) $row->notes : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'variation_id' => $this->variationId,
            'parent_product_id' => $this->parentProductId,
            'size_term_id' => $this->sizeTermId,
            'merchant_id' => $this->merchantId,
            'listing_status' => $this->listingStatus,
            'asking' => $this->asking,
            'condition_fingerprint' => $this->conditionFingerprint,
            'campaign_status' => $this->campaignStatus,
            'campaign_id' => $this->campaignId,
            'expire_at' => $this->expireAt,
            'fast_shipment' => $this->fastShipment,
            'has_invoice' => $this->hasInvoice,
            'is_imported' => $this->isImported,
            'product_desc' => $this->productDesc,
            'is_winner' => $this->isWinner,
            'conditions' => $this->conditions,
            'order_id' => $this->orderId,
            'order_item_id' => $this->orderItemId,
            'sold_at' => $this->soldAt,
            'sourcing_request_id' => $this->sourcingRequestId,
            'is_sourcing' => $this->sourcingRequestId !== null,
            'confirm_deadline_at' => $this->confirmDeadlineAt,
            'seller_confirmed_at' => $this->sellerConfirmedAt,
            'cargo_deadline_at' => $this->cargoDeadlineAt,
            'merchant_shipped_at' => $this->merchantShippedAt,
            'merchant_shipment_code' => $this->merchantShipmentCode,
            'sutore_shipment_code' => $this->sutoreShipmentCode,
            'sutore_shipped_at' => $this->sutoreShippedAt,
            'confirm_notice_sent' => $this->confirmNoticeSent,
            'confirm_punished' => $this->confirmPunished,
            'cargo_notice_sent' => $this->cargoNoticeSent,
            'cargo_expired_flag' => $this->cargoExpiredFlag,
            'delivered_at' => $this->deliveredAt,
            'return_window_ends_at' => $this->returnWindowEndsAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
