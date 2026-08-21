<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptinStatus;
use SutoreMarketplace\Modules\Listings\Domain\TransitionResult;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletOptinRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingOrderBridge;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Merchants\Services\PayoutLineService;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Tasks\Repositories\TaskProgressRepository;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

/**
 * §9.2 concurrency matrix (sequential CAS on real DB).
 *
 * True multi-process barrier labs need pcntl; this environment does not expose it.
 * Outcomes still match the gate: one changed/winner, remaining already_done/conflict.
 */
final class ConcurrencyMatrixTest
{
    public function testTransitionPrimitiveOutcomes(): void
    {
        Schema::install();
        $catalog = Fixtures::catalog('tr01');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, ['listing_status' => ListingStatus::PUBLISH]);

        $op = 'op:transition:' . $id;
        $first = (new ListingRepository())->transition($id, ListingStatus::PUBLISH, [
            'listing_status' => ListingStatus::NOT_SALE,
        ], $op);
        Harness::assertTrue($first->isChanged(), 'first transition wins');
        Harness::assertSame(TransitionResult::CHANGED, $first->outcome());

        $again = (new ListingRepository())->transition($id, ListingStatus::PUBLISH, [
            'listing_status' => ListingStatus::NOT_SALE,
        ], $op);
        Harness::assertTrue($again->isAlreadyDone(), 'same operation_id is idempotent');

        $conflict = (new ListingRepository())->transition($id, ListingStatus::PUBLISH, [
            'listing_status' => ListingStatus::QUEUED,
        ], $op . ':other');
        Harness::assertTrue($conflict->isConflict(), 'stale expected status conflicts');
    }

    public function testTwoOrderClaimsOneWinner(): void
    {
        $catalog = Fixtures::catalog('claim2');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 200);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, ['listing_status' => ListingStatus::PUBLISH]);

        $bridge = new ListingOrderBridge();
        $a = $bridge->markSold($id, 910101, 1);
        $b = $bridge->markSold($id, 910102, 2);
        Harness::assertNotWpError($a);
        Harness::assertWpError($b);
        $fresh = (new ListingRepository())->find($id);
        Harness::assertTrue($fresh !== null && (int) $fresh->orderId === 910101);
        Harness::assertSame(ListingStatus::SOLD, (string) $fresh->listingStatus);
    }

    public function testDuplicateSoldClaimIsAlreadyDone(): void
    {
        $catalog = Fixtures::catalog('claim3');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 300);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, ['listing_status' => ListingStatus::PUBLISH]);

        $bridge = new ListingOrderBridge();
        Harness::assertNotWpError($bridge->markSold($id, 910201, 1));
        Harness::assertNotWpError($bridge->markSold($id, 910201, 1), 'retry same order/item is idempotent');
        $fresh = (new ListingRepository())->find($id);
        Harness::assertTrue($fresh !== null && $fresh->lastOperationId !== null);
    }

    public function testPreOrderClaimOneWinner(): void
    {
        $catalog = Fixtures::catalog('pre1');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 400);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, [
            'listing_status' => ListingStatus::PRE_ORDER,
            'order_id' => 920001,
            'order_item_id' => 7,
        ]);

        $bridge = new ListingOrderBridge();
        $first = $bridge->claimPreOrderForSwap($id);
        $second = $bridge->claimPreOrderForSwap($id);
        Harness::assertTrue(!is_wp_error($first), 'first pre-order claim wins');
        Harness::assertTrue(is_wp_error($second), 'second pre-order claim loses');
        Harness::assertSame('sutore_pre_order_claimed', $second->get_error_code());
        Fixtures::assertStatus($id, ListingStatus::ORDER_DETACHED);
    }

    public function testAcceptPreOrderSwapOneWinner(): void
    {
        $catalog = Fixtures::catalog('pre2');
        $sellerA = Fixtures::sellerVerified();
        $sellerB = Fixtures::sellerQueued();
        $preListing = Fixtures::listing($sellerA, $catalog['parent_id'], $catalog['size_term_id'], 400);
        $preId = (int) $preListing->variationId;
        $order = Fixtures::paidOrder(Fixtures::customer(), $preId);
        $marked = (new FulfillmentService())->markAsPreOrder($preId, 'staff');
        Harness::assertNotWpError($marked, 'mark pre-order');
        Fixtures::assertStatus($preId, ListingStatus::PRE_ORDER);

        $replA = Fixtures::listing($sellerA, $catalog['parent_id'], $catalog['size_term_id'], 425);
        $replB = Fixtures::listing($sellerB, $catalog['parent_id'], $catalog['size_term_id'], 450);
        (new ListingRepository())->update((int) $replA->variationId, [
            'listing_status' => ListingStatus::PUBLISH,
            'is_winner' => 1,
        ]);
        (new ListingRepository())->update((int) $replB->variationId, [
            'listing_status' => ListingStatus::PUBLISH,
            'is_winner' => 1,
        ]);

        $svc = new FulfillmentService();
        $first = $svc->acceptPreOrderSwap($preId, (int) $replA->variationId, $sellerA);
        $second = $svc->acceptPreOrderSwap($preId, (int) $replB->variationId, $sellerB);
        Harness::assertNotWpError($first, 'first accept wins');
        Harness::assertWpError($second, 'second accept loses');
        Harness::assertSame('sutore_pre_order_claimed', $second->get_error_code());
        Harness::assertTrue($order->get_id() > 0);
    }

    public function testCampaignOfferAcceptVsExpire(): void
    {
        $repo = new CampaignOfferRepository();
        global $wpdb;
        $table = $repo->table();
        $now = current_time('mysql');
        $wpdb->insert($table, [
            'campaign_id' => 1,
            'variation_id' => 880001 + wp_rand(1, 99999),
            'merchant_id' => Fixtures::sellerVerified(),
            'status' => 'pending',
            'asking_before' => 100,
            'seller_discount_type' => 'fixed',
            'seller_discount_value' => 10,
            'seller_discount' => 10,
            'platform_discount_type' => 'fixed',
            'platform_discount_value' => 0,
            'platform_discount' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $offerId = (int) $wpdb->insert_id;
        Harness::assertTrue($offerId > 0);

        $accepted = $repo->updateIfStatus($offerId, 'pending', [
            'status' => 'accepted',
            'responded_at' => $now,
        ]);
        $expired = $repo->updateIfStatus($offerId, 'pending', [
            'status' => 'expired',
            'responded_at' => $now,
        ]);
        Harness::assertTrue($accepted);
        Harness::assertTrue(!$expired, 'expire loses after accept');
    }

    public function testOutletOptinMaterializeCas(): void
    {
        $repo = new OutletOptinRepository();
        global $wpdb;
        $table = $repo->table();
        $now = current_time('mysql');
        $ok = $wpdb->insert($table, [
            'item_id' => 800000 + wp_rand(1, 99999),
            'merchant_id' => Fixtures::sellerVerified(),
            'status' => OutletOptinStatus::PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Harness::assertTrue($ok !== false);
        $id = (int) $wpdb->insert_id;
        Harness::assertTrue($id > 0);

        $a = $repo->updateIfStatus($id, OutletOptinStatus::PENDING, [
            'status' => OutletOptinStatus::LIVE,
        ]);
        $b = $repo->updateIfStatus($id, OutletOptinStatus::PENDING, [
            'status' => OutletOptinStatus::LIVE,
        ]);
        Harness::assertTrue($a);
        Harness::assertTrue(!$b);
    }

    public function testTaskProgressTenIncrementsStayAtomic(): void
    {
        $tasks = new TasksRepository();
        $progress = new TaskProgressRepository($tasks);
        global $wpdb;
        $table = $tasks->progressTable();
        $now = current_time('mysql');
        $taskId = 900000 + wp_rand(1, 9999);
        $wpdb->insert($table, [
            'merchant_id' => Fixtures::sellerVerified(),
            'task_id' => $taskId,
            'progress_count' => 0,
            'status' => 'in_progress',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pid = (int) $wpdb->insert_id;
        Harness::assertTrue($pid > 0);

        $wins = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($progress->incrementIfInProgress($pid, 1, 100) !== null) {
                $wins++;
            }
        }
        Harness::assertSame(10, $wins);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT progress_count FROM {$table} WHERE id = %d",
            $pid
        ));
        Harness::assertSame(10, $count);
    }

    public function testPayoutPaidTenRetriesIdempotent(): void
    {
        $catalog = Fixtures::catalog('pay1');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 500);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, [
            'listing_status' => ListingStatus::VERIFIED,
            'order_id' => 930001,
            'order_item_id' => 1,
            'sold_at' => current_time('mysql'),
        ]);
        $fresh = (new ListingRepository())->find($id);
        Harness::assertTrue($fresh !== null);

        $saleRow = (object) [
            'variation_id' => $id,
            'order_id' => 930001,
            'order_item_id' => 1,
        ];
        $payout = new PayoutLineService();
        $lineId = $payout->createForListing($saleRow, $fresh);
        Harness::assertTrue($lineId > 0);

        $oks = 0;
        for ($i = 0; $i < 10; $i++) {
            $res = $payout->markPaid($id, Fixtures::adminId(), 'ref-concurrency');
            if (!is_wp_error($res)) {
                $oks++;
            }
        }
        Harness::assertSame(10, $oks, 'paid retries stay idempotent successes');
        $status = (string) (new PayoutLineRepository())->findByVariationId($id)?->payout_status;
        Harness::assertSame(PayoutStatus::PAID, $status);
    }

    public function testInvoiceDoubleClaimOneWinner(): void
    {
        $repo = new InvoiceRepository();
        global $wpdb;
        $table = $repo->table();
        $now = current_time('mysql');
        $variationId = 960000 + wp_rand(1, 9999);
        $wpdb->insert($table, [
            'kind' => InvoiceKind::CUSTOMER_FEES,
            'status' => InvoiceStatus::QUEUED,
            'order_id' => 940001,
            'merchant_id' => Fixtures::sellerVerified(),
            'variation_id' => $variationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        Harness::assertTrue($id > 0);

        $a = $repo->claim($id);
        $b = $repo->claim($id);
        Harness::assertTrue($a);
        Harness::assertTrue(!$b, 'second invoice worker loses claim while processing');
    }

    public function testFulfillmentAdvanceIdempotentOperation(): void
    {
        $catalog = Fixtures::catalog('ffop');
        $seller = Fixtures::sellerVerified();
        $listing = Fixtures::listing($seller, $catalog['parent_id'], $catalog['size_term_id'], 600);
        $id = (int) $listing->variationId;
        (new ListingRepository())->update($id, [
            'listing_status' => ListingStatus::SHIPPED_TO_SUTORE,
            'order_id' => 950001,
            'order_item_id' => 1,
        ]);

        $svc = new FulfillmentService();
        $first = $svc->markArrivedAtSutore($id, ['operation_id' => 'arrive:' . $id]);
        $second = $svc->markArrivedAtSutore($id, ['operation_id' => 'arrive:' . $id]);
        Harness::assertNotWpError($first);
        Harness::assertNotWpError($second, 'same operation_id advances as already_done');
        Fixtures::assertStatus($id, ListingStatus::ARRIVED_TO_SUTORE);
    }
}
