<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Hooks;

use SutoreMarketplace\Modules\Coupons\Module;
use SutoreMarketplace\Modules\Coupons\Services\CartNotices;

final class CartNoticeHooks
{
    private bool $blocksMarkupRendered = false;

    public function register(): void
    {
        // Classic shortcode / cart.php template.
        add_action('woocommerce_before_cart', [$this, 'renderClassicNotices'], 5);

        // Cart Block: classic hook never fires; inject into filled cart markup.
        add_filter('render_block_woocommerce/filled-cart-block', [$this, 'prependBlocksCartNotices'], 10, 2);
    }

    public function renderClassicNotices(): void
    {
        (new CartNotices())->maybeRender();
    }

    public function prependBlocksCartNotices(string $content, array $block): string
    {
        unset($block);

        if ($this->blocksMarkupRendered) {
            return $content;
        }

        $markup = (new CartNotices())->renderMarkup();
        if ($markup === '') {
            return $content;
        }

        $this->blocksMarkupRendered = true;
        Module::enqueueCouponStyle();

        return $markup . $content;
    }
}
