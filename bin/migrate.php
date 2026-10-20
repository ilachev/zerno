#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Console\MigrateCommand;
use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use App\Platform\Storage\Migration\MigrationService;

$configPath = $argv[2] ?? __DIR__ . '/../config/core.php';
/** @var callable(Container<object>): void $containerConfig */
$containerConfig = require $configPath;

$container = new DIContainer();
$containerConfig($container);

$service = $container->get(MigrationService::class);
$command = new MigrateCommand($service);

$action = $argv[1] ?? 'migrate';

match ($action) {
    'migrate' => $command->migrate(),
    'rollback' => $command->rollback(),
    default => throw new InvalidArgumentException('Invalid action. Use migrate or rollback'),
};
