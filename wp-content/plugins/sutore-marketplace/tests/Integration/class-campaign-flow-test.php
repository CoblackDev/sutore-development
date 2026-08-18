<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignOfferStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class CampaignFlowTest
{
    public function testPublishCreatesOfferAndAcceptLowersAsking(): void
    {
        Fixtures::withMarketplaceSettings(['listing_price_step' => 25], static function (): void {
            $catalog = Fixtures::catalog('camp1');
            $seller = Fixtures::sellerVerified();
            $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
            $service = new CampaignService();

            $starts = wp_date('Y-m-d H:i:s', time() - 60);
            $ends = wp_date('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
            $id = $service->createCampaign([
                'name' => 'Test campaign ' . wp_generate_password(4, false),
                'seller_discount_type' => CampaignDiscountType::FIXED,
                'seller_discount_amount' => 25,
                'platform_discount_type' => CampaignDiscountType::FIXED,
                'platform_discount_amount' => 0,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'product_ids' => [$catalog['parent_id']],
            ]);
            Harness::assertNotWpError($id);
            /** @var int $id */

            $published = $service->publish($id);
            Harness::assertNotWpError($published);
            $offers = (new CampaignOfferRepository())->findForMerchant($seller, CampaignOfferStatus::PENDING);
            $match = null;
            foreach ($offers as $offer) {
                if ((int) $offer->variation_id === (int) $listing->variationId) {
                    $match = $offer;
                    break;
                }
            }
            Harness::assertTrue($match !== null, 'campaign offer missing for listing');

            wp_set_current_user($seller);
            Harness::assertNotWpError($service->acceptOffer((int) $match->id, $seller));
            $fresh = Fixtures::reloadListing((int) $listing->variationId);
            Harness::assertEqualsFloat(175.0, $fresh->asking);
            Harness::assertSame('active', $fresh->campaignStatus);

            Harness::assertNotWpError($service->endCampaign($id));
        });
    }
}
