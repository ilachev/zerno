<?php

declare(strict_types=1);

use Project\Bootstrap\Autoloader;

if (PHP_VERSION_ID < 80500) {
    throw new RuntimeException('PHP 8.5 or newer is required.');
}
if (!extension_loaded('pdo')) {
    throw new RuntimeException('The PDO extension is required.');
}

require_once __DIR__ . '/bootstrap/Autoloader.php';

/** @var array{prefixes: array<string, list<string>>, classmap: array<string, string>, files: list<string>} $libraries */
$libraries = require __DIR__ . '/third_party/runtime/autoload.php';
Autoloader::register(
    [
        'App\Generated\\' => [__DIR__ . '/gen/Generated'],
        'App\\' => [__DIR__ . '/src', __DIR__ . '/protos/gen/App'],
        'Google\Api\\' => [__DIR__ . '/protos/gen/Google/Api'],
        'GPBMetadata\Google\Api\\' => [__DIR__ . '/protos/gen/GPBMetadata/Google/Api'],
        ...$libraries['prefixes'],
    ],
    $libraries['classmap'],
    $libraries['files'],
);
unset($libraries);
