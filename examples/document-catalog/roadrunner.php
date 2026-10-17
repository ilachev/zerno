<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\RoadRunnerServiceProvider;

/** @var Closure(Container<object>): void $catalog */
$catalog = require __DIR__ . '/config.php';

return static function (Container $container) use ($catalog): void {
    $catalog($container);
    (new RoadRunnerServiceProvider(chunkSize: 8192))->register($container);
};
