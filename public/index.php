<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Logging\Logger;
use App\Platform\Logging\ReadableOutputLogger;
use App\Platform\Logging\RoadRunnerLogger;
use App\Platform\Runtime\App;
use App\Platform\Runtime\RoadRunnerRuntime;
use Spiral\RoadRunner\Worker;

$configPath = getenv('APP_CONFIG') ?: __DIR__ . '/../config/core.php';
/** @var Closure(Logger): App $compose */
$compose = require $configPath;
$app = $compose(new RoadRunnerLogger(new ReadableOutputLogger()));
$chunkSize = filter_var(getenv('RR_HTTP_CHUNK_SIZE') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($chunkSize === false) {
    throw new InvalidArgumentException('RR_HTTP_CHUNK_SIZE must be a nonnegative integer.');
}
$worker = RoadRunnerRuntime::createWorker(Worker::create(), $chunkSize);
(new RoadRunnerRuntime($worker, $app))->run();
