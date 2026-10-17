<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

/** @var callable(Container<object>): void $configure */
$configure = require __DIR__ . '/application.php';

return static function (Container $container) use ($configure): void {
    $configure($container);
    $container->set(Worker::class, static function (): Worker {
        throw new LogicException('The application must not create a RoadRunner worker.');
    });
    $container->set(PSR7Worker::class, static function (): PSR7Worker {
        throw new LogicException('The application must not create a PSR-7 worker.');
    });
};
