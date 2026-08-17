<?php

declare(strict_types=1);

/**
 * Sutore Marketplace automated tests.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';

use SutoreMarketplace\Tests\Support\Harness;

$filter = isset($argv[1]) ? (string) $argv[1] : '';

$suites = array_merge(
    glob(__DIR__ . '/Unit/*.php') ?: [],
    glob(__DIR__ . '/Integration/*.php') ?: []
);
sort($suites);

foreach ($suites as $file) {
    require_once $file;
}

$declared = get_declared_classes();
$testClasses = [];
foreach ($declared as $class) {
    if (str_starts_with($class, 'SutoreMarketplace\\Tests\\Unit\\')
        || str_starts_with($class, 'SutoreMarketplace\\Tests\\Integration\\')
    ) {
        $testClasses[] = $class;
    }
}
sort($testClasses);

foreach ($testClasses as $class) {
    $short = substr($class, strrpos($class, '\\') + 1);
    if ($filter !== '' && !str_contains($class, $filter) && !str_contains($short, $filter)) {
        continue;
    }
    Harness::$currentSuite = $short;
    $instance = new $class();
    $methods = get_class_methods($instance);
    sort($methods);
    foreach ($methods as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', substr($method, 4)) ?? $method;
        Harness::run(trim($label), static function () use ($instance, $method): void {
            $instance->{$method}();
        });
    }
}

$pass = 0;
$fail = 0;
$skip = 0;
foreach (Harness::$results as $row) {
    match ($row['status']) {
        'PASS' => $pass++,
        'SKIP' => $skip++,
        default => $fail++,
    };
}

$widths = ['suite' => 22, 'name' => 52, 'status' => 6, 'ms' => 7];
foreach (Harness::$results as $row) {
    $widths['suite'] = max($widths['suite'], strlen($row['suite']));
    $widths['name'] = max($widths['name'], strlen($row['name']));
}

$line = function (string $suite, string $name, string $status, string $ms) use ($widths): string {
    return sprintf(
        '| %-' . $widths['suite'] . 's | %-' . $widths['name'] . 's | %-' . $widths['status'] . 's | %' . $widths['ms'] . 's |',
        $suite,
        $name,
        $status,
        $ms
    );
};

$rule = '+-' . str_repeat('-', $widths['suite']) . '-+-' . str_repeat('-', $widths['name'])
    . '-+-' . str_repeat('-', $widths['status']) . '-+-' . str_repeat('-', $widths['ms']) . '-+';

echo PHP_EOL . $rule . PHP_EOL;
echo $line('Suite', 'Test', 'Result', 'ms') . PHP_EOL;
echo $rule . PHP_EOL;
foreach (Harness::$results as $row) {
    echo $line($row['suite'], $row['name'], $row['status'], (string) $row['ms']) . PHP_EOL;
    if ($row['detail'] !== '') {
        echo '  → ' . $row['detail'] . PHP_EOL;
    }
}
echo $rule . PHP_EOL;
echo sprintf('PASS %d  FAIL %d  SKIP %d  TOTAL %d', $pass, $fail, $skip, $pass + $fail + $skip) . PHP_EOL;

exit($fail > 0 ? 1 : 0);
