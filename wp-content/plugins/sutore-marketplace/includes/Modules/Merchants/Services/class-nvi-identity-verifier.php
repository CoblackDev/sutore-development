<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Shared\Settings\Settings;

/**
 * NVI KPS Public TC kimlik doğrulama (HTTP SOAP).
 *
 * Not: NVI public web servisi Eylül 2025 itibarıyla programatik erişime kapatılmış olabilir.
 * Kurumsal KPS erişimi için ayarlardan endpoint güncellenebilir.
 */
final class NviIdentityVerifier
{
    private const DEFAULT_ENDPOINT = 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx';
    private const SOAP_ACTION = 'http://tckimlik.nvi.gov.tr/WS/TCKimlikNoDogrula';
    private const SOAP_NS = 'http://tckimlik.nvi.gov.tr/WS';

    public static function verify(string $tc, string $firstName, string $lastName, int $birthYear): bool|\WP_Error
    {
        $tc = preg_replace('/\D/', '', $tc) ?? '';
        if (!TcValidator::isValid($tc)) {
            return new \WP_Error(
                'sutore_tc_invalid',
                __('Invalid TC identity number.', 'sutore-marketplace')
            );
        }

        if ($birthYear < 1900 || $birthYear > (int) gmdate('Y')) {
            return new \WP_Error(
                'sutore_birth_year_invalid',
                __('Invalid year of birth.', 'sutore-marketplace')
            );
        }

        $firstName = self::normalizeName($firstName);
        $lastName = self::normalizeName($lastName);
        if ($firstName === '' || $lastName === '') {
            return new \WP_Error(
                'sutore_name_required',
                __('First and last name are required for TC verification.', 'sutore-marketplace')
            );
        }

        return self::callService($tc, $firstName, $lastName, $birthYear);
    }

    private static function callService(string $tc, string $firstName, string $lastName, int $birthYear): bool|\WP_Error
    {
        $endpoint = Settings::tcVerificationNviEndpoint();
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<TCKimlikNoDogrula xmlns="' . self::SOAP_NS . '">'
            . '<TCKimlikNo>' . esc_xml((int) $tc) . '</TCKimlikNo>'
            . '<Ad>' . esc_xml($firstName) . '</Ad>'
            . '<Soyad>' . esc_xml($lastName) . '</Soyad>'
            . '<DogumYili>' . esc_xml((string) $birthYear) . '</DogumYili>'
            . '</TCKimlikNoDogrula>'
            . '</soap:Body></soap:Envelope>';

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"' . self::SOAP_ACTION . '"',
            ],
            'body' => $envelope,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'sutore_nvi_service_error',
                __('Could not reach the TC identity verification service. Please try again later.', 'sutore-marketplace'),
                ['fault' => $response->get_error_message()]
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300 || $body === '') {
            return new \WP_Error(
                'sutore_nvi_service_error',
                __('Could not reach the TC identity verification service. Please try again later.', 'sutore-marketplace'),
                ['http_status' => $status]
            );
        }

        if (self::isHtmlErrorResponse($body)) {
            return new \WP_Error(
                'sutore_nvi_unavailable',
                __('The NVI public TC verification service is currently closed to programmatic access. Use corporate KPS integration or development mode (algorithm verification).', 'sutore-marketplace')
            );
        }

        $verified = self::parseVerificationResult($body);
        if ($verified === null) {
            return new \WP_Error(
                'sutore_nvi_service_error',
                __('Invalid response received from the TC identity verification service.', 'sutore-marketplace')
            );
        }

        if (!$verified) {
            return new \WP_Error(
                'sutore_nvi_mismatch',
                __('TC identity details did not match population registry records.', 'sutore-marketplace')
            );
        }

        return true;
    }

    private static function isHtmlErrorResponse(string $body): bool
    {
        $lower = strtolower($body);

        return str_contains($lower, '<html')
            || str_contains($lower, 'master-body')
            || str_contains($lower, 'yetkiniz yoktur')
            || str_contains($lower, 'teknik bir hata');
    }

    private static function parseVerificationResult(string $body): ?bool
    {
        if (preg_match('/<TCKimlikNoDogrulaResult>\s*(true|false)\s*<\/TCKimlikNoDogrulaResult>/i', $body, $matches)) {
            return strtolower($matches[1]) === 'true';
        }

        return null;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($name, 'UTF-8');
        }

        return strtoupper($name);
    }
}
