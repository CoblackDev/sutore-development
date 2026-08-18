<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

/**
 * Prevents WC/WP admin from trashing or deleting a variation that is still
 * the marketplace listing primary key, unless the delete is initiated by the plugin.
 */
final class VariationLifecycleGuard
{
    private static bool $allowPluginDelete = false;

    public function register(): void
    {
        add_action('wp_trash_post', [$this, 'blockTrash'], 1);
        add_action('before_delete_post', [$this, 'blockDelete'], 1);
    }

    public static function allowPluginDelete(callable $callback): mixed
    {
        self::$allowPluginDelete = true;
        try {
            return $callback();
        } finally {
            self::$allowPluginDelete = false;
        }
    }

    public function blockTrash(int $postId): void
    {
        $this->abortIfProtectedVariation($postId, true);
    }

    public function blockDelete(int $postId): void
    {
        $this->abortIfProtectedVariation($postId, false);
    }

    private function abortIfProtectedVariation(int $postId, bool $trash): void
    {
        if (self::$allowPluginDelete || $postId <= 0) {
            return;
        }

        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product_variation') {
            return;
        }

        if (!(new ListingRepository())->find($postId)) {
            return;
        }

        $message = $trash
            ? __('This variation is a Sutore Marketplace product and cannot be trashed from WordPress. Remove the product in the marketplace UI first.', 'sutore-marketplace')
            : __('This variation is a Sutore Marketplace product and cannot be deleted from WordPress. Remove the product in the marketplace UI first.', 'sutore-marketplace');

        wp_die(
            esc_html($message),
            esc_html__('Marketplace product protected', 'sutore-marketplace'),
            ['response' => 403]
        );
    }
}
