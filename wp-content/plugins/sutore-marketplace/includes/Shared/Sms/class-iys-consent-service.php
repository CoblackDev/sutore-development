<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

use SutoreMarketplace\Shared\Effects\OutboundEffectService;
use SutoreMarketplace\Shared\Effects\OutboundEffectType;

final class IysConsentService
{
    /**
     * @param list<string> $identifiers
     */
    public function grant(array $identifiers): void
    {
        $this->enqueue(IysPayload::STATUS_GRANT, $identifiers);
    }

    /**
     * @param list<string> $identifiers
     */
    public function revoke(array $identifiers): void
    {
        $this->enqueue(IysPayload::STATUS_REVOKE, $identifiers);
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
            $this->enqueue($call['status'], $call['identifiers']);
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
     * @param list<string> $identifiers
     */
    private function enqueue(string $status, array $identifiers): void
    {
        $identifiers = array_values(array_filter(array_map('strval', $identifiers)));
        if ($identifiers === []) {
            return;
        }

        $forHash = $identifiers;
        sort($forHash);
        $dedupe = 'iys:' . $status . ':' . hash('sha256', implode('|', $forHash) . '|' . wp_generate_uuid4());
        (new OutboundEffectService())->enqueue(
            OutboundEffectType::IYS,
            [
                'status' => $status,
                'identifiers' => $identifiers,
            ],
            $dedupe
        );
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
