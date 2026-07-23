<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

use SutoreMarketplace\Shared\Domain\MerchantLevels;

/**
 * Campaign audience rules (AND across groups).
 *
 * @phpstan-type TargetingArray array{
 *   merchant_levels?: list<string>,
 *   asking_min?: float|null,
 *   asking_max?: float|null,
 *   category_ids?: list<int>,
 *   brand_ids?: list<int>,
 *   product_ids?: list<int>
 * }
 */
final class CampaignTargeting
{
    /**
     * @param list<string> $merchantLevels
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     * @param list<int> $productIds
     */
    public function __construct(
        public readonly array $merchantLevels = [],
        public readonly ?float $askingMin = null,
        public readonly ?float $askingMax = null,
        public readonly array $categoryIds = [],
        public readonly array $brandIds = [],
        public readonly array $productIds = [],
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $levels = [];
        foreach ((array) ($raw['merchant_levels'] ?? []) as $level) {
            $key = sanitize_key((string) $level);
            if (in_array($key, [MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
                $levels[] = $key;
            }
        }

        $askingMin = isset($raw['asking_min']) && $raw['asking_min'] !== '' && $raw['asking_min'] !== null
            ? max(0.0, (float) $raw['asking_min'])
            : null;
        $askingMax = isset($raw['asking_max']) && $raw['asking_max'] !== '' && $raw['asking_max'] !== null
            ? max(0.0, (float) $raw['asking_max'])
            : null;

        return new self(
            merchantLevels: array_values(array_unique($levels)),
            askingMin: $askingMin,
            askingMax: $askingMax,
            categoryIds: self::intList($raw['category_ids'] ?? []),
            brandIds: self::intList($raw['brand_ids'] ?? []),
            productIds: self::intList($raw['product_ids'] ?? []),
        );
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self();
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::fromArray($decoded) : new self();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'merchant_levels' => $this->merchantLevels,
            'asking_min' => $this->askingMin,
            'asking_max' => $this->askingMax,
            'category_ids' => $this->categoryIds,
            'brand_ids' => $this->brandIds,
            'product_ids' => $this->productIds,
        ];
    }

    public function toJson(): string
    {
        return (string) wp_json_encode($this->toArray());
    }

    /** @param mixed $raw @return list<int> */
    private static function intList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
