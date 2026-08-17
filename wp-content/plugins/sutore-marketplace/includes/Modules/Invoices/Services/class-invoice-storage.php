<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

final class InvoiceStorage
{
    public function directory(): string|\WP_Error
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new \WP_Error('sutore_invoice_upload', (string) $uploads['error']);
        }

        $dir = trailingslashit((string) $uploads['basedir']) . 'sutore-marketplace-invoices';
        if (!wp_mkdir_p($dir)) {
            return new \WP_Error(
                'sutore_invoice_mkdir',
                __('Could not create the invoice storage folder.', 'sutore-marketplace')
            );
        }

        $this->protect($dir);

        return $dir;
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

        return $path !== '' && is_readable($path) && is_file($path);
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

        $index = $dir . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
