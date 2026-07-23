<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/** Uncropped / proportional product image for small UI previews. */
final class ProductThumbnail
{
    public static function url(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        $attachmentId = 0;
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if ($product instanceof \WC_Product) {
            $attachmentId = (int) $product->get_image_id();
        }
        if ($attachmentId <= 0) {
            $attachmentId = (int) get_post_thumbnail_id($productId);
        }
        if ($attachmentId <= 0) {
            return '';
        }

        $src = wp_get_attachment_image_url($attachmentId, 'woocommerce_thumbnail');
        if (is_string($src) && $src !== '') {
            return $src;
        }

        $fallback = wp_get_attachment_image_url($attachmentId, 'medium');

        return is_string($fallback) ? $fallback : '';
    }
}
