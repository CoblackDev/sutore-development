<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Domain\StaffBulkAction;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class MerchantFulfillmentCommands
{
    public function __construct(
        private readonly FulfillmentCommandSupport $support,
        private readonly FulfillmentRepository $repo,
        private readonly SourcingSwapCommands $sourcing,
    ) {
    }

    public function merchantConfirmSale(int $listingId, int $merchantId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::SOLD) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('This sale cannot be confirmed.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        $cargoSeconds = Settings::cargoDeadlineSecondsForShipmentType($shipmentType);
        $cargoDeadline = DeadlineCalculator::fromNow($cargoSeconds);
        $confirmDeadline = (string) ($row->confirm_deadline_at ?? '');
        $onTime = $confirmDeadline === '' || current_time('mysql') <= $confirmDeadline;

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::CONFIRMED,
            'seller_confirmed_at' => current_time('mysql'),
            'cargo_deadline_at' => $cargoDeadline,
            'cargo_notice_sent' => 0,
            'cargo_expired_flag' => 0,
        ]);

        $bridge = $this->support->bridge();
        $listing = $bridge->find($listingId);
        if ($listing && $order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->support->templateVars($order, $listing, $title);
            $vars['cargo_hours'] = (string) (int) ($cargoSeconds / HOUR_IN_SECONDS);

            Notifications::sendEvent('seller_confirmed_customer', (string) $order->get_billing_phone(), $vars);
            $this->support->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRMED,
                $listing,
                $order,
                ['variation_id' => $listingId, 'cargo_hours' => (int) ($cargoSeconds / HOUR_IN_SECONDS)]
            );

            if ($shipmentType === 'international' && Settings::get('international_invoice_required', true)) {
                Notifications::sendEvent('international_warning', Notifications::merchantPhone($merchantId), $vars);
            }
            if ($shipmentType === 'express' && Settings::get('express_block_carrier_shipment', true)) {
                Notifications::sendEvent('express_warning', Notifications::merchantPhone($merchantId), $vars);
                Notifications::notifyExpress('express_warning', $vars);
            }
        }

        WebhookNotifier::dispatch('fulfillment.confirmed', [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ]);

        $this->support->logListingEvent('fulfillment_seller_confirmed', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'seller_confirmed_at' => current_time('mysql'),
            'cargo_deadline_at' => $cargoDeadline,
            'on_time' => $onTime,
        ], $row);

        if ($onTime) {
            (new \SutoreMarketplace\Modules\Tasks\Services\TaskProgressService())
                ->incrementByTemplate($merchantId, \SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate::RECOVERY_TIMELY_CONFIRM);
        }
        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

        return true;
    }

    public function merchantSubmitShipment(int $listingId, int $merchantId, string $shipmentCode): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }
        if ($row->fulfillment_status !== ListingStatus::CONFIRMED) {
            return new \WP_Error('sutore_marketplace_fulfillment_status', __('Shipping can only be entered for confirmed sales.', 'sutore-marketplace'));
        }

        $code = sanitize_text_field($shipmentCode);
        if (!Settings::shipmentCodeValid($code)) {
            return new \WP_Error('sutore_marketplace_fulfillment_code', __('Enter a valid shipping tracking number.', 'sutore-marketplace'));
        }

        $cargoDeadline = (string) ($row->cargo_deadline_at ?? '');
        $shippedAt = current_time('mysql');
        $shipOnTime = $cargoDeadline === '' || $shippedAt <= $cargoDeadline;

        $this->repo->update($listingId, [
            'fulfillment_status' => ListingStatus::SHIPPED_TO_SUTORE,
            'merchant_shipment_code' => $code,
            'merchant_shipped_at' => $shippedAt,
        ]);

        $listing = $this->support->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if ($listing && $order) {
            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $vars = $this->support->templateVars($order, $listing, $title);
            $vars['track_code'] = $code;

            Notifications::sendEvent('shipped_to_sutore_customer', (string) $order->get_billing_phone(), $vars);
            $this->support->dispatchMerchantNotification(
                NotificationType::FULFILLMENT_SHIPPED_TO_SUTORE,
                $listing,
                $order,
                ['variation_id' => $listingId, 'track_code' => $code]
            );
        }

        WebhookNotifier::dispatch('fulfillment.shipped_to_sutore', [
            'variation_id' => $listingId,
            'track_code' => $code,
        ]);

        $this->support->logListingEvent('fulfillment_shipped_to_sutore', $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
            'merchant_shipment_code' => $code,
            'on_time' => $shipOnTime,
        ], $row);

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

        return true;
    }

    public function merchantCancelSale(int $listingId, int $merchantId): true|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized action.', 'sutore-marketplace'));
        }

        if ((string) $row->fulfillment_status !== ListingStatus::SOLD) {
            return new \WP_Error(
                'sutore_marketplace_fulfillment_status',
                __('Only sales awaiting confirmation can be cancelled.', 'sutore-marketplace')
            );
        }

        $listing = $this->support->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('Product not found.', 'sutore-marketplace'));
        }

        $marked = $this->sourcing->markAsPreOrder($listingId, 'seller_cancelled');
        if (is_wp_error($marked)) {
            return $marked;
        }

        $eventsRepo = new ListingEventsRepository();
        $this->support->logListingEvent(\SutoreMarketplace\Modules\Listings\Domain\ListingEventType::SELLER_CANCELLED, $listing, [
            'variation_id' => $listingId,
            'order_id' => (int) $row->order_id,
        ], $row);

        if ($eventsRepo->hasPreOrderAcceptance($merchantId, $listingId)) {
            $this->support->logListingEvent(
                \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::PRE_ORDER_COMMITMENT_BROKEN,
                $listing,
                [
                    'variation_id' => $listingId,
                    'order_id' => (int) $row->order_id,
                    'reason' => 'seller_cancelled',
                ],
                $row
            );
        }

        (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);

        return true;
    }

    /** @return array<string, mixed> */
    public function merchantDetails(int $listingId, int $merchantId): array|\WP_Error
    {
        $row = $this->repo->find($listingId);
        if (!$row || (int) $row->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_marketplace_fulfillment_forbidden', __('Unauthorized.', 'sutore-marketplace'));
        }
        $listing = $this->support->bridge()->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_fulfillment_listing', __('No products.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $shipmentType = $order ? (string) $order->get_meta(ShipmentMeta::TYPE) : 'standard';
        $cargoHours = (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS);

        return [
            'variation_id' => $listingId,
            'status' => $row->fulfillment_status,
            'status_label' => ListingStatus::label($row->fulfillment_status),
            'order_id' => (int) $row->order_id,
            'asking' => $listing->asking,
            'asking_display' => MarketplacePricing::formatTl($listing->asking),
            'net_payout_display' => MarketplacePricing::formatTl(MarketplacePricing::merchantPayout($listing)),
            'confirm_deadline_at' => $row->confirm_deadline_at,
            'cargo_deadline_at' => $row->cargo_deadline_at,
            'merchant_shipment_code' => $row->merchant_shipment_code,
            'sutore_shipment_code' => $row->sutore_shipment_code,
            'yurtici_customer_code' => Settings::yurticiCustomerCode(),
            'shipment_hint' => sprintf(
                __('Deliver your product in a double box to Yurtici Kargo (%s) within %d hours.', 'sutore-marketplace'),
                $cargoHours,
                Settings::yurticiCustomerCode()
            ),
            'can_confirm' => $row->fulfillment_status === ListingStatus::SOLD,
            'can_ship' => $row->fulfillment_status === ListingStatus::CONFIRMED,
            'can_track' => in_array($row->fulfillment_status, [
                ListingStatus::SHIPPED_TO_SUTORE,
                ListingStatus::ARRIVED_TO_SUTORE,
                ListingStatus::VERIFIED,
            ], true) && !empty($row->merchant_shipment_code),
        ];
    }

    public function processDeadline(object $row): void
    {
        $listing = $this->support->bridge()->find((int) $row->variation_id);
        if ($listing && ImportedProductService::isVariationImported($listing->variationId)) {
            return;
        }

        if ($row->fulfillment_status === ListingStatus::SOLD) {
            $this->processConfirmDeadline($row);
        } elseif ($row->fulfillment_status === ListingStatus::CONFIRMED) {
            $this->processCargoDeadline($row);
        }
    }

    private function processConfirmDeadline(object $row): void
    {
        $listingId = (int) $row->variation_id;
        $listing = $this->support->bridge()->find($listingId);
        $order = wc_get_order((int) $row->order_id);
        if (!$listing || !$order) {
            return;
        }

        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $this->support->templateVars($order, $listing, $title);
        $vars['confirm_hours'] = (string) (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS);
        $customerPhone = (string) $order->get_billing_phone();

        if (!(int) $row->confirm_notice_sent) {
            $claimed = $this->repo->claimWhile(
                $listingId,
                ListingStatus::SOLD,
                ['confirm_notice_sent' => 0],
                [
                    'confirm_notice_sent' => 1,
                    'confirm_deadline_at' => DeadlineCalculator::fromNow(Settings::confirmGraceSeconds()),
                ]
            );
            if (!$claimed) {
                return;
            }

            $this->support->dispatchMerchantNotification(
                NotificationType::SALE_CONFIRM_REMINDER,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'confirm_hours' => (int) (Settings::confirmGraceSeconds() / HOUR_IN_SECONDS),
                ]
            );
            $this->support->logListingEvent('fulfillment_confirm_reminder', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->confirm_punished) {
            $marked = $this->sourcing->markAsPreOrder($listingId, 'confirm_deadline');
            if (is_wp_error($marked)) {
                return;
            }
            Notifications::sendEvent('suspended_customer', $customerPhone, $vars, true);
            $this->support->dispatchMerchantNotification(
                NotificationType::SALE_SUSPENDED,
                $listing,
                $order,
                ['variation_id' => $listingId]
            );
            (new AskMerchants())->notifyForSize($listing->parentProductId, $listing->sizeTermId, $listing->asking, $title);
            $eventsRepo = new ListingEventsRepository();
            $merchantId = (int) $row->merchant_id;
            if ($eventsRepo->hasPreOrderAcceptance($merchantId, $listingId)) {
                $this->support->logListingEvent(
                    \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::PRE_ORDER_COMMITMENT_BROKEN,
                    $listing,
                    [
                        'variation_id' => $listingId,
                        'order_id' => (int) $row->order_id,
                        'reason' => 'confirm_deadline',
                    ],
                    $row
                );
            } else {
                $this->support->logListingEvent(
                    \SutoreMarketplace\Modules\Listings\Domain\ListingEventType::CONFIRM_DEADLINE_MISSED,
                    $listing,
                    [
                        'variation_id' => $listingId,
                        'order_id' => (int) $row->order_id,
                    ],
                    $row
                );
            }
            (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())->refreshMerchant($merchantId);
            WebhookNotifier::dispatch('listing.pre_order', [
                'variation_id' => $listingId,
                'reason' => 'confirm_deadline',
            ]);
        }
    }

    private function processCargoDeadline(object $row): void
    {
        $listingId = (int) $row->variation_id;
        $listing = $this->support->bridge()->find($listingId);
        if (!$listing) {
            return;
        }

        $order = wc_get_order((int) $row->order_id);
        if (!$order) {
            return;
        }

        $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
        $vars = $this->support->templateVars($order, $listing, $title);
        $deadlineTs = $row->cargo_deadline_at ? strtotime((string) $row->cargo_deadline_at) : false;
        $now = current_time('timestamp');

        if ($deadlineTs === false) {
            return;
        }

        if (!(int) $row->cargo_notice_sent && $now >= $deadlineTs - Settings::cargoReminderSeconds()) {
            $claimed = $this->repo->claimWhile(
                $listingId,
                ListingStatus::CONFIRMED,
                ['cargo_notice_sent' => 0],
                ['cargo_notice_sent' => 1]
            );
            if (!$claimed) {
                return;
            }

            $shipmentType = (string) $order->get_meta(ShipmentMeta::TYPE);
            $this->support->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_REMINDER,
                $listing,
                $order,
                [
                    'variation_id' => $listingId,
                    'cargo_hours' => (int) (Settings::cargoDeadlineSecondsForShipmentType($shipmentType) / HOUR_IN_SECONDS),
                ]
            );
            $this->support->logListingEvent('fulfillment_cargo_reminder', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
            ], $row);
            return;
        }

        if (!(int) $row->cargo_expired_flag && $now >= $deadlineTs) {
            $claimed = $this->repo->claimWhile(
                $listingId,
                ListingStatus::CONFIRMED,
                ['cargo_expired_flag' => 0],
                ['cargo_expired_flag' => 1]
            );
            if (!$claimed) {
                return;
            }

            Notifications::sendEvent('seller_cargo_expired', (string) $order->get_billing_phone(), $vars, true);
            $this->support->dispatchMerchantNotification(
                NotificationType::SALE_CARGO_EXPIRED,
                $listing,
                $order,
                ['variation_id' => $listingId]
            );
            $this->support->logListingEvent('fulfillment_cargo_expired', $listing, [
                'variation_id' => $listingId,
                'order_id' => (int) $row->order_id,
                'cargo_deadline_at' => (string) $row->cargo_deadline_at,
            ], $row);
            (new \SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService())
                ->refreshMerchant((int) $row->merchant_id);
        }
    }
}