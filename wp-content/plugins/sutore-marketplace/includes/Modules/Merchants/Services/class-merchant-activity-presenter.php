<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\MerchantEventType;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class MerchantActivityPresenter
{
    public function __construct(
        private readonly MerchantEventsRepository $events = new MerchantEventsRepository(),
    ) {
    }

    /**
     * @return list<array{date:string,event_label:string,event_type:string,actor:string,summary:string}>
     */
    public function forMerchant(int $merchantId, int $limit = 80): array
    {
        $rows = [];
        foreach ($this->events->forMerchant($merchantId, $limit) as $event) {
            $payload = json_decode((string) ($event->payload ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $eventType = (string) ($event->event_type ?? '');
            $createdAt = (string) ($event->created_at ?? '');
            $actor = (string) ($payload['actor_login'] ?? '');
            if ($actor === '' && !empty($payload['actor_user_id'])) {
                $actor = '#' . (int) $payload['actor_user_id'];
            }
            if ($actor === '') {
                $actor = 'system';
            }

            $rows[] = [
                'date' => $createdAt,
                'event_label' => MerchantEventType::label($eventType),
                'event_type' => $eventType,
                'actor' => $actor,
                'summary' => $this->payloadSummary($payload, $eventType),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private function payloadSummary(array $payload, string $eventType): string
    {
        return match ($eventType) {
            'merchant_iban_changed' => sprintf(
                /* translators: 1: old last4, 2: new last4 */
                __('IBAN •••• %1$s → •••• %2$s', 'sutore-marketplace'),
                (string) ($payload['old_iban_last4'] ?? '—'),
                (string) ($payload['new_iban_last4'] ?? '—')
            ),
            'merchant_phone_changed' => sprintf(
                /* translators: 1: old phone, 2: new phone */
                __('%1$s → %2$s', 'sutore-marketplace'),
                (string) ($payload['old_phone'] ?? '—'),
                (string) ($payload['new_phone'] ?? '—')
            ),
            'merchant_email_changed' => sprintf(
                /* translators: 1: old email, 2: new email */
                __('%1$s → %2$s', 'sutore-marketplace'),
                (string) ($payload['old_email'] ?? '—'),
                (string) ($payload['new_email'] ?? '—')
            ),
            'merchant_name_changed' => sprintf(
                /* translators: 1: old name, 2: new name */
                __('%1$s → %2$s', 'sutore-marketplace'),
                (string) ($payload['old_name'] ?? '—'),
                (string) ($payload['new_name'] ?? '—')
            ),
            'merchant_address_changed' => sprintf(
                /* translators: 1: old city/state, 2: new city/state */
                __('%1$s / %2$s → %3$s / %4$s', 'sutore-marketplace'),
                (string) ($payload['old_city'] ?? '—'),
                (string) ($payload['old_state'] ?? '—'),
                (string) ($payload['new_city'] ?? '—'),
                (string) ($payload['new_state'] ?? '—')
            ),
            'merchant_tckno_changed' => sprintf(
                /* translators: 1: old last4, 2: new last4 */
                __('TC •••• %1$s → •••• %2$s', 'sutore-marketplace'),
                (string) ($payload['old_tckno_last4'] ?? '—'),
                (string) ($payload['new_tckno_last4'] ?? '—')
            ),
            'merchant_birth_year_changed' => sprintf(
                /* translators: 1: old year, 2: new year */
                __('%1$s → %2$s', 'sutore-marketplace'),
                (string) ($payload['old_birth_year'] ?? '—'),
                (string) ($payload['new_birth_year'] ?? '—')
            ),
            'merchant_level_changed' => sprintf(
                /* translators: 1: old level label, 2: new level label */
                __('%1$s → %2$s', 'sutore-marketplace'),
                MerchantLevels::labelForStatus((string) ($payload['old_status'] ?? '')),
                MerchantLevels::labelForStatus((string) ($payload['new_status'] ?? ''))
            ),
            'merchant_restriction_created' => sprintf(
                /* translators: 1: restriction label, 2: reason */
                __('%1$s — %2$s', 'sutore-marketplace'),
                self::restrictionLabel((string) ($payload['restriction_key'] ?? '')),
                (string) ($payload['reason'] ?? '')
            ),
            'merchant_restriction_deactivated' => self::restrictionLabel((string) ($payload['restriction_key'] ?? '')),
            'merchant_profile_updated_by_staff' => !empty($payload['changed_fields']) && is_array($payload['changed_fields'])
                ? implode(', ', array_map([self::class, 'fieldLabel'], $payload['changed_fields']))
                : '',
            'merchant_tc_verified' => (string) ($payload['method'] ?? ''),
            'merchant_commission_override_set' => sprintf(
                /* translators: 1: commission percent, 2: expiry or dash */
                __('Commission %1$s%% — expires %2$s', 'sutore-marketplace'),
                (string) ($payload['effective_percent'] ?? $payload['commission_percent'] ?? '—'),
                !empty($payload['expires_at']) ? (string) $payload['expires_at'] : __('No end date', 'sutore-marketplace')
            ),
            'merchant_commission_override_deleted' => sprintf(
                /* translators: %s: commission percent */
                __('Deleted override %s%%', 'sutore-marketplace'),
                (string) ($payload['commission_percent'] ?? '—')
            ),
            'merchant_payout_commission_adjusted' => sprintf(
                /* translators: 1: previous commission percent, 2: new commission percent */
                __('Commission %1$s%% → %2$s%%', 'sutore-marketplace'),
                (string) ($payload['previous_percent'] ?? '—'),
                (string) ($payload['commission_percent'] ?? '—')
            ),
            'merchant_listing_commission_set' => isset($payload['commission_percent']) && $payload['commission_percent'] !== null && $payload['commission_percent'] !== ''
                ? sprintf(
                    /* translators: %s: commission percent */
                    __('Product commission %s%%', 'sutore-marketplace'),
                    (string) $payload['commission_percent']
                )
                : __('Product commission cleared', 'sutore-marketplace'),
            'merchant_referral_accepted' => sprintf(
                /* translators: %s: inviter user id */
                __('Invited by seller #%s', 'sutore-marketplace'),
                (string) ($payload['inviter_id'] ?? '—')
            ),
            'merchant_referral_inviter_rewarded' => sprintf(
                /* translators: 1: invitee name or id, 2: points off */
                __('First sale by %1$s — %2$s points off', 'sutore-marketplace'),
                (string) ($payload['invitee_name'] ?? ('#' . ($payload['invitee_id'] ?? '—'))),
                (string) ($payload['points_off'] ?? '—')
            ),
            'merchant_referral_inviter_capped' => sprintf(
                /* translators: 1: used count, 2: max rewards */
                __('Period limit %1$s / %2$s', 'sutore-marketplace'),
                (string) ($payload['used'] ?? '—'),
                (string) ($payload['max_rewards'] ?? '—')
            ),
            default => '',
        };
    }

    private static function restrictionLabel(string $key): string
    {
        return match ($key) {
            'listing_create_ban' => __('Ban creating products', 'sutore-marketplace'),
            'price_update_ban' => __('Ban price updates', 'sutore-marketplace'),
            'disabled_account' => __('Disable account', 'sutore-marketplace'),
            default => $key,
        };
    }

    private static function fieldLabel(mixed $field): string
    {
        $key = (string) $field;

        return match ($key) {
            'iban' => __('IBAN', 'sutore-marketplace'),
            'phone' => __('Phone', 'sutore-marketplace'),
            'email' => __('Email', 'sutore-marketplace'),
            'name' => __('Name', 'sutore-marketplace'),
            'address' => __('Address', 'sutore-marketplace'),
            'tckno' => __('TC Identity Number', 'sutore-marketplace'),
            'birth_year' => __('Year of Birth', 'sutore-marketplace'),
            'tc_verified' => __('TC identity', 'sutore-marketplace'),
            'level' => __('Level', 'sutore-marketplace'),
            default => $key,
        };
    }
}
