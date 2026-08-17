<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Support;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Services\PaymentHandler;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;

final class Fixtures
{
    public const META = '_sutore_marketplace_automated_test';
    public const PASSWORD = 'password';
    public const VALID_TC = '10000000146';
    public const VALID_IBAN = 'TR330006100519786457841326';
    public const TRACK_SELLER = '111222333444';
    public const TRACK_SUTORE = '999888777666';

    /** @var array<string, int> */
    private static array $users = [];

    public static function ensureMerchantRole(): void
    {
        if (get_role('merchant') === null) {
            add_role('merchant', 'Merchant', ['read' => true]);
        }
    }

    public static function adminId(): int
    {
        $admin = get_user_by('login', 'admin');
        if ($admin) {
            return (int) $admin->ID;
        }
        $users = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

        return (int) ($users[0] ?? 0);
    }

    public static function user(string $login, string $role, string $level = MerchantLevels::VERIFIED): int
    {
        if (isset(self::$users[$login])) {
            return self::$users[$login];
        }

        self::ensureMerchantRole();
        $email = $login . '@sutore-test.local';
        $existing = get_user_by('login', $login);
        if ($existing) {
            $id = (int) $existing->ID;
            (new \WP_User($id))->set_role($role);
            wp_set_password(self::PASSWORD, $id);
        } else {
            $created = wp_insert_user([
                'user_login' => $login,
                'user_pass' => self::PASSWORD,
                'user_email' => $email,
                'display_name' => $login,
                'role' => $role,
            ]);
            if ($created instanceof \WP_Error) {
                throw new Failed('User create failed: ' . $created->get_error_message());
            }
            $id = (int) $created;
        }

        MerchantMeta::writeProfile($id, [
            MerchantMeta::ACCOUNT_PHONE => '555' . str_pad((string) ($id % 10000000), 7, '0', STR_PAD_LEFT),
            MerchantMeta::ACCOUNT_NAME => $login,
            MerchantMeta::ACCOUNT_LASTNAME => 'Test',
            MerchantMeta::ACCOUNT_CITY => 'TR34',
            MerchantMeta::ACCOUNT_STATE => 'Kadikoy',
            MerchantMeta::ACCOUNT_IBAN => self::VALID_IBAN,
            MerchantMeta::ACCOUNT_EMAIL => $email,
            MerchantMeta::ACCOUNT_TCKNO => self::VALID_TC,
            MerchantMeta::ACCOUNT_BIRTH_YEAR => '1998',
        ], [
            'merchant_status' => $level,
            'tckno_verified' => $level === MerchantLevels::NORMAL ? 0 : 1,
            'tckno_verified_at' => $level === MerchantLevels::NORMAL ? 0 : time(),
            'tckno_verify_method' => 'test',
        ]);
        update_user_meta($id, self::META, '1');
        self::$users[$login] = $id;

        return $id;
    }

    public static function sellerVerified(): int
    {
        return self::user('st_seller_verified', 'merchant', MerchantLevels::VERIFIED);
    }

    public static function sellerNormal(): int
    {
        return self::user('st_seller_normal', 'merchant', MerchantLevels::NORMAL);
    }

    public static function sellerPremium(): int
    {
        return self::user('st_seller_premium', 'merchant', MerchantLevels::PREMIUM);
    }

    public static function sellerQueued(): int
    {
        return self::user('st_seller_queued', 'merchant', MerchantLevels::VERIFIED);
    }

    public static function customer(): int
    {
        return self::user('st_customer', 'customer', MerchantLevels::NORMAL);
    }

    /**
     * @return array{parent_id:int,size_term_id:int,code:string}
     */
    public static function catalog(string $suffix = ''): array
    {
        require_once SUTORE_MARKETPLACE_PATH . 'tools/seed-catalog-helpers.php';

        $suffix = $suffix !== '' ? $suffix : wp_generate_password(6, false, false);
        $code = 'STEST-' . strtoupper($suffix);
        $sizeSlug = 'st-size-' . strtolower($suffix);
        $term = seed_catalog_ensure_size_term($sizeSlug, 'Test ' . $suffix);
        $parentId = seed_catalog_create_variable_parent(
            'Sutore Test ' . $suffix,
            $code,
            seed_catalog_primary_taxonomy(),
            [$term],
            self::META
        );

        return [
            'parent_id' => $parentId,
            'size_term_id' => (int) $term->term_id,
            'code' => $code,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function listing(int $merchantId, int $parentId, int $sizeTermId, int $asking, array $input = []): Listing
    {
        wp_set_current_user($merchantId);
        $listing = (new ListingService())->create(array_merge([
            'parent_product_id' => $parentId,
            'size_term_id' => $sizeTermId,
            'asking' => $asking,
            'fast_shipment' => 0,
            'has_invoice' => 0,
            'no_box' => 0,
            'box_damaged' => 0,
            'missing_accessory' => 0,
            'damaged' => 0,
        ], $input), $merchantId, ['skip_task_increment' => true]);

        Harness::assertNotWpError($listing, 'Listing create failed');
        /** @var Listing $listing */
        update_post_meta((int) $listing->variationId, self::META, '1');

        return $listing;
    }

    public static function paidOrder(int $customerId, int $variationId, string $note = 'automated test'): \WC_Order
    {
        return self::paidOrderWithVariations($customerId, [$variationId], $note);
    }

    /**
     * @param list<int> $variationIds
     */
    public static function paidOrderWithVariations(int $customerId, array $variationIds, string $note = 'automated test'): \WC_Order
    {
        $order = wc_create_order(['customer_id' => $customerId]);
        $order->set_billing_first_name('Test');
        $order->set_billing_last_name('Customer');
        $order->set_billing_email('st_customer@sutore-test.local');
        $order->set_billing_phone('5551112233');
        $order->set_billing_address_1('Test Street 1');
        $order->set_billing_city('Istanbul');
        $order->set_billing_state('Kadikoy');
        $order->set_billing_country('TR');
        $order->set_payment_method('cod');
        $order->set_payment_method_title('COD (test)');
        $order->update_meta_data(ShipmentMeta::TYPE, 'standard');
        $order->update_meta_data(CheckoutIdentityHooks::ORDER_META_TCKNO, self::VALID_TC);
        $order->update_meta_data(self::META, '1');
        foreach ($variationIds as $variationId) {
            $product = wc_get_product((int) $variationId);
            if (!$product) {
                throw new Failed('Variation missing #' . $variationId);
            }
            $order->add_product($product, 1);
        }
        $order->calculate_totals();
        $order->save();
        $order->update_status('processing', $note);
        (new PaymentHandler())->onPaymentComplete((int) $order->get_id());

        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof \WC_Order) {
            throw new Failed('Order reload failed');
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public static function withMarketplaceSettings(array $patch, callable $fn): mixed
    {
        $before = get_option(Settings::OPTION_KEY);
        try {
            Settings::update($patch);

            return $fn();
        } finally {
            if (is_array($before)) {
                update_option(Settings::OPTION_KEY, $before);
            } else {
                delete_option(Settings::OPTION_KEY);
            }
            $ref = new \ReflectionClass(Settings::class);
            $memo = $ref->getProperty('memo');
            $memo->setAccessible(true);
            $memo->setValue(null, null);
        }
    }

    /**
     * @param array<string, mixed> $patch
     */
    public static function withOrderSettings(array $patch, callable $fn): mixed
    {
        $before = get_option(OrderSettings::OPTION);
        try {
            OrderSettings::update($patch);

            return $fn();
        } finally {
            if (is_array($before)) {
                update_option(OrderSettings::OPTION, $before);
            } else {
                delete_option(OrderSettings::OPTION);
            }
            $ref = new \ReflectionClass(OrderSettings::class);
            $memo = $ref->getProperty('memo');
            $memo->setAccessible(true);
            $memo->setValue(null, null);
        }
    }

    public static function listingStatus(int $listingId): string
    {
        $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($listingId);
        if (!$listing) {
            throw new Failed('Listing #' . $listingId . ' missing');
        }

        return $listing->listingStatus;
    }

    public static function reloadListing(int $listingId): Listing
    {
        $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($listingId);
        if (!$listing) {
            throw new Failed('Listing #' . $listingId . ' missing');
        }

        return $listing;
    }

    public static function assertStatus(int $listingId, string $expected, string $label = ''): void
    {
        $actual = self::listingStatus($listingId);
        Harness::assertSame($expected, $actual, $label !== '' ? $label : 'listing_status');
    }

    public static function soldFromPublish(Listing $listing, int $customerId): \WC_Order
    {
        return self::withOrderSettings(['require_admin_payment_confirm' => false], static function () use ($listing, $customerId) {
            $order = self::paidOrder($customerId, (int) $listing->variationId);
            self::assertStatus((int) $listing->variationId, ListingStatus::SOLD, 'payment should skip admin confirm');

            return $order;
        });
    }

    public static function advanceSoldToVerified(int $listingId, int $sellerId): void
    {
        $fs = new \SutoreMarketplace\Modules\Orders\Services\FulfillmentService();
        wp_set_current_user($sellerId);
        $confirmed = $fs->merchantConfirmSale($listingId, $sellerId);
        if (is_wp_error($confirmed)) {
            throw new Failed($confirmed->get_error_message());
        }
        $shipped = $fs->merchantSubmitShipment($listingId, $sellerId, self::TRACK_SELLER);
        if (is_wp_error($shipped)) {
            throw new Failed($shipped->get_error_message());
        }
        wp_set_current_user(self::adminId());
        $arrived = $fs->markArrivedAtSutore($listingId);
        if (is_wp_error($arrived)) {
            throw new Failed($arrived->get_error_message());
        }
        $verified = $fs->markVerified($listingId);
        if (is_wp_error($verified)) {
            throw new Failed($verified->get_error_message());
        }
        self::assertStatus($listingId, ListingStatus::VERIFIED, 'pipeline to verified');
    }
}
