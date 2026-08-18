<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

/**
 * Make local WordPress usable behind Cloudflare Quick Tunnel / named tunnel
 * without permanently changing siteurl in the database.
 *
 * Docker/local installs often define WP_CONTENT_URL / WP_PLUGIN_URL as
 * http://localhost:8080/... — those constants bypass option_siteurl filters and
 * become https://localhost:8080 under tunnel TLS, which browsers reject.
 */
final class CloudflareTunnelHooks
{
    public function register(): void
    {
        if (!$this->isAllowedEnvironment() || !$this->isTunnelRequest()) {
            return;
        }

        // Cloudflare terminates TLS; WP must treat the request as HTTPS.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';

        add_filter('option_home', [$this, 'publicUrl'], 1);
        add_filter('option_siteurl', [$this, 'publicUrl'], 1);
        add_filter('content_url', [$this, 'rewriteLocalUrl'], 100);
        add_filter('plugins_url', [$this, 'rewriteLocalUrl'], 100);
        add_filter('script_loader_src', [$this, 'rewriteLocalUrl'], 100);
        add_filter('style_loader_src', [$this, 'rewriteLocalUrl'], 100);
        add_filter('theme_root_uri', [$this, 'rewriteLocalUrl'], 100);
        add_filter('stylesheet_directory_uri', [$this, 'rewriteLocalUrl'], 100);
        add_filter('template_directory_uri', [$this, 'rewriteLocalUrl'], 100);
        add_filter('wp_get_attachment_url', [$this, 'rewriteLocalUrl'], 100);
        add_filter('rest_url', [$this, 'rewriteLocalUrl'], 100);
    }

    public function publicUrl(mixed $url): string
    {
        $public = $this->requestPublicBase();

        return $public !== '' ? $public : (string) $url;
    }

    public function rewriteLocalUrl(mixed $url): string
    {
        $url = (string) $url;
        $public = $this->requestPublicBase();
        if ($public === '' || $url === '') {
            return $url;
        }

        foreach ($this->localOrigins() as $local) {
            if (str_starts_with($url, $local)) {
                return $public . substr($url, strlen($local));
            }
        }

        return $url;
    }

    private function isAllowedEnvironment(): bool
    {
        if (defined('SUTORE_CLOUDFLARE_TUNNEL') && SUTORE_CLOUDFLARE_TUNNEL) {
            return true;
        }

        if (function_exists('wp_get_environment_type')) {
            return in_array(wp_get_environment_type(), ['local', 'development'], true);
        }

        return false;
    }

    private function isTunnelRequest(): bool
    {
        $host = $this->requestHost();
        if ($host === '') {
            return false;
        }

        return str_ends_with($host, '.trycloudflare.com')
            || str_ends_with($host, '.cfargotunnel.com');
    }

    private function requestPublicBase(): string
    {
        $host = $this->requestHost();
        if ($host === '') {
            return '';
        }

        return 'https://' . $host;
    }

    private function requestHost(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
        ];

        foreach ($candidates as $raw) {
            $host = strtolower(trim(explode(',', $raw)[0]));
            $host = preg_replace('/:\d+$/', '', $host) ?: '';
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return $host;
            }
        }

        return '';
    }

    /** @return list<string> */
    private function localOrigins(): array
    {
        return [
            'https://localhost:8080',
            'http://localhost:8080',
            'https://127.0.0.1:8080',
            'http://127.0.0.1:8080',
            'https://localhost',
            'http://localhost',
            'https://127.0.0.1',
            'http://127.0.0.1',
        ];
    }
}
