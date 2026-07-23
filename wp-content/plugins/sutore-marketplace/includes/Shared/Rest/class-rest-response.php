<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Rest;

final class RestResponse
{
    public static function success(mixed $data = null, int $status = 200): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function fail(string $message, int $status = 400, string $code = '', mixed $data = null): \WP_REST_Response
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if ($code !== '') {
            $body['code'] = $code;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }

        return new \WP_REST_Response($body, $status);
    }

    public static function fromWpError(\WP_Error $error, int $defaultStatus = 400): \WP_REST_Response
    {
        $data = $error->get_error_data();
        $status = $defaultStatus;
        if (is_array($data) && isset($data['status'])) {
            $status = (int) $data['status'];
        }

        return self::fail(
            $error->get_error_message(),
            $status,
            $error->get_error_code(),
            is_array($data) ? $data : null
        );
    }
}
