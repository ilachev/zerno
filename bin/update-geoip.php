#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Capabilities\Session\Application\GeoLocationConfig;
use App\Capabilities\Session\Infrastructure\GeoLocation\UpdateGeoIPCommand;
use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use App\Platform\Logging\Logger;

/** @var callable(Container<object>): void $containerConfig */
$containerConfig = require __DIR__ . '/../config/container.php';

$container = new DIContainer();
$containerConfig($container);

$config = $container->get(GeoLocationConfig::class);
$logger = $container->get(Logger::class);
$command = new UpdateGeoIPCommand($config, $logger);

// Execute the update command.
$command->execute();

echo "Обновление базы данных геолокации завершено.\n";
