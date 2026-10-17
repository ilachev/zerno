<?php

declare(strict_types=1);

use Project\Bootstrap\Autoloader;

require_once dirname(__DIR__, 2) . '/bootstrap/Autoloader.php';

(static function (): void {
    /** @var array{prefixes: array<string, list<string>>, classmap: array<string, string>, files: list<string>} $autoload */
    $autoload = require dirname(__DIR__, 2) . '/third_party/codegen/sql/autoload.php';
    Autoloader::register($autoload['prefixes'], $autoload['classmap'], $autoload['files']);
    Autoloader::register([
        'SqlGen\\' => [__DIR__ . '/src'],
        'Tests\\' => [__DIR__ . '/tests'],
    ]);
})();
