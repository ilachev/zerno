<?php

declare(strict_types=1);

use Project\Bootstrap\Autoloader;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../tools/dev-bootstrap.php';

Autoloader::register(['Tests\\' => [__DIR__]]);
