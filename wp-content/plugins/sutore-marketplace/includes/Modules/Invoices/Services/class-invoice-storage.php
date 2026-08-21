<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

/**
 * Private PDF storage outside the public document root when possible.
 * Prefer dirname(ABSPATH)/sutore-private/invoices; allow SUTORE_MARKETPLACE_PRIVATE_DIR override.
 * Legacy uploads and WP_CONTENT paths remain readable for existing files.
 */
final class InvoiceStorage
{
    public function directory(): string|\WP_Error
    {
        foreach ($this->candidateDirectories() as $dir) {
            if (wp_mkdir_p($dir)) {
                $this->protect($dir);

                return $dir;
            }
        }

        return new \WP_Error(
            'sutore_invoice_mkdir',
            __('Could not create the invoice storage folder.', 'sutore-marketplace')
        );
    }

    public function pathFor(int $invoiceId): string|\WP_Error
    {
        $dir = $this->directory();
        if ($dir instanceof \WP_Error) {
            return $dir;
        }

        return $dir . '/invoice-' . $invoiceId . '-' . wp_generate_password(12, false) . '.pdf';
    }

    public function isReadable(?string $path): bool
    {
        $path = (string) $path;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'pdf') {
            return false;
        }

        foreach ($this->allowedRoots() as $root) {
            $resolved = realpath($root);
            if ($resolved === false) {
                continue;
            }
            $prefix = $resolved . DIRECTORY_SEPARATOR;
            if (str_starts_with($real, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function preferredDirectory(): string
    {
        $candidates = $this->candidateDirectories();

        return $candidates[0];
    }

    /** @return list<string> */
    private function candidateDirectories(): array
    {
        $dirs = [];
        if (defined('SUTORE_MARKETPLACE_PRIVATE_DIR') && is_string(SUTORE_MARKETPLACE_PRIVATE_DIR) && SUTORE_MARKETPLACE_PRIVATE_DIR !== '') {
            $dirs[] = trailingslashit(SUTORE_MARKETPLACE_PRIVATE_DIR) . 'invoices';
        }
        if (defined('ABSPATH')) {
            $dirs[] = trailingslashit(dirname((string) ABSPATH)) . 'sutore-private/invoices';
        }
        if (defined('WP_CONTENT_DIR')) {
            $dirs[] = trailingslashit((string) WP_CONTENT_DIR) . 'sutore-private/invoices';
        }

        return array_values(array_unique($dirs));
    }

    /** @return list<string> */
    private function allowedRoots(): array
    {
        $roots = [$this->preferredDirectory()];

        if (defined('WP_CONTENT_DIR')) {
            $roots[] = trailingslashit((string) WP_CONTENT_DIR) . 'sutore-private/invoices';
        }

        $uploads = wp_upload_dir();
        if (empty($uploads['error'])) {
            $roots[] = trailingslashit((string) $uploads['basedir']) . 'sutore-marketplace-invoices';
        }

        return array_values(array_unique($roots));
    }

    private function protect(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }

        $parent = dirname($dir);
        $parentHt = $parent . '/.htaccess';
        if (!is_file($parentHt)) {
            file_put_contents(
                $parentHt,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }

        $nginx = $parent . '/.nginx-deny';
        if (!is_file($nginx)) {
            file_put_contents(
                $nginx,
                "# Deny HTTP access to this directory (outside document root preferred).\n"
                . "# Example nginx:\n"
                . "# location ^~ /sutore-private/ { deny all; return 404; }\n"
                . "# location ^~ /wp-content/sutore-private/ { deny all; return 404; }\n"
            );
        }

        $index = $dir . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $parentIndex = $parent . '/index.php';
        if (!is_file($parentIndex)) {
            file_put_contents($parentIndex, "<?php\n// Silence is golden.\n");
        }
    }
}
