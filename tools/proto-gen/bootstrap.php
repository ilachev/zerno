<?php

declare(strict_types=1);

use Project\Bootstrap\Autoloader;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

Autoloader::register(['Project\ProtoGen\\' => [__DIR__]]);
