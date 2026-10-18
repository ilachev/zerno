<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\RoadRunnerServiceProvider;

/** @var callable(Container<object>): void $configure */
$configure = require __DIR__ . '/config.php';

return static function (Container $container) use ($configure): void {
    $configure($container);
    new RoadRunnerServiceProvider(chunkSize: 8192)->register($container);
};
