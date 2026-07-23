<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class NotificationTemplates
{
    /**
     * @param array<string, mixed> $context
     * @return array{title: string, body: string, category: string, entity_type: ?string, entity_id: ?int, listing_id: ?int, action_url: string, dedupe_key: string}
     */
    public static function render(string $type, array $context): array
    {
        $product = sanitize_text_field((string) ($context['product'] ?? ''));
        $price = self::formatPrice($context['price'] ?? null);
        $orderId = (int) ($context['order_id'] ?? 0);
        $listingId = (int) ($context['listing_id'] ?? 0);
        $confirmHours = (int) ($context['confirm_hours'] ?? 0);
        $cargoHours = (int) ($context['cargo_hours'] ?? 0);
        $netAmount = self::formatPrice($context['net_amount'] ?? null);
        $oldPos = (int) ($context['old_position'] ?? 0);
        $newPos = (int) ($context['new_position'] ?? 0);
        $taskTitle = sanitize_text_field((string) ($context['task_title'] ?? ''));
        $createdCount = (int) ($context['created_count'] ?? 0);
        $failedCount = (int) ($context['failed_count'] ?? 0);
        $winnerCount = (int) ($context['winner_count'] ?? 0);
        $queuedCount = (int) ($context['queued_count'] ?? 0);
        $importId = sanitize_text_field((string) ($context['import_id'] ?? ''));

        $listingsUrl = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('listings')
            : home_url('/hesabim/listings/');
        $merchantUrl = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('merchant-area')
            : home_url('/hesabim/merchant-area/');
        $campaignsUrl = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('campaign-offers')
            : home_url('/hesabim/campaign-offers/');

        $title = '';
        $body = '';
        $entityType = null;
        $entityId = null;
        $actionUrl = $listingsUrl;
        $dedupeKey = $type;

        $offerId = (int) ($context['offer_id'] ?? 0);
        $sellerDiscountLabel = trim((string) ($context['seller_discount_label'] ?? ''));
        $platformDiscountLabel = trim((string) ($context['platform_discount_label'] ?? ''));
        if ($sellerDiscountLabel === '') {
            $sellerDiscountLabel = self::formatPrice($context['seller_discount'] ?? null);
        }
        if ($platformDiscountLabel === '') {
            $platformDiscountLabel = self::formatPrice($context['platform_discount'] ?? null);
        }

        switch ($type) {
            case NotificationType::SALE_RECEIVED:
                $title = sprintf(
                    /* translators: 1: product name, 2: price */
                    __('Your %1$s product was sold to %2$s', 'sutore-marketplace'),
                    $product,
                    $price
                );
                $body = $confirmHours > 0
                    ? sprintf(
                        /* translators: %d: hours */
                        __('You must confirm your sale within %d hours.', 'sutore-marketplace'),
                        $confirmHours
                    )
                    : __('You need to confirm your sale.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.received:' . $listingId;
                break;

            case NotificationType::SALE_CONFIRM_REMINDER:
                $title = sprintf(
                    __('Your confirmation deadline is approaching: %s', 'sutore-marketplace'),
                    $product
                );
                $body = $confirmHours > 0
                    ? sprintf(__('If you do not confirm your sale within %d hours, it will be taken off sale.', 'sutore-marketplace'), $confirmHours)
                    : __('If you do not confirm your sale, it will be taken off sale.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.confirm_reminder:' . $listingId;
                break;

            case NotificationType::SALE_CONFIRMED:
                $title = sprintf(__('You confirmed your sale: %s', 'sutore-marketplace'), $product);
                $body = $cargoHours > 0
                    ? sprintf(__('Ship the product to the Sutore center within %d hours.', 'sutore-marketplace'), $cargoHours)
                    : __('Ship the product to the Sutore center.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.confirmed:' . $listingId;
                break;

            case NotificationType::SALE_SUSPENDED:
                $title = sprintf(__('Sale not for sale: %s', 'sutore-marketplace'), $product);
                $body = __('Suspended because the sale was not confirmed or the deadline passed.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.suspended:' . $listingId;
                break;

            case NotificationType::SALE_CARGO_REMINDER:
                $title = sprintf(__('Shipping reminder: %s', 'sutore-marketplace'), $product);
                $body = $cargoHours > 0
                    ? sprintf(__('You must send the product to our center within %d hours.', 'sutore-marketplace'), $cargoHours)
                    : __('You must send the product to our center.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.cargo_reminder:' . $listingId;
                break;

            case NotificationType::SALE_CARGO_EXPIRED:
                $title = sprintf(__('Shipping deadline passed: %s', 'sutore-marketplace'), $product);
                $body = __('Shipping deadline has passed. Contact us to prevent your sale from being taken off sale.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'sale.cargo_expired:' . $listingId;
                break;

            case NotificationType::FULFILLMENT_SHIPPED_TO_SUTORE:
                $title = sprintf(__('Product shipped to Sutore: %s', 'sutore-marketplace'), $product);
                $body = __('Shipped to our center for verification.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'fulfillment.shipped_to_sutore:' . $listingId;
                break;

            case NotificationType::FULFILLMENT_ARRIVED_AT_SUTORE:
                $title = sprintf(__('Product arrived at the center: %s', 'sutore-marketplace'), $product);
                $body = $price !== ''
                    ? sprintf(__('Product sold to %s is being reviewed.', 'sutore-marketplace'), $price)
                    : __('Product is under review.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'fulfillment.arrived_at_sutore:' . $listingId;
                break;

            case NotificationType::FULFILLMENT_VERIFIED:
                $title = sprintf(__('Product verified: %s', 'sutore-marketplace'), $product);
                $body = $price !== ''
                    ? sprintf(__('Product sold to %s has been verified. Your payout has been created.', 'sutore-marketplace'), $price)
                    : __('Product verified. Your payout has been created.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $actionUrl = $merchantUrl;
                $dedupeKey = 'fulfillment.verified:' . $listingId;
                break;

            case NotificationType::FULFILLMENT_SHIPPED:
                $title = sprintf(__('Product shipped to customer: %s', 'sutore-marketplace'), $product);
                $body = $orderId > 0
                    ? sprintf(__('Order #%d has been shipped.', 'sutore-marketplace'), $orderId)
                    : __('Product has been handed over for customer shipping.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $listingId ?: null;
                $dedupeKey = 'fulfillment.shipped:' . $listingId;
                break;

            case NotificationType::PAYOUT_PENDING:
                $title = $netAmount !== ''
                    ? sprintf(__('Pending payout: %s', 'sutore-marketplace'), $netAmount)
                    : __('You have a pending payout', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(__('Payment pending for sale %s.', 'sutore-marketplace'), $product)
                    : __('Payment for your sale is pending.', 'sutore-marketplace');
                $entityType = 'payout';
                $entityId = $listingId ?: null;
                $actionUrl = $merchantUrl;
                $dedupeKey = 'payout.pending:' . $listingId;
                break;

            case NotificationType::PAYOUT_PAID:
                $title = $netAmount !== ''
                    ? sprintf(__('Your payment has been made: %s', 'sutore-marketplace'), $netAmount)
                    : __('Your payment has been made', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(__('Your payment for sale %s has been processed.', 'sutore-marketplace'), $product)
                    : __('Payment for your sale has been completed.', 'sutore-marketplace');
                $entityType = 'payout';
                $entityId = $listingId ?: null;
                $actionUrl = $merchantUrl;
                $dedupeKey = 'payout.paid:' . $listingId;
                break;

            case NotificationType::PAYOUT_REVERSED:
                $title = $netAmount !== ''
                    ? sprintf(__('Payout cancelled: %s', 'sutore-marketplace'), $netAmount)
                    : __('Your payout was cancelled', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(__('Payout for sale %s has been cancelled.', 'sutore-marketplace'), $product)
                    : __('Payout for this sale was canceled.', 'sutore-marketplace');
                $entityType = 'payout';
                $entityId = $listingId ?: null;
                $actionUrl = $merchantUrl;
                $dedupeKey = 'payout.reversed:' . $listingId;
                break;

            case NotificationType::LISTING_WINNER_GAINED:
                $title = sprintf(__('Your product is now on sale: %s', 'sutore-marketplace'), $product);
                $body = __('You moved up to #1 — product is for sale.', 'sutore-marketplace');
                $entityType = 'listing';
                $entityId = $listingId ?: null;
                $dedupeKey = 'listing.winner_gained:' . $listingId;
                break;

            case NotificationType::LISTING_WINNER_LOST:
                $title = sprintf(__('Your queue position changed: %s', 'sutore-marketplace'), $product);
                $body = $newPos > 0
                    ? sprintf(__('Another seller has overtaken you. Your new rank: #%d.', 'sutore-marketplace'), $newPos)
                    : __('Another seller has overtaken you.', 'sutore-marketplace');
                if ($oldPos > 0 && $newPos > 0) {
                    $dedupeKey = 'listing.winner_lost:' . $listingId . ':' . $oldPos . ':' . $newPos;
                } else {
                    $dedupeKey = 'listing.winner_lost:' . $listingId;
                }
                $entityType = 'listing';
                $entityId = $listingId ?: null;
                break;

            case NotificationType::LISTING_EXPIRED:
                $title = sprintf(__('Listing expired: %s', 'sutore-marketplace'), $product);
                $body = __('Listing expired. You can re-enter the queue by setting a new price.', 'sutore-marketplace');
                $entityType = 'listing';
                $entityId = $listingId ?: null;
                $dedupeKey = 'listing.expired:' . $listingId;
                break;

            case NotificationType::LISTING_BULK_IMPORT_COMPLETED:
                $title = sprintf(
                    /* translators: %d: number of listings created */
                    __('Bulk import: %d listings created', 'sutore-marketplace'),
                    $createdCount
                );
                $body = sprintf(
                    /* translators: 1: winner count, 2: queued count, 3: failed count */
                    __('%1$d live or leading, %2$d queued. %3$d rows failed.', 'sutore-marketplace'),
                    $winnerCount,
                    $queuedCount,
                    $failedCount
                );
                $actionUrl = !empty($context['action_url'])
                    ? esc_url_raw((string) $context['action_url'])
                    : $listingsUrl;
                $dedupeKey = 'listing.bulk_import_completed:' . ($importId !== '' ? $importId : wp_generate_password(8, false));
                break;

            case NotificationType::TASK_COMPLETED:
                $title = $taskTitle !== ''
                    ? sprintf(__('Task completed: %s', 'sutore-marketplace'), $taskTitle)
                    : __('Task completed', 'sutore-marketplace');
                $body = __('You earned a new task reward.', 'sutore-marketplace');
                $actionUrl = $merchantUrl;
                $dedupeKey = 'task.completed:' . sanitize_key((string) ($context['task_key'] ?? '')) . ':' . (int) ($context['task_id'] ?? 0);
                break;

            case NotificationType::CAMPAIGN_OFFER:
                $title = sprintf(__('Campaign offer: %s', 'sutore-marketplace'), $product);
                $bodyParts = [];
                if ($sellerDiscountLabel !== '') {
                    $bodyParts[] = sprintf(
                        /* translators: %s: discount amount */
                        __('Your discount: %s', 'sutore-marketplace'),
                        $sellerDiscountLabel
                    );
                }
                if ($platformDiscountLabel !== '') {
                    $bodyParts[] = sprintf(
                        /* translators: %s: discount amount */
                        __('Platform contribution: %s', 'sutore-marketplace'),
                        $platformDiscountLabel
                    );
                }
                $body = $bodyParts !== []
                    ? implode(' ', $bodyParts)
                    : __('Review and accept or decline this campaign offer.', 'sutore-marketplace');
                $entityType = 'campaign_offer';
                $entityId = $offerId ?: null;
                $actionUrl = $campaignsUrl;
                $dedupeKey = 'campaign.offer:' . ($offerId ?: $listingId);
                break;

            default:
                $title = __('New notification', 'sutore-marketplace');
                $body = '';
                break;
        }

        return [
            'title' => $title,
            'body' => $body,
            'category' => NotificationType::categoryFor($type),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'listing_id' => $listingId ?: null,
            'action_url' => esc_url_raw($actionUrl),
            'dedupe_key' => $dedupeKey,
        ];
    }

    private static function formatPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value) && str_contains($value, 'TL')) {
            return sanitize_text_field($value);
        }

        return MarketplacePricing::formatTl((float) $value);
    }
}
