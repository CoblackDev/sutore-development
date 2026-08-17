<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings;
use SutoreMarketplace\Shared\Security\SecretBox;

/**
 * Paraşüt API v4 JSON:API client. Rate limit: 10 requests / 10 seconds.
 */
final class ParasutClient
{
    private const BASE = 'https://api.parasut.com';
    private const RATE_WINDOW = 10;
    private const RATE_MAX = 10;

    /** @var list<int> */
    private static array $requestTimes = [];

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        $url = $path;
        if ($query !== []) {
            $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @param array<string, mixed> $body */
    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, $body);
    }

    public function companyPath(string $suffix): string
    {
        $companyId = InvoiceSettings::companyId();

        return '/v4/' . $companyId . '/' . ltrim($suffix, '/');
    }

    /**
     * @return array{ok:bool,status:int,body:string,content_type:string,error?:string}
     */
    public function requestRaw(string $method, string $path): array
    {
        $this->throttle();
        $token = $this->accessToken();
        if ($token === '') {
            return [
                'ok' => false,
                'status' => 401,
                'body' => '',
                'content_type' => '',
                'error' => __('Paraşüt is not authenticated.', 'sutore-marketplace'),
            ];
        }

        $url = str_starts_with($path, 'http') ? $path : self::BASE . $path;
        $response = $this->sendBinaryRequest($url, $this->binaryRequestArgs($method, [
            'Accept' => 'application/json, application/pdf, */*',
            'Authorization' => 'Bearer ' . $token,
        ]));
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'content_type' => '',
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = $this->maybeGunzip((string) wp_remote_retrieve_body($response));
        $contentType = (string) wp_remote_retrieve_header($response, 'content-type');

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, bool $auth = true, bool $retried = false): array
    {
        $this->throttle();

        $headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ];
        if ($auth) {
            $token = $this->accessToken();
            if ($token === '') {
                return ['ok' => false, 'status' => 401, 'error' => __('Paraşüt is not authenticated.', 'sutore-marketplace')];
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $url = str_starts_with($path, 'http') ? $path : self::BASE . $path;
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status === 401 && $auth && !$retried) {
            $this->clearTokens();
            $token = $this->accessToken(true);
            if ($token !== '') {
                return $this->request($method, $path, $body, true, true);
            }
        }

        if ($status >= 200 && $status < 300) {
            $decoded['ok'] = true;
            $decoded['status'] = $status;

            return $decoded;
        }

        return [
            'ok' => false,
            'status' => $status,
            'error' => $this->errorMessage($decoded, $raw, $status),
            'body' => $decoded,
        ];
    }

    public function download(string $url): string|\WP_Error
    {
        $this->throttle();
        $response = $this->sendBinaryRequest($url, $this->binaryRequestArgs('GET', [
            'Accept' => 'application/pdf, */*',
        ]));
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 204) {
            return new \WP_Error('sutore_invoice_pdf_pending', __('e-Archive PDF is not ready yet.', 'sutore-marketplace'));
        }
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('sutore_invoice_pdf_http', sprintf(
                /* translators: %d: HTTP status */
                __('PDF download failed (%d).', 'sutore-marketplace'),
                $status
            ));
        }

        $body = $this->maybeGunzip((string) wp_remote_retrieve_body($response));

        return $body !== '' ? $body : new \WP_Error('sutore_invoice_pdf_empty', __('PDF download was empty.', 'sutore-marketplace'));
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $tokens = $this->readTokens();
        $now = time();
        if (!$forceRefresh && ($tokens['access_token'] ?? '') !== '' && (int) ($tokens['expires_at'] ?? 0) > ($now + 60)) {
            return (string) $tokens['access_token'];
        }

        if (!$forceRefresh && ($tokens['refresh_token'] ?? '') !== '') {
            $refreshed = $this->fetchToken([
                'grant_type' => 'refresh_token',
                'client_id' => InvoiceSettings::clientId(),
                'client_secret' => InvoiceSettings::clientSecret(),
                'refresh_token' => (string) $tokens['refresh_token'],
            ]);
            if ($refreshed !== '') {
                return $refreshed;
            }
        }

        return $this->fetchToken([
            'grant_type' => 'password',
            'client_id' => InvoiceSettings::clientId(),
            'client_secret' => InvoiceSettings::clientSecret(),
            'username' => InvoiceSettings::username(),
            'password' => InvoiceSettings::password(),
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
        ]);
    }

    /** @param array<string, string> $fields */
    private function fetchToken(array $fields): string
    {
        $this->throttle();
        $response = wp_remote_post(self::BASE . '/oauth/token', [
            'timeout' => 30,
            'body' => $fields,
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            return '';
        }

        $this->writeTokens([
            'access_token' => (string) $decoded['access_token'],
            'refresh_token' => (string) ($decoded['refresh_token'] ?? ''),
            'expires_at' => time() + max(60, (int) ($decoded['expires_in'] ?? 7200)),
        ]);

        return (string) $decoded['access_token'];
    }

    /** @return array<string, mixed> */
    private function readTokens(): array
    {
        $raw = SecretBox::open((string) get_option(InvoiceSettings::TOKEN_OPTION, ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $tokens */
    private function writeTokens(array $tokens): void
    {
        update_option(InvoiceSettings::TOKEN_OPTION, SecretBox::seal((string) wp_json_encode($tokens)), false);
    }

    private function clearTokens(): void
    {
        delete_option(InvoiceSettings::TOKEN_OPTION);
    }

    private function maybeGunzip(string $body): string
    {
        if ($body !== '' && str_starts_with($body, "\x1f\x8b")) {
            $decoded = gzdecode($body);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $body;
    }

    /**
     * Paraşüt PDF/CDN responses sometimes send a Content-Encoding curl cannot decode
     * (cURL error 61, e.g. identity). Skip auto-decompress and ignore that header.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function binaryRequestArgs(string $method, array $headers): array
    {
        return [
            'method' => $method,
            'timeout' => 60,
            'decompress' => false,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    private function sendBinaryRequest(string $url, array $args): array|\WP_Error
    {
        $disableDecoding = static function ($handle): void {
            if (defined('CURLOPT_HTTP_CONTENT_DECODING') && (is_resource($handle) || $handle instanceof \CurlHandle)) {
                curl_setopt($handle, CURLOPT_HTTP_CONTENT_DECODING, false);
            }
        };
        add_action('http_api_curl', $disableDecoding, 10, 1);
        try {
            return wp_remote_request($url, $args);
        } finally {
            remove_action('http_api_curl', $disableDecoding, 10);
        }
    }

    private function throttle(): void
    {
        $now = time();
        self::$requestTimes = array_values(array_filter(
            self::$requestTimes,
            static fn (int $t): bool => $t > $now - self::RATE_WINDOW
        ));
        if (count(self::$requestTimes) >= self::RATE_MAX) {
            $wait = self::RATE_WINDOW - ($now - self::$requestTimes[0]) + 1;
            if ($wait > 0 && $wait <= self::RATE_WINDOW) {
                sleep($wait);
            }
            $now = time();
            self::$requestTimes = array_values(array_filter(
                self::$requestTimes,
                static fn (int $t): bool => $t > $now - self::RATE_WINDOW
            ));
        }
        self::$requestTimes[] = $now;
    }

    /** @param array<string, mixed> $decoded */
    private function errorMessage(array $decoded, string $raw, int $status): string
    {
        $errors = $decoded['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $parts = [];
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $detail = trim((string) ($error['detail'] ?? $error['title'] ?? ''));
                $source = '';
                if (isset($error['source']) && is_array($error['source'])) {
                    $source = trim((string) ($error['source']['pointer'] ?? $error['source']['parameter'] ?? ''));
                }
                if ($detail !== '' && $source !== '') {
                    $parts[] = $detail . ' (' . $source . ')';
                } elseif ($detail !== '') {
                    $parts[] = $detail;
                }
            }
            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return sprintf(
            /* translators: %d: HTTP status */
            __('Paraşüt request failed (%d).', 'sutore-marketplace'),
            $status
        ) . ($raw !== '' ? ' ' . substr(wp_strip_all_tags($raw), 0, 300) : '');
    }
}
