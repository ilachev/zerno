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
if (!in_array($action, ['migrate', 'rollback'], true)) {
    throw new InvalidArgumentException('Invalid action. Use migrate or rollback');
}
$directory = $argv[2] ?? __DIR__ . '/../src/Migrations';
if (!is_dir($directory)) {
    throw new InvalidArgumentException('Migration directory does not exist: ' . $directory);
}
/** @var Storage $storage */
$storage = require __DIR__ . '/../config/storage.php';
$command = new MigrateCommand(new MigrationService(
    $storage,
    new MigrationRepository($storage),
    new MigrationLoader($directory, new ReadableOutputLogger()),
));

match ($action) {
    'migrate' => $command->migrate(),
    'rollback' => $command->rollback(),
};
