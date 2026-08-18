<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

use SutoreMarketplace\Shared\Sms\Settings\NetgsmSettings;
use SutoreMarketplace\Shared\Sms\Settings\SmsSimulationSettings;

final class IysClient
{
    private const API_URL = 'https://api.netgsm.com.tr/iys/add';
    private const TIMEOUT = 10;

    /**
     * @param list<string> $identifiers
     */
    public function submit(array $identifiers, string $status): bool
    {
        $consentDate = current_time('mysql');
        $records = IysPayload::records($identifiers, $status, $consentDate);
        if ($records === []) {
            return true;
        }

        if (SmsSimulationSettings::isEnabled()) {
            do_action('sutore_marketplace_iys_recorded', $status, $records, true);

            return true;
        }

        if (!NetgsmSettings::isIysConfigured()) {
            return false;
        }

        $body = wp_json_encode([
            'header' => [
                'username' => NetgsmSettings::usercode(),
                'password' => NetgsmSettings::password(),
                'brandCode' => NetgsmSettings::brandCode(),
            ],
            'body' => [
                'data' => $records,
            ],
        ]);
        if (!is_string($body)) {
            return false;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            do_action('sutore_marketplace_iys_failed', $status, $records, $response->get_error_message());

            return false;
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            do_action('sutore_marketplace_iys_failed', $status, $records, $rawBody);

            return false;
        }

        do_action('sutore_marketplace_iys_recorded', $status, $records, false);

        return true;
    }
}
