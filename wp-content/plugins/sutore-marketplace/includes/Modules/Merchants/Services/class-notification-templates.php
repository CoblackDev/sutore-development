<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class NotificationTemplates
{
    /**
     * @param array<string, mixed> $context
     * @return array{title: string, body: string, category: string, entity_type: ?string, entity_id: ?int, variation_id: ?int, action_url: string, dedupe_key: string}
     */
    public static function render(string $type, array $context): array
    {
        $product = sanitize_text_field((string) ($context['product'] ?? ''));
        $price = self::formatPrice($context['price'] ?? null);
        $orderId = (int) ($context['order_id'] ?? 0);
        $variationId = (int) ($context['variation_id'] ?? 0);
        $confirmHours = (int) ($context['confirm_hours'] ?? 0);
        $cargoHours = (int) ($context['cargo_hours'] ?? 0);
        $netAmount = self::formatPrice($context['net_amount'] ?? null);
        $scheduledMessage = '';
        $scheduledRaw = (string) ($context['scheduled_payout_date'] ?? '');
        if ($scheduledRaw !== '') {
            $scheduledMessage = \SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule::merchantPendingMessage($scheduledRaw);
        }
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
        $balanceUrl = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('balance')
            : home_url('/hesabim/balance/');
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
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.received:' . $variationId;
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
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.confirm_reminder:' . $variationId;
                break;

            case NotificationType::SALE_CONFIRMED:
                $title = sprintf(__('You confirmed your sale: %s', 'sutore-marketplace'), $product);
                $body = $cargoHours > 0
                    ? sprintf(__('Ship the product to the Sutore center within %d hours.', 'sutore-marketplace'), $cargoHours)
                    : __('Ship the product to the Sutore center.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.confirmed:' . $variationId;
                break;

            case NotificationType::SALE_SUSPENDED:
                $title = sprintf(__('Sale not for sale: %s', 'sutore-marketplace'), $product);
                $body = __('Suspended because the sale was not confirmed or the deadline passed.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.suspended:' . $variationId;
                break;

            case NotificationType::SALE_CARGO_REMINDER:
                $title = sprintf(__('Shipping reminder: %s', 'sutore-marketplace'), $product);
                $body = $cargoHours > 0
                    ? sprintf(__('You must send the product to our center within %d hours.', 'sutore-marketplace'), $cargoHours)
                    : __('You must send the product to our center.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.cargo_reminder:' . $variationId;
                break;

            case NotificationType::SALE_CARGO_EXPIRED:
                $title = sprintf(__('Shipping deadline passed: %s', 'sutore-marketplace'), $product);
                $body = __('Shipping deadline has passed. Contact us to prevent your sale from being taken off sale.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'sale.cargo_expired:' . $variationId;
                break;

            case NotificationType::FULFILLMENT_SHIPPED_TO_SUTORE:
                $title = sprintf(__('Product shipped to Sutore: %s', 'sutore-marketplace'), $product);
                $body = __('Shipped to our center for verification.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'fulfillment.shipped_to_sutore:' . $variationId;
                break;

            case NotificationType::FULFILLMENT_ARRIVED_AT_SUTORE:
                $title = sprintf(__('Product arrived at the center: %s', 'sutore-marketplace'), $product);
                $body = $price !== ''
                    ? sprintf(__('Product sold to %s is being reviewed.', 'sutore-marketplace'), $price)
                    : __('Product is under review.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'fulfillment.arrived_at_sutore:' . $variationId;
                break;

            case NotificationType::FULFILLMENT_VERIFIED:
                $title = sprintf(__('Product verified: %s', 'sutore-marketplace'), $product);
                $body = $price !== ''
                    ? sprintf(__('Product sold to %s has been verified. Your payout has been created.', 'sutore-marketplace'), $price)
                    : __('Product verified. Your payout has been created.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $actionUrl = $balanceUrl;
                $dedupeKey = 'fulfillment.verified:' . $variationId;
                break;

            case NotificationType::FULFILLMENT_SHIPPED:
                $title = sprintf(__('Product shipped to customer: %s', 'sutore-marketplace'), $product);
                $body = $orderId > 0
                    ? sprintf(__('Order #%d has been shipped.', 'sutore-marketplace'), $orderId)
                    : __('Product has been handed over for customer shipping.', 'sutore-marketplace');
                $entityType = 'fulfillment';
                $entityId = $variationId ?: null;
                $dedupeKey = 'fulfillment.shipped:' . $variationId;
                break;

            case NotificationType::PAYOUT_PENDING:
                $title = $netAmount !== ''
                    ? sprintf(__('Pending payout: %s', 'sutore-marketplace'), $netAmount)
                    : __('You have a pending payout', 'sutore-marketplace');
                if ($scheduledMessage !== '') {
                    $body = $scheduledMessage;
                } elseif ($product !== '') {
                    $body = sprintf(__('Payment pending for sale %s.', 'sutore-marketplace'), $product);
                } else {
                    $body = __('Payment for your sale is pending.', 'sutore-marketplace');
                }
                $entityType = 'payout';
                $entityId = $variationId ?: null;
                $actionUrl = $balanceUrl;
                $dedupeKey = 'payout.pending:' . $variationId;
                break;

            case NotificationType::PAYOUT_PAID:
                $title = $netAmount !== ''
                    ? sprintf(__('Your payment has been made: %s', 'sutore-marketplace'), $netAmount)
                    : __('Your payment has been made', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(__('Your payment for sale %s has been processed.', 'sutore-marketplace'), $product)
                    : __('Payment for your sale has been completed.', 'sutore-marketplace');
                $entityType = 'payout';
                $entityId = $variationId ?: null;
                $actionUrl = $balanceUrl;
                $dedupeKey = 'payout.paid:' . $variationId;
                break;

            case NotificationType::PAYOUT_REVERSED:
                $title = $netAmount !== ''
                    ? sprintf(__('Payout cancelled: %s', 'sutore-marketplace'), $netAmount)
                    : __('Your payout was cancelled', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(__('Payout for sale %s has been cancelled.', 'sutore-marketplace'), $product)
                    : __('Payout for this sale was canceled.', 'sutore-marketplace');
                $entityType = 'payout';
                $entityId = $variationId ?: null;
                $actionUrl = $balanceUrl;
                $dedupeKey = 'payout.reversed:' . $variationId;
                break;

            case NotificationType::LISTING_WINNER_GAINED:
                $title = sprintf(__('Your product is now on sale: %s', 'sutore-marketplace'), $product);
                $body = __('You moved up to #1 — product is for sale.', 'sutore-marketplace');
                $entityType = 'listing';
                $entityId = $variationId ?: null;
                $dedupeKey = 'listing.winner_gained:' . $variationId;
                break;

            case NotificationType::LISTING_WINNER_LOST:
                $title = sprintf(__('Your queue position changed: %s', 'sutore-marketplace'), $product);
                $body = $newPos > 0
                    ? sprintf(__('Another seller has overtaken you. Your new rank: #%d.', 'sutore-marketplace'), $newPos)
                    : __('Another seller has overtaken you.', 'sutore-marketplace');
                if ($oldPos > 0 && $newPos > 0) {
                    $dedupeKey = 'listing.winner_lost:' . $variationId . ':' . $oldPos . ':' . $newPos;
                } else {
                    $dedupeKey = 'listing.winner_lost:' . $variationId;
                }
                $entityType = 'listing';
                $entityId = $variationId ?: null;
                break;

            case NotificationType::LISTING_EXPIRED:
                $title = sprintf(__('Product expired: %s', 'sutore-marketplace'), $product);
                $body = __('Product expired. You can re-enter the queue by setting a new price.', 'sutore-marketplace');
                $entityType = 'listing';
                $entityId = $variationId ?: null;
                $dedupeKey = 'listing.expired:' . $variationId;
                break;

            case NotificationType::LISTING_BULK_IMPORT_COMPLETED:
                $title = sprintf(
                    /* translators: %d: number of listings created */
                    __('Bulk import: %d products created', 'sutore-marketplace'),
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
                    ? sprintf(__('Opportunity completed: %s', 'sutore-marketplace'), $taskTitle)
                    : __('Opportunity completed', 'sutore-marketplace');
                $body = __('Your opportunity card reward has been applied.', 'sutore-marketplace');
                $actionUrl = $merchantUrl;
                $dedupeKey = 'task.completed:' . sanitize_key((string) ($context['task_key'] ?? '')) . ':' . (int) ($context['task_id'] ?? 0);
                break;

            case NotificationType::LEVEL_CHANGED:
                $fromLabel = (string) ($context['from_label'] ?? '');
                $toLabel = (string) ($context['to_label'] ?? '');
                $title = __('Your seller level changed', 'sutore-marketplace');
                $body = $fromLabel !== '' && $toLabel !== ''
                    ? sprintf(
                        /* translators: 1: previous level label, 2: new level label */
                        __('Your level changed from %1$s to %2$s.', 'sutore-marketplace'),
                        $fromLabel,
                        $toLabel
                    )
                    : __('Your seller level was updated based on your recent performance.', 'sutore-marketplace');
                $actionUrl = $merchantUrl;
                $dedupeKey = 'level.changed:' . sanitize_key((string) ($context['to'] ?? '')) . ':' . wp_date('Y-m-d');
                break;

            case NotificationType::CAMPAIGN_OFFER:
                $headline = trim((string) ($context['headline'] ?? ''));
                $title = sprintf(__('Campaign offer: %s', 'sutore-marketplace'), $product);
                if ($headline !== '') {
                    $body = $headline;
                } else {
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
                }
                $entityType = 'campaign_offer';
                $entityId = $offerId ?: null;
                $actionUrl = $campaignsUrl;
                if ($offerId > 0) {
                    $actionUrl = add_query_arg('offer', $offerId, $campaignsUrl);
                }
                $dedupeKey = 'campaign.offer:' . ($offerId ?: $variationId);
                break;

            case NotificationType::CUSTOMER_OFFER:
                $bidLabel = self::formatPrice($context['bid_amount'] ?? null);
                $title = sprintf(__('Customer offer: %s', 'sutore-marketplace'), $product);
                $body = $bidLabel !== ''
                    ? sprintf(
                        /* translators: %s: bid amount */
                        __('A customer offered %s for this product. Accept to issue a personal coupon, or decline to pass it to the next seller in queue.', 'sutore-marketplace'),
                        $bidLabel
                    )
                    : __('A customer sent a price offer on this product.', 'sutore-marketplace');
                $entityType = 'customer_offer';
                $entityId = $offerId ?: null;
                $priceOffersUrl = function_exists('wc_get_account_endpoint_url')
                    ? wc_get_account_endpoint_url('price-offers')
                    : home_url('/hesabim/price-offers/');
                $actionUrl = $priceOffersUrl;
                if ($offerId > 0) {
                    $actionUrl = add_query_arg('offer', $offerId, $priceOffersUrl);
                }
                $dedupeKey = 'customer.offer:' . ($offerId ?: $variationId);
                break;

            case NotificationType::CUSTOMER_OFFER_ACCEPTED:
                $couponCode = sanitize_text_field((string) ($context['coupon_code'] ?? ''));
                $title = $product !== ''
                    ? sprintf(
                        /* translators: %s: product title */
                        __('Your offer on %s was accepted', 'sutore-marketplace'),
                        $product
                    )
                    : __('Your offer was accepted', 'sutore-marketplace');
                $body = $couponCode !== ''
                    ? sprintf(
                        /* translators: %s: coupon code */
                        __('Use coupon %s at checkout. This coupon is only for you and this product.', 'sutore-marketplace'),
                        $couponCode
                    )
                    : __('The seller accepted your offer. Use your personal coupon at checkout.', 'sutore-marketplace');
                $entityType = 'customer_offer';
                $entityId = $offerId ?: null;
                $actionUrl = self::myOffersUrl();
                $dedupeKey = 'customer.offer_accepted:' . ($offerId ?: $variationId);
                break;

            case NotificationType::CUSTOMER_OFFER_DECLINED:
                $title = $product !== ''
                    ? sprintf(
                        /* translators: %s: product title */
                        __('Your offer on %s was declined', 'sutore-marketplace'),
                        $product
                    )
                    : __('Your offer was declined', 'sutore-marketplace');
                $body = __('The seller declined your offer.', 'sutore-marketplace');
                $entityType = 'customer_offer';
                $entityId = $offerId ?: null;
                $actionUrl = self::myOffersUrl();
                $dedupeKey = 'customer.offer_declined:' . ($offerId ?: $variationId);
                break;

            case NotificationType::CUSTOMER_OFFER_EXPIRED:
                $title = $product !== ''
                    ? sprintf(
                        /* translators: %s: product title */
                        __('Your offer on %s expired', 'sutore-marketplace'),
                        $product
                    )
                    : __('Your offer expired', 'sutore-marketplace');
                $body = __('This offer is no longer valid.', 'sutore-marketplace');
                $entityType = 'customer_offer';
                $entityId = $offerId ?: null;
                $actionUrl = self::myOffersUrl();
                $dedupeKey = 'customer.offer_expired:' . ($offerId ?: $variationId);
                break;

            case NotificationType::CUSTOMER_OFFER_FORWARDED:
                $title = $product !== ''
                    ? sprintf(
                        /* translators: %s: product title */
                        __('Your offer on %s is still pending', 'sutore-marketplace'),
                        $product
                    )
                    : __('Your offer is still pending', 'sutore-marketplace');
                $body = __('The seller did not accept. Your offer was sent to the next seller.', 'sutore-marketplace');
                $entityType = 'customer_offer';
                $entityId = $offerId ?: null;
                $actionUrl = self::myOffersUrl();
                $dedupeKey = 'customer.offer_forwarded:' . ($offerId ?: $variationId);
                break;

            case NotificationType::REFERRAL_REWARDED:
                $inviteeName = sanitize_text_field((string) ($context['invitee_name'] ?? ''));
                $pointsOff = (float) ($context['points_off'] ?? 0);
                $expiresRaw = trim((string) ($context['expires_at'] ?? ''));
                $expiresLabel = '';
                if ($expiresRaw !== '') {
                    $expiresTs = strtotime($expiresRaw);
                    if ($expiresTs !== false) {
                        $expiresLabel = (string) wp_date(
                            get_option('date_format') . ' ' . get_option('time_format'),
                            $expiresTs
                        );
                    }
                }
                $title = __('Referral reward unlocked', 'sutore-marketplace');
                if ($inviteeName !== '' && $expiresLabel !== '') {
                    $body = sprintf(
                        /* translators: 1: invited seller name, 2: points off, 3: expiry datetime */
                        __('%1$s completed a first sale. Your commission is reduced by %2$s points until %3$s.', 'sutore-marketplace'),
                        $inviteeName,
                        (string) $pointsOff,
                        $expiresLabel
                    );
                } elseif ($inviteeName !== '') {
                    $body = sprintf(
                        /* translators: 1: invited seller name, 2: points off */
                        __('%1$s completed a first sale. Your commission is reduced by %2$s points.', 'sutore-marketplace'),
                        $inviteeName,
                        (string) $pointsOff
                    );
                } else {
                    $body = __('A seller you invited completed their first sale. Your referral commission discount is now active.', 'sutore-marketplace');
                }
                $actionUrl = $merchantUrl;
                $dedupeKey = 'referral.rewarded:' . (int) ($context['invitee_id'] ?? $variationId);
                break;

            case NotificationType::CATALOG_REQUEST_FULFILLED:
                $requestId = (int) ($context['request_id'] ?? 0);
                $productCode = sanitize_text_field((string) ($context['product_code'] ?? ''));
                $title = __('Your product was added to the catalog', 'sutore-marketplace');
                $body = $product !== ''
                    ? sprintf(
                        /* translators: %s: product name or SKU */
                        __('%s was added to the catalog. You can open a product now.', 'sutore-marketplace'),
                        $product
                    )
                    : __('The product you requested was added to the catalog. You can open a product now.', 'sutore-marketplace');
                $entityType = 'catalog_product_request';
                $entityId = $requestId ?: null;
                $actionUrl = $listingsUrl;
                $createArgs = ['action' => 'create'];
                if ($productCode !== '' && preg_match('#^https?://#i', $productCode) !== 1) {
                    $createArgs['product_code'] = $productCode;
                }
                $actionUrl = add_query_arg($createArgs, $listingsUrl);
                $dedupeKey = 'catalog_request.fulfilled:' . ($requestId ?: wp_generate_password(8, false));
                break;

            case NotificationType::OUTLET_LISTING_LIVE:
                $outletUrl = function_exists('wc_get_account_endpoint_url')
                    ? wc_get_account_endpoint_url('outlet')
                    : home_url('/hesabim/outlet/');
                $title = sprintf(__('Outlet product is live: %s', 'sutore-marketplace'), $product);
                $body = __('Your outlet product is now on sale at the committed price until the window ends.', 'sutore-marketplace');
                $entityType = 'outlet_optin';
                $entityId = $variationId ?: null;
                $actionUrl = $outletUrl;
                $dedupeKey = 'outlet.listing_live:' . $variationId;
                break;

            case NotificationType::CATALOG_REQUEST_REJECTED:
                $requestId = (int) ($context['request_id'] ?? 0);
                $staffNote = sanitize_text_field((string) ($context['staff_note'] ?? ''));
                $title = __('Catalog request declined', 'sutore-marketplace');
                if ($staffNote !== '') {
                    $body = $staffNote;
                } elseif ($product !== '') {
                    $body = sprintf(
                        /* translators: %s: product SKU or link */
                        __('Your request for %s was declined.', 'sutore-marketplace'),
                        $product
                    );
                } else {
                    $body = __('Your catalog product request was declined.', 'sutore-marketplace');
                }
                $entityType = 'catalog_product_request';
                $entityId = $requestId ?: null;
                $actionUrl = $listingsUrl;
                $dedupeKey = 'catalog_request.rejected:' . ($requestId ?: wp_generate_password(8, false));
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
            'variation_id' => $variationId ?: null,
            'action_url' => esc_url_raw($actionUrl),
            'dedupe_key' => $dedupeKey,
        ];
    }

    private static function myOffersUrl(): string
    {
        if (function_exists('wc_get_account_endpoint_url')) {
            return wc_get_account_endpoint_url('my-offers');
        }

        return home_url('/hesabim/my-offers/');
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
