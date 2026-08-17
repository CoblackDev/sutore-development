<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings;

final class ParasutCatalog
{
    public function __construct(
        private readonly ParasutClient $client = new ParasutClient(),
    ) {
    }

    public function productId(string $code): string|\WP_Error
    {
        $stored = InvoiceSettings::productId($code);
        if ($stored !== '') {
            return $stored;
        }

        $map = [
            'hizmet' => [
                'code' => 'SUTORE-HIZMET',
                'name' => 'Hizmet Bedeli',
            ],
            'guvence' => [
                'code' => 'SUTORE-GUVENCE',
                'name' => 'Güvence Bedeli',
            ],
            'commission' => [
                'code' => 'SUTORE-KOMISYON',
                'name' => 'Komisyon Bedeli',
            ],
        ];
        $meta = $map[$code] ?? null;
        if ($meta === null) {
            return new \WP_Error('sutore_invoice_product', __('Unknown invoice product.', 'sutore-marketplace'));
        }

        $found = $this->client->get($this->client->companyPath('products'), [
            'filter[code]' => $meta['code'],
            'page[size]' => 1,
        ]);
        $id = $this->firstId($found);
        if ($id !== '') {
            InvoiceSettings::setProductId($code, $id);

            return $id;
        }

        $created = $this->client->post($this->client->companyPath('products'), [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'name' => $meta['name'],
                    'code' => $meta['code'],
                    'vat_rate' => InvoiceSettings::vatRate(),
                    'unit' => 'Adet',
                    'inventory_tracking' => false,
                    'archived' => false,
                ],
            ],
        ]);
        $id = (string) ($created['data']['id'] ?? '');
        if ($id === '' || empty($created['ok'])) {
            return new \WP_Error(
                'sutore_invoice_product_create',
                (string) ($created['error'] ?? __('Could not create Paraşüt product.', 'sutore-marketplace'))
            );
        }
        InvoiceSettings::setProductId($code, $id);

        return $id;
    }

    /**
     * @param array{name:string,email:string,tax_number:string,phone:string,city:string,district:string,address:string} $person
     */
    public function contactId(array $person): string|\WP_Error
    {
        $tax = preg_replace('/\D/', '', $person['tax_number']) ?? '';
        $email = sanitize_email($person['email']);

        if ($tax !== '') {
            $found = $this->client->get($this->client->companyPath('contacts'), [
                'filter[tax_number]' => $tax,
                'page[size]' => 1,
            ]);
            $id = $this->firstId($found);
            if ($id !== '') {
                return $id;
            }
        }
        if ($email !== '') {
            $found = $this->client->get($this->client->companyPath('contacts'), [
                'filter[email]' => $email,
                'page[size]' => 1,
            ]);
            $id = $this->firstId($found);
            if ($id !== '') {
                return $id;
            }
        }

        $created = $this->client->post($this->client->companyPath('contacts'), [
            'data' => [
                'type' => 'contacts',
                'attributes' => [
                    'name' => $person['name'] !== '' ? $person['name'] : $email,
                    'email' => $email,
                    'tax_number' => $tax,
                    'phone' => $person['phone'],
                    'city' => $person['city'],
                    'district' => $person['district'],
                    'address' => $person['address'],
                    'account_type' => 'customer',
                    'contact_type' => 'person',
                ],
            ],
        ]);
        $id = (string) ($created['data']['id'] ?? '');
        if ($id === '' || empty($created['ok'])) {
            return new \WP_Error(
                'sutore_invoice_contact',
                (string) ($created['error'] ?? __('Could not create Paraşüt contact.', 'sutore-marketplace'))
            );
        }

        return $id;
    }

    /** @param array<string, mixed> $response */
    private function firstId(array $response): string
    {
        $data = $response['data'] ?? null;
        if (is_array($data) && isset($data[0]['id'])) {
            return (string) $data[0]['id'];
        }
        if (is_array($data) && isset($data['id'])) {
            return (string) $data['id'];
        }

        return '';
    }
}
