#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Console\MigrateCommand;
use App\Platform\Logging\ReadableOutputLogger;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\Storage;

$action = $argv[1] ?? 'migrate';
if (!in_array($action, ['migrate', 'rollback', 'upgrade-metadata'], true)) {
    throw new InvalidArgumentException('Invalid action. Use migrate, rollback or upgrade-metadata');
}
if ($action === 'upgrade-metadata' && count($argv ?? []) > 2) {
    throw new InvalidArgumentException('upgrade-metadata does not accept a migration directory');
}
$directory = $argv[2] ?? __DIR__ . '/../src/Migrations';
if ($action !== 'upgrade-metadata' && !is_dir($directory)) {
    throw new InvalidArgumentException('Migration directory does not exist: ' . $directory);
}
/** @var Storage $storage */
$storage = require __DIR__ . '/../config/storage.php';
$repository = new MigrationRepository($storage);
if ($action === 'upgrade-metadata') {
    $repository->upgradeMetadata();

    return;
}
$command = new MigrateCommand(new MigrationService(
    $storage,
    $repository,
    new MigrationLoader($directory, new ReadableOutputLogger()),
));

match ($action) {
    'migrate' => $command->migrate(),
    'rollback' => $command->rollback(),
};
