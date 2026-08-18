<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class ParentProductSearchTest
{
    public function testSearchFindsByTitleAndSku(): void
    {
        $catalog = Fixtures::catalog('namesku1');
        $parentId = (int) $catalog['parent_id'];
        $product = wc_get_product($parentId);
        Harness::assertTrue($product instanceof \WC_Product, 'fixture product');
        $sku = trim((string) $product->get_sku('edit'));
        Harness::assertTrue($sku !== '', 'fixture has sku');

        $byTitle = ProductCodeLookup::searchParents('namesku1');
        Harness::assertTrue(self::containsId($byTitle, $parentId), 'title fragment matches');

        $bySku = ProductCodeLookup::searchParents($sku);
        Harness::assertTrue(self::containsId($bySku, $parentId), 'full sku matches');

        $empty = ProductCodeLookup::searchParents('   ');
        Harness::assertSame([], $empty);
    }

    public function testRestSearchParentsAcceptsNameSkuAndQAlias(): void
    {
        $catalog = Fixtures::catalog('restsrch');
        $parentId = (int) $catalog['parent_id'];
        $product = wc_get_product($parentId);
        $sku = $product ? trim((string) $product->get_sku('edit')) : '';
        wp_set_current_user(Fixtures::sellerVerified());

        $byName = self::searchParentsRequest(['product_code' => 'restsrch']);
        Harness::assertSame(200, $byName->get_status());
        Harness::assertTrue(self::restContainsId($byName, $parentId), 'REST name search');

        $bySku = self::searchParentsRequest(['product_code' => $sku]);
        Harness::assertSame(200, $bySku->get_status());
        Harness::assertTrue(self::restContainsId($bySku, $parentId), 'REST sku search');

        $byAlias = self::searchParentsRequest(['q' => 'restsrch']);
        Harness::assertSame(200, $byAlias->get_status());
        Harness::assertTrue(self::restContainsId($byAlias, $parentId), 'REST q alias');

        $empty = self::searchParentsRequest([]);
        Harness::assertTrue($empty->get_status() >= 400, 'empty search is rejected');
    }

    public function testRestSizesReturnParentTerms(): void
    {
        $catalog = Fixtures::catalog('sizesrch');
        wp_set_current_user(Fixtures::sellerVerified());
        $req = new \WP_REST_Request('GET', '/sutore-marketplace/v1/sizes/' . (int) $catalog['parent_id']);
        $res = rest_do_request($req);
        Harness::assertSame(200, $res->get_status());
        $body = $res->get_data();
        $items = is_array($body) ? ($body['data']['items'] ?? []) : [];
        Harness::assertTrue(is_array($items) && $items !== [], 'sizes payload');
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['term_id'] ?? 0);
        }
        Harness::assertTrue(in_array((int) $catalog['size_term_id'], $ids, true), 'parent size term listed');
    }

    /**
     * @param array<string, string> $params
     */
    private static function searchParentsRequest(array $params): \WP_REST_Response
    {
        $req = new \WP_REST_Request('GET', '/sutore-marketplace/v1/search-parents');
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }

        return rest_do_request($req);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function containsId(array $items, int $id): bool
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        return false;
    }

    private static function restContainsId(\WP_REST_Response $res, int $id): bool
    {
        $body = $res->get_data();
        $items = is_array($body) ? ($body['data']['items'] ?? []) : [];

        return is_array($items) && self::containsId($items, $id);
    }
}
