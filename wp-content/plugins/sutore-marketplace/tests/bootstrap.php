<?php

declare(strict_types=1);

/**
 * Load WordPress inside Docker so marketplace tests hit the real plugin.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = '/var/www/html';
if (!is_file($root . '/wp-load.php')) {
    $root = dirname(__DIR__, 4);
}
if (!is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "wp-load.php not found.\n");
    exit(1);
}

require $root . '/wp-load.php';

if (!defined('SUTORE_MARKETPLACE_PATH')) {
    fwrite(STDERR, "sutore-marketplace is not loaded.\n");
    exit(1);
}

require_once __DIR__ . '/Support/class-failed.php';
require_once __DIR__ . '/Support/class-skipped.php';
require_once __DIR__ . '/Support/class-harness.php';
require_once __DIR__ . '/Support/class-fixtures.php';
