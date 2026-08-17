<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

use SutoreMarketplace\Shared\Sms\Settings\NetgsmSettings;

final class NetgsmClient
{
    private const API_URL = 'https://api.netgsm.com.tr/sms/rest/v2/send';
    private const TIMEOUT = 12;

    public function sendSingle(string $phone, string $message): NetgsmSendResult
    {
        if (!NetgsmSettings::isConfigured()) {
            return NetgsmSendResult::fail(__('Netgsm credentials are not configured.', 'sutore-marketplace'));
        }

        $phone = PhoneNormalizer::toDomestic($phone);
        if (!PhoneNormalizer::isValidDomestic($phone)) {
            return NetgsmSendResult::fail(__('Invalid phone number for SMS.', 'sutore-marketplace'));
        }

        $message = trim($message);
        if ($message === '') {
            return NetgsmSendResult::fail(__('SMS message is empty.', 'sutore-marketplace'));
        }

        $body = wp_json_encode([
            'msgheader' => NetgsmSettings::header(),
            'encoding' => NetgsmSettings::encoding(),
            'iysfilter' => '',
            'partnercode' => '',
            'messages' => [
                [
                    'msg' => $message,
                    'no' => $phone,
                ],
            ],
        ]);

        if (!is_string($body)) {
            return NetgsmSendResult::fail(__('Could not encode SMS payload.', 'sutore-marketplace'));
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode(
                    NetgsmSettings::usercode() . ':' . NetgsmSettings::password(),
                    true
                ),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return NetgsmSendResult::fail($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            return NetgsmSendResult::fail(
                sprintf(
                    /* translators: 1: HTTP status code, 2: API response body */
                    __('Netgsm API error (HTTP %1$d): %2$s', 'sutore-marketplace'),
                    $status,
                    $this->sanitizeLogSnippet($rawBody)
                )
            );
        }

        $parsed = json_decode($rawBody, true);
        if (is_array($parsed)) {
            $code = (string) ($parsed['code'] ?? $parsed['status'] ?? '');
            if ($code !== '' && !in_array($code, ['00', '0', '200'], true)) {
                $description = (string) ($parsed['description'] ?? $parsed['message'] ?? $rawBody);

                return NetgsmSendResult::fail(
                    sprintf(
                        /* translators: 1: Netgsm error code, 2: error description */
                        __('Netgsm rejected the message (%1$s): %2$s', 'sutore-marketplace'),
                        $code,
                        $this->sanitizeLogSnippet($description)
                    )
                );
            }
        }

        return NetgsmSendResult::ok($rawBody !== '' ? $rawBody : '00');
    }

    private function sanitizeLogSnippet(string $text): string
    {
        $text = wp_strip_all_tags($text);

        return strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text;
    }
}
