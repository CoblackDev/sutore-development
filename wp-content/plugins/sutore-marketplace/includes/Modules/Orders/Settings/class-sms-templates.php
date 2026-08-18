<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Settings;

final class SmsTemplates
{
    /** @return array<string, bool> */
    public static function defaultEventFlags(): array
    {
        return [
            'payment_received_customer' => true,
            'sale_admin' => true,
            'seller_confirmed_customer' => true,
            'international_warning' => true,
            'express_warning' => true,
            'shipped_to_sutore_customer' => true,
            'arrived_customer' => true,
            'verified_customer' => true,
            'shipped_customer' => true,
            'suspended_customer' => true,
            'seller_cargo_expired' => true,
            'alternative_sourcing' => true,
            'sourcing_digest' => true,
            'pre_order_swapped_customer' => true,
            'pre_order_unsourced_customer' => true,
            'manual_order_attach_customer' => true,
            'order_completed' => true,
            'order_refunded' => true,
            'order_cancelled_open_fulfillment' => true,
        ];
    }

    /** @return array<string, string> Source strings (msgid) for defaults and DB comparison. */
    public static function templateSources(): array
    {
        return [
            'payment_received_customer' => 'Dear {customer_name}, your sutore.com order {order_id} has been received.',
            'sale_admin' => '{product} sold for {price} TL. Order: {order_id} Shipping: {shipment_type}',
            'seller_confirm_request' => 'Your {product} sold for {price} TL. Please confirm the sale within {confirm_hours} hours.',
            'seller_confirm_reminder' => 'If you do not confirm your {product} sale within {confirm_hours} hours, it will be taken off sale.',
            'seller_cargo_reminder' => 'Please ship {product} to our hub within {cargo_hours} hours.',
            'seller_cargo_expired' => 'The shipping deadline for {product} has passed. Contact us to avoid being taken off sale.',
            'seller_confirmed_customer' => 'The seller confirmed {product} on your order {order_id}.',
            'seller_confirmed_seller' => 'You confirmed your {product} sale. Ship via Yurtici ({yurtici_code}) within {cargo_hours} hours.',
            'international_warning' => 'WARNING: {product} was sold internationally. Ship with invoice or order screenshot.',
            'express_warning' => 'WARNING: Express sale {product} — do not ship via courier; contact us.',
            'shipped_to_sutore_customer' => '{product} from order {order_id} was shipped to our hub for verification.',
            'shipped_to_sutore_seller' => '{product} was shipped to our hub. Track: http://yurti.ci/{track_code}',
            'arrived_customer' => '{product} from order {order_id} arrived at our verification hub.',
            'arrived_seller' => '{product} sold for {price} TL arrived at our hub and is being inspected.',
            'verified_customer' => '{product} from order {order_id} was verified and is being shipped to you.',
            'verified_seller' => '{product} sold for {price} TL was verified. Your payout is being processed.',
            'shipped_customer' => '{product} from order {order_id} was shipped to you. Track: http://yurti.ci/{track_code}',
            'paid_seller' => 'Your payout for {product} sold at {price} TL has been completed.',
            'suspended_customer' => 'Alternative sourcing is being considered for {product} on order {order_id}.',
            'suspended_seller' => 'Your {product} sale was taken off sale because it was not confirmed.',
            'alternative_sourcing' => 'Urgent sourcing request for {product}. Check the panel if you have a matching product.',
            'sourcing_digest' => 'Pre-order list updated: {products}',
            'pre_order_swapped_customer' => '{product} on your order {order_id} was sourced from another seller. We are tracking delivery to your estimated date.',
            'pre_order_unsourced_customer' => 'We could not source {product} on order {order_id}. The item has been removed and a refund will be processed if payment was taken.',
            'manual_order_attach_customer' => '{product} on your order {order_id} was sourced from another seller. We are tracking delivery to your estimated date.',
            'order_completed' => 'Your order {order_id} is complete. Thank you.',
            'order_refunded' => 'A refund for your order {order_id} has been processed.',
            'order_cancelled_open_fulfillment' => 'Order {order_id} was cancelled while {product} is still in the sale pipeline. Handle in Manage Products.',
        ];
    }

    /** @return array<string, string> Translated default SMS templates for the active locale. */
    public static function defaultTemplates(): array
    {
        $templates = [];
        foreach (self::templateSources() as $key => $source) {
            $templates[$key] = __($source, 'sutore-marketplace');
        }

        return $templates;
    }

    /** @return array<string, string> Admin labels for SMS events. */
    public static function eventLabels(): array
    {
        return [
            'payment_received_customer' => __('Payment received (customer)', 'sutore-marketplace'),
            'sale_admin' => __('Sale (admin)', 'sutore-marketplace'),
            'seller_confirm_request' => __('Merchant confirmation request', 'sutore-marketplace'),
            'seller_confirm_reminder' => __('Merchant confirmation reminder', 'sutore-marketplace'),
            'seller_cargo_reminder' => __('Shipping reminder', 'sutore-marketplace'),
            'seller_cargo_expired' => __('Shipping deadline expired (customer)', 'sutore-marketplace'),
            'seller_confirmed_customer' => __('Merchant confirmed (customer)', 'sutore-marketplace'),
            'seller_confirmed_seller' => __('Merchant confirmed (merchant)', 'sutore-marketplace'),
            'international_warning' => __('International warning', 'sutore-marketplace'),
            'express_warning' => __('Express warning', 'sutore-marketplace'),
            'shipped_to_sutore_customer' => __('Shipped to Sutore (customer)', 'sutore-marketplace'),
            'shipped_to_sutore_seller' => __('Shipped to Sutore (merchant)', 'sutore-marketplace'),
            'arrived_customer' => __('Arrived at hub (customer)', 'sutore-marketplace'),
            'arrived_seller' => __('Arrived at hub (merchant)', 'sutore-marketplace'),
            'verified_customer' => __('Verified (customer)', 'sutore-marketplace'),
            'verified_seller' => __('Verified (seller)', 'sutore-marketplace'),
            'shipped_customer' => __('Shipped to customer', 'sutore-marketplace'),
            'paid_seller' => __('Paid to seller', 'sutore-marketplace'),
            'suspended_customer' => __('Suspended (customer)', 'sutore-marketplace'),
            'suspended_seller' => __('Suspended (seller)', 'sutore-marketplace'),
            'alternative_sourcing' => __('Alternative sourcing', 'sutore-marketplace'),
            'sourcing_digest' => __('Pre-order digest', 'sutore-marketplace'),
            'pre_order_swapped_customer' => __('Pre-order swap (customer)', 'sutore-marketplace'),
            'pre_order_unsourced_customer' => __('Pre-order could not be sourced (customer)', 'sutore-marketplace'),
            'manual_order_attach_customer' => __('Manual order attach (customer)', 'sutore-marketplace'),
            'order_completed' => __('Order completed', 'sutore-marketplace'),
            'order_refunded' => __('Order refund', 'sutore-marketplace'),
            'order_cancelled_open_fulfillment' => __('Cancelled order still in the sale pipeline (admin)', 'sutore-marketplace'),
        ];
    }

    public static function eventLabel(string $eventKey): string
    {
        return self::eventLabels()[$eventKey] ?? $eventKey;
    }

    /** @param array<string, string|int|float|null> $vars */
    public static function render(string $eventKey, array $vars = []): string
    {
        $sources = self::templateSources();
        $defaults = self::defaultTemplates();
        $stored = (array) Settings::get('sms_templates', []);
        $storedTemplate = (string) ($stored[$eventKey] ?? '');
        $source = $sources[$eventKey] ?? '';

        if (
            $storedTemplate === ''
            || ($source !== '' && $storedTemplate === $source)
        ) {
            $template = $defaults[$eventKey] ?? '';
        } else {
            $template = $storedTemplate;
        }

        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        return trim($template);
    }

    /** @return list<string> */
    public static function templateKeys(): array
    {
        return array_keys(self::templateSources());
    }

    /** @return list<string> */
    public static function placeholders(): array
    {
        return [
            '{order_id}', '{product}', '{price}', '{customer_name}',
            '{confirm_hours}', '{cargo_hours}', '{yurtici_code}',
            '{track_code}', '{shipment_type}', '{products}',
        ];
    }

    public static function effectiveTemplate(string $eventKey): string
    {
        $sources = self::templateSources();
        $defaults = self::defaultTemplates();
        $stored = (array) Settings::get('sms_templates', []);
        $storedTemplate = (string) ($stored[$eventKey] ?? '');
        $source = $sources[$eventKey] ?? '';

        if ($storedTemplate === '' || ($source !== '' && $storedTemplate === $source)) {
            return $defaults[$eventKey] ?? '';
        }

        return $storedTemplate;
    }
}
