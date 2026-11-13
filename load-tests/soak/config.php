<?php

declare(strict_types=1);

use App\Platform\Logging\Logger;
use App\Platform\Runtime\App;
use Zerno\Soak\Handler;

require_once __DIR__ . '/Handler.php';

return static function (Logger $logger): App {
    /** @var Closure(Logger): App $baseline */
    $baseline = require __DIR__ . '/../baseline/config.php';
    /** @var Closure(Logger): App $wire */
    $wire = require __DIR__ . '/../../tools/http-fixtures/config.php';

    return new App(new Handler($baseline($logger), $wire($logger)));
};
