<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

final class IysPayload
{
    public const STATUS_GRANT = 'ONAY';
    public const STATUS_REVOKE = 'RET';
    public const SOURCE = 'HS_WEB';
    public const RECIPIENT_TYPE = 'BIREYSEL';
    public const TYPE_EMAIL = 'EPOSTA';
    public const TYPE_SMS = 'MESAJ';

    /**
     * @param list<string> $identifiers Emails and/or phone numbers
     * @return list<array{type:string,source:string,recipient:string,status:string,consentDate:string,recipientType:string}>
     */
    public static function records(array $identifiers, string $status, string $consentDate): array
    {
        if (!in_array($status, [self::STATUS_GRANT, self::STATUS_REVOKE], true)) {
            return [];
        }

        $records = [];
        $seen = [];
        foreach ($identifiers as $raw) {
            $identifier = self::normalizeIdentifier((string) $raw);
            if ($identifier === '' || isset($seen[$identifier])) {
                continue;
            }
            $seen[$identifier] = true;
            $records[] = [
                'type' => self::isEmail($identifier) ? self::TYPE_EMAIL : self::TYPE_SMS,
                'source' => self::SOURCE,
                'recipient' => $identifier,
                'status' => $status,
                'consentDate' => $consentDate,
                'recipientType' => self::RECIPIENT_TYPE,
            ];
        }

        return $records;
    }

    public static function normalizeIdentifier(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        if (self::isEmail($value)) {
            return strtolower($value);
        }

        return PhoneNormalizer::toIysRecipient($value);
    }

    public static function isEmail(string $value): bool
    {
        return is_email($value) !== false;
    }
}
