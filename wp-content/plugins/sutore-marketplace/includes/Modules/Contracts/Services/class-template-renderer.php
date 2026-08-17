<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

use SutoreMarketplace\Modules\Contracts\Domain\ContractType;

final class TemplateRenderer
{
    public static function load(ContractType $type): string
    {
        $baseDir = SUTORE_MARKETPLACE_PATH . 'includes/Modules/Contracts/Templates/';
        $file = $type->templateFile();
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        // English is the plugin default; Turkish keeps regulator-facing TR legal copy.
        $candidates = [];
        if (str_starts_with(strtolower($locale), 'tr')) {
            $candidates[] = $file;
            $candidates[] = preg_replace('/\.php$/', '-en.php', $file) ?: $file;
        } else {
            $candidates[] = preg_replace('/\.php$/', '-en.php', $file) ?: $file;
            $candidates[] = $file;
        }

        foreach ($candidates as $name) {
            $path = $baseDir . $name;
            if (!is_readable($path)) {
                continue;
            }
            $template = include $path;
            if (is_string($template) && $template !== '') {
                return $template;
            }
        }

        return '';
    }

    /**
     * @param array{
     *     items:string,
     *     fullname:string,
     *     city:string,
     *     address:string,
     *     payment:string,
     *     billingName:string,
     *     phone:string,
     *     email:string,
     *     totalPrice:string,
     *     shipment:string
     * } $data
     */
    public static function render(ContractType $type, array $data): string
    {
        $template = self::load($type);
        if ($template === '') {
            return '';
        }

        $search = [
            '[satici-urunler]',
            '[satici-isim]',
            '[satici-il]',
            '[kargo-adres]',
            '[odeme-yontemi]',
            '[kargo-isim]',
            '[telefon]',
            '[eposta]',
            '[toplam-tutar]',
            '[kargo-tutar]',
        ];

            $replace = [
            $data['items'],
            esc_html($data['fullname']),
            esc_html($data['city']),
            esc_html($data['address']),
            esc_html($data['payment']),
            esc_html($data['billingName']),
            esc_html($data['phone']),
            esc_html($data['email']),
            wp_kses_post($data['totalPrice']),
            esc_html($data['shipment']),
        ];

        return str_replace($search, $replace, $template);
    }

    /** @param list<array<string,mixed>> $items */
    public static function productRowsHtml(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<tr>'
                . '<td class="tg-s6z2">' . esc_html((string) ($item['name'] ?? '')) . '</td>'
                . '<td class="tg-s6z2">' . wp_kses_post((string) ($item['product_price'] ?? '')) . '</td>'
                . '<td class="tg-s6z2">' . wp_kses_post((string) ($item['service_cost'] ?? '')) . '</td>'
                . '<td class="tg-s6z2">' . wp_kses_post((string) ($item['insurance_cost'] ?? '')) . '</td>'
                . '<td class="tg-s6z2">' . wp_kses_post((string) ($item['total_price'] ?? '')) . '</td>'
                . '</tr>';
        }

        return $html;
    }
}
