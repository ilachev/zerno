<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\RoadRunnerServiceProvider;

/** @var callable(Container<object>): void $configure */
$configure = require __DIR__ . '/core.php';

return static function (Container $container) use ($configure): void {
    $configure($container);
    new RoadRunnerServiceProvider()->register($container);
};
