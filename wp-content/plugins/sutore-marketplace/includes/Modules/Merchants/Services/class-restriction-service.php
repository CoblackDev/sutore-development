<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\RestrictionsRepository;

final class RestrictionService
{
    public function __construct(
        private readonly RestrictionsRepository $restrictions = new RestrictionsRepository(),
        private readonly MerchantProfileChangeLogger $logger = new MerchantProfileChangeLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{message: string, id: int}|\WP_Error
     */
    public function create(array $params, int $actorUserId): array|\WP_Error
    {
        $merchantId = (int) ($params['merchant_id'] ?? 0);
        $key = sanitize_key((string) ($params['restriction_key'] ?? ''));
        $reason = sanitize_textarea_field((string) ($params['reason'] ?? ''));

        if ($merchantId <= 0 || $key === '' || !in_array($key, RestrictionsRepository::KEYS, true)) {
            return new \WP_Error(
                'sutore_restriction_invalid',
                __('Invalid restriction.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $expiresAt = null;
        if (!empty($params['expires_at'])) {
            $raw = str_replace('T', ' ', sanitize_text_field((string) $params['expires_at']));
            $raw = trim($raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
                $raw .= ':00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
                $expiresAt = $raw;
            }
        }

        $id = $this->restrictions->create([
            'merchant_id' => $merchantId,
            'restriction_key' => $key,
            'is_active' => 1,
            'reason' => $reason,
            'created_by' => $actorUserId,
            'expires_at' => $expiresAt,
        ]);

        $this->logger->logRestrictionCreated($merchantId, $id, $key, $reason, [
            'actor_role' => 'staff',
            'expires_at' => $expiresAt,
        ]);

        return [
            'message' => __('Restriction added.', 'sutore-marketplace'),
            'id' => $id,
        ];
    }

    /**
     * @return array{message: string}|\WP_Error
     */
    public function deactivate(int $id): array|\WP_Error
    {
        $row = $this->restrictions->find($id);
        if ($row === null) {
            return new \WP_Error(
                'sutore_restriction_not_found',
                __('Restriction not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $this->restrictions->deactivate($id);
        $this->logger->logRestrictionDeactivated(
            (int) $row->merchant_id,
            $id,
            (string) $row->restriction_key,
            ['actor_role' => 'staff']
        );

        return ['message' => __('Deactivated.', 'sutore-marketplace')];
    }
}
