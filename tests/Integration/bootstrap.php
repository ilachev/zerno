<?php

declare(strict_types=1);

use App\Platform\Console\MigrateCommand;
use App\Platform\Runtime\App;
use App\Platform\Storage\Migration\MigrationService;
use Tests\Integration\PostgreSqlTestDatabase;
use Tests\Integration\TestAppFactory;

require_once __DIR__ . '/../bootstrap.php';

try {
    $database = PostgreSqlTestDatabase::fromEnvironment();
    $database->create();
    register_shutdown_function(static function () use ($database): void {
        $database->cleanup();
    });

    foreach ($database->applicationEnvironment() as $name => $value) {
        putenv($name . '=' . $value);
    }

    echo "Integration database: {$database->database}; isolated schema: {$database->schema}\n";

    $app = new App(__DIR__ . '/../../config/container.php');
    $container = $app->getContainer();
    $migrationService = $container->get(MigrationService::class);
    $command = new MigrateCommand($migrationService);
    $command->migrate();
    TestAppFactory::setApp($app);

    $varDir = __DIR__ . '/../../var';
    if (!is_dir($varDir)) {
        mkdir($varDir, 0o755, true);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error in integration bootstrap: ' . $exception->getMessage() . "\n");
    exit(1);
}
