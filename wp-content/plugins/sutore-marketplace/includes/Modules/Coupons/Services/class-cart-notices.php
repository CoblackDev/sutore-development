<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Services;

use SutoreMarketplace\Modules\Coupons\Domain\BrandCampaignConfig;
use SutoreMarketplace\Modules\Coupons\Settings\CouponSettings;

final class CartNotices
{
    public function maybeRender(): void
    {
        if (!function_exists('is_cart') || !is_cart()) {
            return;
        }

        foreach ($this->buildNoticeHtmlList() as $html) {
            wc_add_notice($html, 'notice');
        }
    }

    /**
     * Markup for Cart Block (and other non-classic surfaces).
     */
    public function renderMarkup(): string
    {
        $parts = $this->buildNoticeHtmlList();
        if ($parts === []) {
            return '';
        }

        return '<div class="sutore-mp-campaign-notices" role="region" aria-label="'
            . esc_attr__('Brand campaign offers', 'sutore-marketplace')
            . '">'
            . implode('', $parts)
            . '</div>';
    }

    /** @return list<string> */
    private function buildNoticeHtmlList(): array
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return [];
        }

        if ($this->hasAppliedBrandCampaignCoupon()) {
            return [];
        }

        $candidates = [];
        foreach (BrandCampaignRegistry::all() as $config) {
            $count = $this->brandQuantityInCart($config->brandTermIds);
            if ($count <= 0) {
                continue;
            }

            $candidates[] = [
                'config' => $config,
                'count' => $count,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        $limit = CouponSettings::cartNoticeLimit();
        $shown = 0;
        $htmlList = [];

        foreach ($candidates as $notice) {
            if ($shown >= $limit) {
                break;
            }

            $html = $this->buildNoticeHtml($notice['config'], (int) $notice['count']);
            if ($html === '') {
                continue;
            }

            $htmlList[] = $html;
            $shown++;
        }

        return $htmlList;
    }

    private function hasAppliedBrandCampaignCoupon(): bool
    {
        foreach (WC()->cart->get_applied_coupons() as $code) {
            if (BrandCampaignRegistry::findByCode((string) $code) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $brandTermIds */
    private function brandQuantityInCart(array $brandTermIds): int
    {
        $count = 0;
        foreach (WC()->cart->get_cart() as $cartItem) {
            $productId = (int) ($cartItem['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $terms = wp_get_post_terms($productId, 'product_brand', ['fields' => 'ids']);
            if (is_wp_error($terms) || array_intersect($brandTermIds, array_map('intval', $terms)) === []) {
                continue;
            }

            $count += (int) ($cartItem['quantity'] ?? 0);
        }

        return $count;
    }

    private function buildNoticeHtml(BrandCampaignConfig $config, int $currentCount): string
    {
        $term = get_term($config->primaryBrandTermId(), 'product_brand');
        if (!$term instanceof \WP_Term) {
            return '';
        }

        $brandName = $term->name;
        $brandUrl = get_term_link($term);
        if (is_wp_error($brandUrl)) {
            $brandUrl = '';
        }

        $borderColor = esc_attr($config->noticeColor);
        $required = $config->minBrandQty;

        if ($currentCount >= $required) {
            return sprintf(
                '<div class="sutore-mp-campaign-notice sutore-mp-campaign-notice--success" style="border-left-color:%1$s;">'
                . '<div class="sutore-mp-campaign-notice__text">'
                . '<p>%2$s! %3$s <strong>%4$s</strong> %5$s</p>'
                . '<span>%6$s: <strong>%7$s</strong></span>'
                . '</div></div>',
                $borderColor,
                esc_html__('Congratulations', 'sutore-marketplace'),
                esc_html__('You earned this discount:', 'sutore-marketplace'),
                esc_html($brandName . ' %' . number_format_i18n($config->discountPercent, 0)),
                esc_html__('discount.', 'sutore-marketplace'),
                esc_html__('Coupon code at checkout', 'sutore-marketplace'),
                esc_html($config->code)
            );
        }

        $needed = $required - $currentCount;
        $progress = (int) round(($currentCount / max(1, $required)) * 100);

        return sprintf(
            '<div class="sutore-mp-campaign-notice sutore-mp-campaign-notice--progress" style="border-left-color:%1$s;">'
            . '<div class="sutore-mp-campaign-notice__text">'
            . '<p>%2$s <a href="%3$s">%4$s</a> %5$s</p>'
            . '<span>%6$s</span>'
            . '</div>'
            . '<div class="sutore-mp-campaign-notice__bar"><div class="sutore-mp-campaign-notice__bar-fill" style="width:%7$d%%;background-color:%1$s;"></div></div>'
            . '</div>',
            $borderColor,
            esc_html__('For discount only', 'sutore-marketplace'),
            esc_url((string) $brandUrl),
            esc_html(sprintf(
                /* translators: 1: item count, 2: brand name */
                _n('%1$d × %2$s product', '%1$d × %2$s product', $needed, 'sutore-marketplace'),
                $needed,
                $brandName
            )),
            esc_html__('add.', 'sutore-marketplace'),
            esc_html(sprintf(
                /* translators: 1: current count, 2: required count */
                __('%1$d / %2$d products added', 'sutore-marketplace'),
                $currentCount,
                $required
            )),
            $progress
        );
    }
}
