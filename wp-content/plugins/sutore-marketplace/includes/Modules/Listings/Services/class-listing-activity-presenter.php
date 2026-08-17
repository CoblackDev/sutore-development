<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;

final class ListingActivityPresenter
{
    public function __construct(
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
    ) {
    }

    /**
     * @return list<array{date:string,event_label:string,event_type:string,actor:string,summary:string}>
     */
    public function present(
        int $variationId,
        ?string $visibility = null,
        int $limit = 50
    ): array {
        if ($variationId <= 0) {
            return [];
        }

        $rows = [];
        foreach ($this->events->forListing($variationId, $limit, $visibility) as $event) {
            $payload = json_decode((string) ($event->payload ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $actor = (string) ($payload['actor_login'] ?? '');
            if ($actor === 'system') {
                $actor = __('system', 'sutore-marketplace');
            } elseif ($actor === '') {
                $actor = '—';
            }

            $createdAt = (string) ($event->created_at ?? '');
            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
            $eventType = (string) ($event->event_type ?? '');

            $rows[] = [
                'date' => $timestamp
                    ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
                    : $createdAt,
                'event_label' => ListingEventType::label($eventType),
                'event_type' => $eventType,
                'actor' => $actor,
                'summary' => $this->payloadSummary($payload, $eventType),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private function payloadSummary(array $payload, string $eventType): string
    {
        $parts = [];

        if (!empty($payload['order_id'])) {
            /* translators: %d: WooCommerce order ID */
            $parts[] = sprintf(__('Order #%d', 'sutore-marketplace'), (int) $payload['order_id']);
        }

        if (!empty($payload['from_status']) && !empty($payload['to_status'])) {
            /* translators: 1: previous status label 2: new status label */
            $parts[] = sprintf(
                __('%1$s → %2$s', 'sutore-marketplace'),
                ListingStatus::label((string) $payload['from_status']),
                ListingStatus::label((string) $payload['to_status'])
            );
        } elseif (!empty($payload['old_status']) && !empty($payload['new_status'])) {
            /* translators: 1: previous listing status label 2: new listing status label */
            $parts[] = sprintf(
                __('%1$s → %2$s', 'sutore-marketplace'),
                ListingStatus::label((string) $payload['old_status']),
                ListingStatus::label((string) $payload['new_status'])
            );
        } elseif (isset($payload['old_asking'], $payload['new_asking'])) {
            /* translators: 1: previous price 2: new price */
            $parts[] = sprintf(
                __('%1$s TL → %2$s TL', 'sutore-marketplace'),
                number_format_i18n((int) $payload['old_asking']),
                number_format_i18n((int) $payload['new_asking'])
            );
        } elseif (!empty($payload['to_status'])) {
            $parts[] = ListingStatus::label((string) $payload['to_status']);
        } elseif (!empty($payload['listing_status'])) {
            $parts[] = ListingStatus::label((string) $payload['listing_status']);
        } elseif (!empty($payload['new_status'])) {
            $parts[] = ListingStatus::label((string) $payload['new_status']);
        }

        if (!empty($payload['merchant_shipment_code'])) {
            /* translators: %s: carrier tracking number */
            $parts[] = sprintf(__('Tracking: %s', 'sutore-marketplace'), (string) $payload['merchant_shipment_code']);
        }

        if (!empty($payload['sutore_shipment_code'])) {
            /* translators: %s: Sutore outbound tracking number */
            $parts[] = sprintf(__('Sutore tracking: %s', 'sutore-marketplace'), (string) $payload['sutore_shipment_code']);
        }

        if (isset($payload['old_position'], $payload['new_position'])) {
            /* translators: 1: previous queue position 2: new queue position */
            $parts[] = sprintf(
                __('Queue #%1$d → #%2$d', 'sutore-marketplace'),
                (int) $payload['old_position'],
                (int) $payload['new_position']
            );
        }

        foreach (['cargo_deadline_at', 'seller_confirmed_at', 'sold_at'] as $dateKey) {
            if (empty($payload[$dateKey])) {
                continue;
            }
            $timestamp = strtotime((string) $payload[$dateKey]);
            if ($timestamp === false) {
                continue;
            }
            $formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            if ($dateKey === 'cargo_deadline_at') {
                /* translators: %s: formatted ship-by datetime */
                $parts[] = sprintf(__('Ship by: %s', 'sutore-marketplace'), $formatted);
            } elseif ($dateKey === 'seller_confirmed_at') {
                /* translators: %s: formatted confirmation datetime */
                $parts[] = sprintf(__('Confirmed: %s', 'sutore-marketplace'), $formatted);
            } else {
                /* translators: %s: formatted sale datetime */
                $parts[] = sprintf(__('Sold at: %s', 'sutore-marketplace'), $formatted);
            }
            break;
        }

        if (!empty($payload['reason'])) {
            /* translators: %s: release or suspension reason */
            $parts[] = sprintf(__('Reason: %s', 'sutore-marketplace'), $this->detachReasonLabel((string) $payload['reason']));
        }

        if ($eventType === 'order_listing_attached' && !empty($payload['attachment_mode'])) {
            $parts[] = $this->attachmentModeLabel((string) $payload['attachment_mode']);
        }

        if ($eventType === 'order_listing_swapped') {
            if (!empty($payload['role'])) {
                $parts[] = $payload['role'] === 'incoming'
                    ? __('Incoming listing', 'sutore-marketplace')
                    : __('Outgoing listing', 'sutore-marketplace');
            }
            if (!empty($payload['old_variation_id']) && !empty($payload['new_variation_id'])) {
                /* translators: 1: previous variation ID 2: new variation ID */
                $parts[] = sprintf(
                    __('Variation #%1$d → #%2$d', 'sutore-marketplace'),
                    (int) $payload['old_variation_id'],
                    (int) $payload['new_variation_id']
                );
            }
        }

        if ($eventType === 'campaign_offer_sent') {
            if (!empty($payload['campaign_id'])) {
                /* translators: %d: campaign ID */
                $parts[] = sprintf(__('Campaign #%d', 'sutore-marketplace'), (int) $payload['campaign_id']);
            }
            if (isset($payload['seller_discount'], $payload['platform_discount'])) {
                $sellerType = (string) ($payload['seller_discount_type'] ?? 'fixed');
                $platformType = (string) ($payload['platform_discount_type'] ?? 'fixed');
                $sellerValue = (float) ($payload['seller_discount_value'] ?? $payload['seller_discount']);
                $platformValue = (float) ($payload['platform_discount_value'] ?? $payload['platform_discount']);
                $parts[] = sprintf(
                    /* translators: 1: seller discount label, 2: platform discount label */
                    __('Seller −%1$s · Platform −%2$s', 'sutore-marketplace'),
                    CampaignDiscountType::offerLabel($sellerType, $sellerValue, (float) $payload['seller_discount']),
                    CampaignDiscountType::offerLabel($platformType, $platformValue, (float) $payload['platform_discount'])
                );
            }
        }

        if ($eventType === 'campaign_applied') {
            if (!empty($payload['campaign_id'])) {
                /* translators: %d: campaign ID */
                $parts[] = sprintf(__('Campaign #%d', 'sutore-marketplace'), (int) $payload['campaign_id']);
            }
            if (isset($payload['asking_before'], $payload['asking_effective'])) {
                /* translators: 1: previous asking, 2: new asking */
                $parts[] = sprintf(
                    __('Asking %1$s TL → %2$s TL', 'sutore-marketplace'),
                    number_format_i18n((int) $payload['asking_before']),
                    number_format_i18n((int) $payload['asking_effective'])
                );
            }
        }

        if (in_array($eventType, ['campaign_cleared', 'campaign_offer_declined', 'campaign_offer_expired'], true)) {
            if (!empty($payload['campaign_id'])) {
                /* translators: %d: campaign ID */
                $parts[] = sprintf(__('Campaign #%d', 'sutore-marketplace'), (int) $payload['campaign_id']);
            }
            if (isset($payload['asking_restored'])) {
                /* translators: %s: restored asking price */
                $parts[] = sprintf(
                    __('Asking restored: %s TL', 'sutore-marketplace'),
                    number_format_i18n((int) $payload['asking_restored'])
                );
            }
        }

        if (in_array($eventType, ['sale_commission_locked', 'listing_commission_set', 'payout_commission_adjusted'], true)) {
            if (array_key_exists('previous_percent', $payload) && array_key_exists('commission_percent', $payload)) {
                $from = $payload['previous_percent'] === null || $payload['previous_percent'] === ''
                    ? __('none', 'sutore-marketplace')
                    : ((string) $payload['previous_percent'] . '%');
                $to = $payload['commission_percent'] === null || $payload['commission_percent'] === ''
                    ? __('none', 'sutore-marketplace')
                    : ((string) $payload['commission_percent'] . '%');
                /* translators: 1: previous commission percent 2: new commission percent */
                $parts[] = sprintf(__('%1$s → %2$s', 'sutore-marketplace'), $from, $to);
            } elseif (isset($payload['commission_percent']) && $payload['commission_percent'] !== null && $payload['commission_percent'] !== '') {
                /* translators: %s: commission percent */
                $parts[] = sprintf(__('Commission %s%%', 'sutore-marketplace'), (string) $payload['commission_percent']);
            }
            if (!empty($payload['source'])) {
                $parts[] = (string) $payload['source'];
            }
        }

        if (!empty($payload['staff_note'])) {
            /* translators: %s: staff note text */
            $parts[] = sprintf(__('Staff note: %s', 'sutore-marketplace'), (string) $payload['staff_note']);
        }

        if (!empty($payload['payment_ref'])) {
            /* translators: %s: payout reference */
            $parts[] = sprintf(__('Payment ref: %s', 'sutore-marketplace'), (string) $payload['payment_ref']);
        }

        if (!empty($payload['approval_mode'])) {
            if ($payload['approval_mode'] === 'auto') {
                $parts[] = __('Auto-approved (merchant tier)', 'sutore-marketplace');
            } elseif ($payload['approval_mode'] === 'manual') {
                $parts[] = __('Manually approved', 'sutore-marketplace');
            }
        }

        if (!empty($payload['deletion_source'])) {
            $parts[] = $this->deletionSourceLabel((string) $payload['deletion_source']);
        }

        if (!empty($payload['parent_product_id'])) {
            /* translators: %d: WooCommerce parent product ID */
            $parts[] = sprintf(__('Parent product #%d', 'sutore-marketplace'), (int) $payload['parent_product_id']);
        }

        if ($eventType === 'listing_condition_changed' && !empty($payload['changed_keys']) && is_array($payload['changed_keys'])) {
            $labels = array_map(
                fn ($key) => $this->conditionLabel((string) $key),
                $payload['changed_keys']
            );
            $parts[] = implode(', ', $labels);
        }

        if ($eventType === 'listing_duration_changed') {
            if (isset($payload['old_duration_days'], $payload['new_duration_days'])
                && (int) $payload['old_duration_days'] !== (int) $payload['new_duration_days']) {
                $parts[] = sprintf(
                    /* translators: 1: previous day count 2: new day count */
                    __('Duration: %1$d → %2$d days', 'sutore-marketplace'),
                    (int) $payload['old_duration_days'],
                    (int) $payload['new_duration_days']
                );
            }
        }

        if ($eventType === 'listing_shipping_changed') {
            $shippingParts = [];
            if (isset($payload['old_fast_shipment'], $payload['new_fast_shipment'])
                && (int) $payload['old_fast_shipment'] !== (int) $payload['new_fast_shipment']) {
                $shippingParts[] = sprintf(
                    /* translators: 1: previous on/off label 2: new on/off label */
                    __('Fast shipping: %1$s → %2$s', 'sutore-marketplace'),
                    $this->onOffLabel((int) $payload['old_fast_shipment']),
                    $this->onOffLabel((int) $payload['new_fast_shipment'])
                );
            }
            if (isset($payload['old_has_invoice'], $payload['new_has_invoice'])
                && (int) $payload['old_has_invoice'] !== (int) $payload['new_has_invoice']) {
                $shippingParts[] = sprintf(
                    /* translators: 1: previous on/off label 2: new on/off label */
                    __('International shipping: %1$s → %2$s', 'sutore-marketplace'),
                    $this->onOffLabel((int) $payload['old_has_invoice']),
                    $this->onOffLabel((int) $payload['new_has_invoice'])
                );
            }
            if ($shippingParts !== []) {
                $parts[] = implode(' · ', $shippingParts);
            }
        }

        if (!empty($payload['below_retail'])) {
            $parts[] = __('Below retail', 'sutore-marketplace');
        }

        if (!empty($payload['asking']) && !in_array($eventType, ['listing_created', 'listing_status_changed', 'listing_price_changed'], true)) {
            /* translators: %s: listing price in TRY */
            $parts[] = sprintf(__('Price: %s TL', 'sutore-marketplace'), (string) $payload['asking']);
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        return $this->fallbackPayloadSummary($payload);
    }

    /** @param array<string, mixed> $payload */
    private function fallbackPayloadSummary(array $payload): string
    {
        $skip = ['actor_user_id', 'actor_login', 'conditions', 'condition_fingerprint', 'product_desc'];
        $parts = [];
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $skip, true) || is_array($value) || is_object($value)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $parts[] = $key . '=' . $value;
            if (count($parts) >= 8) {
                break;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : '—';
    }

    private function deletionSourceLabel(string $source): string
    {
        return match ($source) {
            'merchant' => __('Deleted by merchant', 'sutore-marketplace'),
            'admin' => __('Deleted by admin', 'sutore-marketplace'),
            'staff_fulfillment' => __('Deleted from fulfillment panel', 'sutore-marketplace'),
            'account_purge' => __('Deleted during account purge', 'sutore-marketplace'),
            'orphan_variation' => __('Orphan variation removed', 'sutore-marketplace'),
            default => sprintf(
                /* translators: %s: deletion source key */
                __('Deletion source: %s', 'sutore-marketplace'),
                $source
            ),
        };
    }

    private function conditionLabel(string $key): string
    {
        return match ($key) {
            'no_box' => __('No box', 'sutore-marketplace'),
            'box_damaged' => __('Box damaged', 'sutore-marketplace'),
            'missing_accessory' => __('Missing accessory', 'sutore-marketplace'),
            'damaged' => __('Damaged', 'sutore-marketplace'),
            default => $key,
        };
    }

    private function onOffLabel(int $value): string
    {
        return $value === 1 ? __('On', 'sutore-marketplace') : __('Off', 'sutore-marketplace');
    }

    private function detachReasonLabel(string $reason): string
    {
        return match ($reason) {
            'split' => __('Detached by staff', 'sutore-marketplace'),
            'swap_out' => __('Removed during listing swap', 'sutore-marketplace'),
            'chargeback' => __('Detached due to refund', 'sutore-marketplace'),
            'cancelled' => __('Detached due to cancellation', 'sutore-marketplace'),
            'unsourced' => __('Could not be sourced', 'sutore-marketplace'),
            'confirm_deadline' => __('Detached: confirmation deadline missed', 'sutore-marketplace'),
            'mark_not_for_sale' => __('Detached: marked not for sale', 'sutore-marketplace'),
            'suspended' => __('Detached due to suspension', 'sutore-marketplace'),
            'confirm_deadline' => __('Detached due to confirmation deadline', 'sutore-marketplace'),
            'released' => __('Released from order', 'sutore-marketplace'),
            default => $reason,
        };
    }

    private function attachmentModeLabel(string $mode): string
    {
        return match ($mode) {
            'attached' => __('Attached after sale', 'sutore-marketplace'),
            'payment' => __('Attached awaiting payment confirmation', 'sutore-marketplace'),
            'manual' => __('Manually added to order', 'sutore-marketplace'),
            default => $mode,
        };
    }
}
