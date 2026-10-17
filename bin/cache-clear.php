#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Cache\CacheService;
use App\Platform\Console\CacheClearCommand;
use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use App\Platform\Logging\Logger;

/** @var callable(Container<object>): void $containerConfig */
$containerConfig = require __DIR__ . '/../config/container.php';

$container = new DIContainer();
$containerConfig($container);

$cacheService = $container->get(CacheService::class);
$logger = $container->get(Logger::class);
$command = new CacheClearCommand($cacheService, $logger);

// Execute the command and use its result as the script exit status.
$success = $command->clear();

// Return a non-zero status when cache clearing fails.
exit($success ? 0 : 1);
