<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Services\ListingBulkImportService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class ListingsBulkController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/listings/bulk/template', [
            'methods' => 'GET',
            'callback' => [$this, 'template'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/bulk/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'validate'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/bulk/update-row', [
            'methods' => 'POST',
            'callback' => [$this, 'updateRow'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/bulk/delete-row', [
            'methods' => 'POST',
            'callback' => [$this, 'deleteRow'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/bulk/commit', [
            'methods' => 'POST',
            'callback' => [$this, 'commit'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/bulk/jobs/(?P<job_id>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'job'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return is_user_logged_in() && !is_wp_error(ListingPolicy::assertCanManage());
    }

    public function template(\WP_REST_Request $req): void
    {
        $csv = (new ListingBulkImportService())->templateCsv();
        $filename = 'sutore-listings-import-template.csv';

        status_header(200);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    public function validate(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $csv = (string) ($params['csv'] ?? '');
        if ($csv === '' && !empty($_FILES['file']['tmp_name'])) {
            $csv = (string) file_get_contents((string) $_FILES['file']['tmp_name']);
        }

        if (trim($csv) === '') {
            return RestResponse::fail(__('Upload a CSV file.', 'sutore-marketplace'), 400);
        }

        $result = (new ListingBulkImportService())->validate($csv, get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function updateRow(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $token = sanitize_text_field((string) ($params['import_token'] ?? ''));
        $line = (int) ($params['line'] ?? 0);
        $price = (string) ($params['price'] ?? '');

        if ($token === '') {
            return RestResponse::fail(__('Import session is missing.', 'sutore-marketplace'), 400);
        }
        if ($line <= 0) {
            return RestResponse::fail(__('Row number is missing.', 'sutore-marketplace'), 400);
        }

        $result = (new ListingBulkImportService())->updateRowPrice($token, get_current_user_id(), $line, $price);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function deleteRow(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $token = sanitize_text_field((string) ($params['import_token'] ?? ''));
        $line = (int) ($params['line'] ?? 0);

        if ($token === '') {
            return RestResponse::fail(__('Import session is missing.', 'sutore-marketplace'), 400);
        }
        if ($line <= 0) {
            return RestResponse::fail(__('Row number is missing.', 'sutore-marketplace'), 400);
        }

        $result = (new ListingBulkImportService())->deleteRow($token, get_current_user_id(), $line);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function commit(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $token = sanitize_text_field((string) ($params['import_token'] ?? ''));
        if ($token === '') {
            return RestResponse::fail(__('Import session is missing.', 'sutore-marketplace'), 400);
        }

        $result = (new ListingBulkImportService())->queueJob($token, get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function job(\WP_REST_Request $req): \WP_REST_Response
    {
        $jobId = sanitize_text_field((string) $req->get_param('job_id'));
        if ($jobId === '') {
            return RestResponse::fail(__('Import job is missing.', 'sutore-marketplace'), 400);
        }

        $result = (new ListingBulkImportService())->getJob($jobId, get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }
}
