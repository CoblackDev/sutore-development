<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

final class IysConsentService
{
    public function __construct(
        private readonly IysClient $client = new IysClient(),
    ) {
    }

    /**
     * @param list<string> $identifiers
     */
    public function grant(array $identifiers): void
    {
        $this->client->submit($identifiers, IysPayload::STATUS_GRANT);
    }

    /**
     * @param list<string> $identifiers
     */
    public function revoke(array $identifiers): void
    {
        $this->client->submit($identifiers, IysPayload::STATUS_REVOKE);
    }

    public function sync(
        string $oldEmail,
        string $oldPhone,
        string $newEmail,
        string $newPhone,
        bool $oldConsent,
        bool $newConsent
    ): void {
        foreach (self::plannedCalls($oldEmail, $oldPhone, $newEmail, $newPhone, $oldConsent, $newConsent) as $call) {
            $this->client->submit($call['identifiers'], $call['status']);
        }
    }

    /**
     * @return list<array{status:string,identifiers:list<string>}>
     */
    public static function plannedCalls(
        string $oldEmail,
        string $oldPhone,
        string $newEmail,
        string $newPhone,
        bool $oldConsent,
        bool $newConsent
    ): array {
        $oldIds = self::identifiers($oldEmail, $oldPhone);
        $newIds = self::identifiers($newEmail, $newPhone);

        if (!$oldConsent && !$newConsent) {
            return [];
        }

        if ($oldConsent && !$newConsent) {
            return [[
                'status' => IysPayload::STATUS_REVOKE,
                'identifiers' => array_values(array_unique(array_merge($oldIds, $newIds))),
            ]];
        }

        if (!$oldConsent && $newConsent) {
            return [[
                'status' => IysPayload::STATUS_GRANT,
                'identifiers' => $newIds,
            ]];
        }

        $calls = [];
        $removed = array_values(array_diff($oldIds, $newIds));
        $added = array_values(array_diff($newIds, $oldIds));
        if ($removed !== []) {
            $calls[] = [
                'status' => IysPayload::STATUS_REVOKE,
                'identifiers' => $removed,
            ];
        }
        if ($added !== []) {
            $calls[] = [
                'status' => IysPayload::STATUS_GRANT,
                'identifiers' => $added,
            ];
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private static function identifiers(string $email, string $phone): array
    {
        $out = [];
        $email = IysPayload::normalizeIdentifier($email);
        if ($email !== '') {
            $out[] = $email;
        }
        $phone = IysPayload::normalizeIdentifier($phone);
        if ($phone !== '') {
            $out[] = $phone;
        }

        return $out;
    }
}
