<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Merchants\Domain\BehaviorSummary;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Shared\Database\Schema;

final class BehaviorScoreService
{
    public function __construct(
        private readonly MerchantProfileRepository $profiles = new MerchantProfileRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
    ) {
    }

    /**
     * @return array{
     *   score: float,
     *   summary_key: string,
     *   event_count: int,
     *   negative_events: int,
     *   delivered_count: int
     * }
     */
    public function computeForMerchant(int $merchantId): array
    {
        $windowDays = BehaviorSettings::scoreWindowDays();
        $since = wp_date('Y-m-d H:i:s', time() - ($windowDays * DAY_IN_SECONDS));
        $reversed = array_flip($this->events->reversedEventIds($merchantId, $since));
        $rows = $this->events->scorableForMerchant($merchantId, $since);

        $askingRef = BehaviorSettings::askingReference();
        $totalDelta = 0.0;
        $negativeEvents = 0;
        $dominantType = '';
        $dominantImpact = 0.0;

        foreach ($rows as $row) {
            $eventId = (int) $row->id;
            if (isset($reversed[$eventId])) {
                continue;
            }

            $type = (string) $row->event_type;
            $weight = BehaviorSettings::eventWeight($type);
            if ($weight === 0.0) {
                continue;
            }

            $asking = ListingEventsRepository::payloadAsking($row);
            $factor = $asking > 0 ? max(0.5, min(2.0, $asking / $askingRef)) : 1.0;
            $impact = $weight * $factor;
            $totalDelta += $impact;

            if ($weight < 0) {
                ++$negativeEvents;
            }

            if ($weight < 0 && abs($impact) > abs($dominantImpact)) {
                $dominantImpact = $impact;
                $dominantType = $type;
            }
        }

        $score = round(max(1.0, min(5.0, 5.0 + $totalDelta)), 2);
        $deliveredCount = $this->deliveredCount($merchantId);

        if ($rows === [] && $deliveredCount === 0) {
            return [
                'score' => 5.0,
                'summary_key' => BehaviorSummary::NO_SALES,
                'event_count' => 0,
                'negative_events' => 0,
                'delivered_count' => 0,
            ];
        }

        $summaryKey = BehaviorSummary::ALL_ON_TIME;
        if ($negativeEvents > 0 && $dominantType !== '') {
            $summaryKey = BehaviorSummary::fromEventType($dominantType);
        } elseif ($totalDelta > 0.05) {
            $summaryKey = BehaviorSummary::STRONG;
        }

        return [
            'score' => $score,
            'summary_key' => $summaryKey,
            'event_count' => count($rows),
            'negative_events' => $negativeEvents,
            'delivered_count' => $deliveredCount,
        ];
    }

    public function refreshMerchant(int $merchantId): array
    {
        $computed = $this->computeForMerchant($merchantId);
        $now = current_time('mysql');

        global $wpdb;
        $wpdb->update(
            Schema::table('merchant_profiles'),
            [
                'behavior_score' => $computed['score'],
                'behavior_summary_key' => $computed['summary_key'],
                'score_computed_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id' => $merchantId]
        );

        return $computed;
    }

    public function scoreForMerchant(int $merchantId): float
    {
        if ($this->isScoreHidden($merchantId)) {
            return 5.0;
        }

        $row = $this->profiles->find($merchantId);
        if ($row !== null && isset($row['behavior_score']) && $row['behavior_score'] !== '') {
            return (float) $row['behavior_score'];
        }

        return 5.0;
    }

    public function sanctionsActive(int $merchantId): bool
    {
        // Per-merchant only: new-seller protection or shadow window. No global kill-switch.
        return !$this->isScoreHidden($merchantId);
    }

    public function isScoreHidden(int $merchantId): bool
    {
        return $this->isNewSellerProtected($merchantId) || $this->isInShadowMode($merchantId);
    }

    public function isInShadowMode(int $merchantId): bool
    {
        if (!BehaviorSettings::shadowModeEnabled()) {
            return false;
        }

        $weeks = BehaviorSettings::shadowModeWeeks();
        if ($weeks <= 0) {
            return false;
        }

        $profile = $this->profiles->find($merchantId);
        $createdAt = (string) ($profile['created_at'] ?? '');
        if ($createdAt === '') {
            return true;
        }

        $tz = wp_timezone();
        $created = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt, $tz);
        if (!$created) {
            return true;
        }

        $until = $created->modify('+' . $weeks . ' weeks');

        return $until > new \DateTimeImmutable('now', $tz);
    }

    public function isNewSellerProtected(int $merchantId): bool
    {
        $minDeliveries = BehaviorSettings::newSellerProtectionDeliveries();
        $minDays = BehaviorSettings::newSellerProtectionDays();
        if ($minDeliveries <= 0 && $minDays <= 0) {
            return false;
        }

        $delivered = $this->deliveredCount($merchantId);
        if ($minDeliveries > 0 && $delivered >= $minDeliveries) {
            return false;
        }

        $profile = $this->profiles->find($merchantId);
        $createdAt = (string) ($profile['created_at'] ?? '');
        if ($minDays > 0 && $createdAt !== '') {
            $tz = wp_timezone();
            $created = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt, $tz);
            if ($created) {
                $eligibleFrom = $created->modify('+' . $minDays . ' days');
                if ($eligibleFrom > new \DateTimeImmutable('now', $tz)) {
                    return true;
                }
            }
        }

        return $minDeliveries > 0 && $delivered < $minDeliveries;
    }

    /** @return array<string, mixed> */
    public function snapshotForMerchant(int $merchantId): array
    {
        $computed = $this->computeForMerchant($merchantId);
        $hidden = $this->isScoreHidden($merchantId);
        $shadow = $this->isInShadowMode($merchantId);
        $protected = $this->isNewSellerProtected($merchantId);

        if ($hidden) {
            return [
                'score' => null,
                'score_max' => 5.0,
                'summary_key' => $protected ? BehaviorSummary::PROTECTED : BehaviorSummary::SHADOW,
                'summary' => $protected
                    ? __('Your score will appear after your first deliveries.', 'sutore-marketplace')
                    : __('Your score is being calibrated and is not shown yet.', 'sutore-marketplace'),
                'computed_at' => '',
                'window_days' => BehaviorSettings::scoreWindowDays(),
                'hidden' => true,
                'shadow_mode' => $shadow,
                'protected' => $protected,
            ];
        }

        $row = $this->profiles->find($merchantId);

        return [
            'score' => $this->scoreForMerchant($merchantId),
            'score_max' => 5.0,
            'summary_key' => (string) ($row['behavior_summary_key'] ?? $computed['summary_key']),
            'summary' => BehaviorSummary::sentence(
                (string) ($row['behavior_summary_key'] ?? $computed['summary_key']),
                $computed['negative_events']
            ),
            'computed_at' => (string) ($row['score_computed_at'] ?? ''),
            'window_days' => BehaviorSettings::scoreWindowDays(),
            'hidden' => false,
            'shadow_mode' => false,
            'protected' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function digestForMerchant(int $merchantId, int $limit = 30): array
    {
        $since = wp_date('Y-m-d H:i:s', time() - (BehaviorSettings::scoreWindowDays() * DAY_IN_SECONDS));
        $reversed = array_flip($this->events->reversedEventIds($merchantId, $since));
        $rows = $this->events->scorableForMerchant($merchantId, $since);
        $out = [];

        foreach (array_reverse($rows) as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $eventId = (int) $row->id;
            if (isset($reversed[$eventId])) {
                continue;
            }

            $type = (string) $row->event_type;
            $out[] = [
                'id' => $eventId,
                'event_type' => $type,
                'label' => ListingEventType::behaviorDigestLabel($type),
                'asking' => ListingEventsRepository::payloadAsking($row),
                'created_at' => (string) ($row->created_at ?? ''),
                'reversed' => false,
            ];
        }

        return $out;
    }

    public function reverseEvent(int $eventId, string $note = ''): bool
    {
        $id = $this->events->logReversal($eventId, $note);

        return $id > 0;
    }

    public function deliveredCount(int $merchantId): int
    {
        global $wpdb;
        $listings = Schema::table('listings');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$listings}
             WHERE merchant_id = %d
               AND listing_status = %s",
            $merchantId,
            ListingStatus::DELIVERED_TO_CUSTOMER
        ));
    }
}
