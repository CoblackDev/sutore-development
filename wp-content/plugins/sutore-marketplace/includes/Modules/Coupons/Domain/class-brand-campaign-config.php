<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Domain;

final class BrandCampaignConfig
{
    /** @param list<int> $brandTermIds */
    public function __construct(
        public readonly int $couponId,
        public readonly string $code,
        public readonly float $discountPercent,
        public readonly array $brandTermIds,
        public readonly int $minBrandQty,
        public readonly int $noticePriority,
        public readonly string $noticeColor,
    ) {
    }

    public function primaryBrandTermId(): int
    {
        return $this->brandTermIds[0] ?? 0;
    }
}
