<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

use SutoreMarketplace\Modules\Contracts\Domain\ContractContext;
use SutoreMarketplace\Modules\Contracts\Domain\ContractType;

final class ContractAssembler
{
    /** @return array{pre_information:string,distance_sales:string} */
    public static function buildFromContext(ContractContext $context): array
    {
        return [
            'pre_information' => self::assembleType(ContractType::PreInformation, $context),
            'distance_sales' => self::assembleType(ContractType::DistanceSales, $context),
        ];
    }

    /** @return array{pre_information:string,distance_sales:string} */
    public static function buildFromCart(bool $isCheckoutPreview = true): array
    {
        return self::buildFromContext(self::contextFromCart($isCheckoutPreview));
    }

    /** @return array{pre_information:string,distance_sales:string} */
    public static function buildFromOrder(\WC_Order $order): array
    {
        return self::buildFromContext(self::contextFromOrder($order));
    }

    public static function contextFromCart(bool $isCheckoutPreview = true): ContractContext
    {
        $billing = self::billingFromSessionCustomer();

        return new ContractContext(
            merchantBlocks: MerchantResolver::fromCart(),
            billingName: $billing['name'],
            address: $billing['address'],
            phone: $billing['phone'],
            email: $billing['email'],
            paymentMethod: $isCheckoutPreview
                ? __('To be specified in the contract after payment is completed', 'sutore-marketplace')
                : __('Credit Card', 'sutore-marketplace'),
            shipmentLabel: __('Free', 'sutore-marketplace'),
            isCheckoutPreview: $isCheckoutPreview,
        );
    }

    /**
     * Seed checkout preview from WC session customer so first paint is not empty.
     * Live typing updates continue via contracts-checkout.js span sync.
     *
     * @return array{name:string,address:string,phone:string,email:string}
     */
    private static function billingFromSessionCustomer(): array
    {
        $name = '';
        $address = '';
        $phone = '';
        $email = '';

        if (function_exists('WC') && WC()->customer) {
            $customer = WC()->customer;
            $name = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
            $address = AddressFormatter::fromParts(
                (string) $customer->get_billing_address_1(),
                (string) $customer->get_billing_address_2(),
                (string) $customer->get_billing_city(),
                (string) $customer->get_billing_state(),
                (string) $customer->get_billing_postcode(),
                (string) $customer->get_billing_country()
            );
            $phone = (string) $customer->get_billing_phone();
            $email = (string) $customer->get_billing_email();
        }

        if ($email === '' && is_user_logged_in()) {
            $user = wp_get_current_user();
            $email = (string) $user->user_email;
        }

        return [
            'name' => $name,
            'address' => $address,
            'phone' => $phone,
            'email' => $email,
        ];
    }

    public static function contextFromOrder(\WC_Order $order): ContractContext
    {
        return new ContractContext(
            merchantBlocks: MerchantResolver::fromOrder($order),
            billingName: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            address: AddressFormatter::billing($order),
            phone: (string) $order->get_billing_phone(),
            email: (string) $order->get_billing_email(),
            paymentMethod: __('Credit Card', 'sutore-marketplace'),
            shipmentLabel: __('Free', 'sutore-marketplace'),
            isCheckoutPreview: false,
        );
    }

    private static function assembleType(ContractType $type, ContractContext $context): string
    {
        $html = '';

        foreach ($context->merchantBlocks as $block) {
            $blockTotal = 0.0;
            foreach ($block['items'] as $item) {
                $blockTotal += (float) ($item['total_raw'] ?? 0);
            }

            $html .= TemplateRenderer::render($type, [
                'items' => TemplateRenderer::productRowsHtml($block['items']),
                'fullname' => (string) $block['fullname'],
                'city' => (string) $block['city'],
                'address' => $context->address,
                'payment' => $context->paymentMethod,
                'billingName' => $context->billingName,
                'phone' => $context->phone,
                'email' => $context->email,
                'totalPrice' => wc_price($blockTotal),
                'shipment' => $context->shipmentLabel,
            ]);
        }

        return $html;
    }
}
