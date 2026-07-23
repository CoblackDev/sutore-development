<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Listings\Services\ImportedProductService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class ImportedProductsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('sutore-marketplace/v1', '/admin/imported-products', [
            'methods' => 'POST',
            'callback' => [$this, 'markImported'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'variation_ids' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(AdminMenu::CAP);
    }

    public function markImported(\WP_REST_Request $request): \WP_REST_Response
    {
        $variationIds = array_values(array_filter(array_map(
            'absint',
            (array) $request->get_param('variation_ids')
        )));

        if ($variationIds === []) {
            return RestResponse::fail(__('Enter at least one variation ID.', 'sutore-marketplace'), 400);
        }

        $result = (new ImportedProductService())->markVariationsImported($variationIds);
        $result['message'] = sprintf(
            /* translators: %d: number of variations */
            _n(
                '%d variation marked as imported.',
                '%d variations marked as imported.',
                $result['marked'],
                'sutore-marketplace'
            ),
            $result['marked']
        );

        return RestResponse::success($result);
    }
}
