<?php

declare(strict_types=1);

use App\Platform\Logging\Logger;
use App\Platform\Runtime\App;
use App\Platform\Storage\Storage;

return static function (Logger $logger): App {
    /** @var Closure(Logger, Storage, string): App $compose */
    $compose = require __DIR__ . '/application.php';
    /** @var Storage $storage */
    $storage = require __DIR__ . '/../../config/storage.php';

    return $compose($logger, $storage, getenv('CATALOG_FILES_DIR') ?: __DIR__ . '/../../var/document-catalog/files');
};
