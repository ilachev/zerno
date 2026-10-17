<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Runtime\App;
use App\Platform\Runtime\RoadRunnerRuntime;
use Spiral\RoadRunner\Http\PSR7Worker;

$configPath = getenv('APP_CONFIG') ?: __DIR__ . '/../config/container.php';
$app = new App($configPath);
$runtime = new RoadRunnerRuntime($app->getContainer()->get(PSR7Worker::class), $app);
$runtime->run();
