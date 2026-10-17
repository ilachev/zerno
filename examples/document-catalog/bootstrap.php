<?php

declare(strict_types=1);

use Project\Bootstrap\Autoloader;

require_once __DIR__ . '/../../bootstrap.php';

Autoloader::register([
    'Examples\DocumentCatalog\Migrations\\' => [__DIR__ . '/migrations'],
    'Examples\DocumentCatalog\\' => [__DIR__ . '/src'],
]);
