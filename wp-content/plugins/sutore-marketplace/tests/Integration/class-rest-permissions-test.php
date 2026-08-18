<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class RestPermissionsTest
{
    public function testListingsGetRequiresMerchant(): void
    {
        wp_set_current_user(0);
        $anon = rest_do_request(new \WP_REST_Request('GET', '/sutore-marketplace/v1/listings'));
        Harness::assertTrue($anon->get_status() >= 400, 'anonymous listings GET must fail');

        wp_set_current_user(Fixtures::customer());
        $customer = rest_do_request(new \WP_REST_Request('GET', '/sutore-marketplace/v1/listings'));
        Harness::assertTrue($customer->get_status() >= 400, 'customer listings GET must fail');

        wp_set_current_user(Fixtures::sellerVerified());
        $merchant = rest_do_request(new \WP_REST_Request('GET', '/sutore-marketplace/v1/listings'));
        Harness::assertSame(200, $merchant->get_status());
        $body = $merchant->get_data();
        Harness::assertTrue(is_array($body) && !empty($body['success']));
    }

    public function testNotificationsGetAllowsCustomer(): void
    {
        wp_set_current_user(0);
        $anon = rest_do_request(new \WP_REST_Request('GET', '/sutore-marketplace/v1/notifications'));
        Harness::assertTrue($anon->get_status() >= 400, 'anonymous notifications GET must fail');

        wp_set_current_user(Fixtures::customer());
        $customer = rest_do_request(new \WP_REST_Request('GET', '/sutore-marketplace/v1/notifications'));
        Harness::assertSame(200, $customer->get_status());
        $body = $customer->get_data();
        Harness::assertTrue(is_array($body) && !empty($body['success']));
    }
}
