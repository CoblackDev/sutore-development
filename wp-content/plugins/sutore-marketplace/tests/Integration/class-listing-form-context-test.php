<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Services\ListingFormContext;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class ListingFormContextTest
{
    public function testCreateFormShowsFirstPlaceWhenSizeAlreadyHasAWinner(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 100], static function (): void {
            $catalog = Fixtures::catalog('fpcreate');
            Fixtures::listing(
                Fixtures::sellerVerified(),
                $catalog['parent_id'],
                $catalog['size_term_id'],
                3000
            );

            wp_set_current_user(Fixtures::sellerPremium());
            $ctx = (new ListingFormContext())->build([
                'parent_product_id' => $catalog['parent_id'],
                'size_term_id' => $catalog['size_term_id'],
                'asking' => 3000,
            ]);

            Harness::assertTrue($ctx['show_first_place_button'], 'unsaved listing must offer Move to First Place');
            Harness::assertEqualsFloat(2900.0, (float) $ctx['first_place_asking']);
            Harness::assertSame(2, (int) $ctx['queue_position']);
        });
    }
}
