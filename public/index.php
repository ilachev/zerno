<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Platform\Runtime\App;
use App\Platform\Runtime\RoadRunnerRuntime;
use Spiral\RoadRunner\Http\PSR7Worker;

$app = new App(__DIR__ . '/../config/container.php');
$runtime = new RoadRunnerRuntime($app->getContainer()->get(PSR7Worker::class), $app);
$runtime->run();
