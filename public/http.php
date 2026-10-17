<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Runtime\App;
use App\Platform\Runtime\SapiRequestFactory;
use App\Platform\Runtime\SapiResponseEmitter;
use Nyholm\Psr7\Response;

try {
    $request = (new SapiRequestFactory())->fromGlobals();
} catch (InvalidArgumentException) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    (new SapiResponseEmitter())->emit(
        new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Bad Request'),
        is_string($method) ? $method : 'GET',
    );

    return;
}

$configPath = getenv('APP_CONFIG') ?: __DIR__ . '/../config/core.php';
$app = new App($configPath);
(new SapiResponseEmitter())->emit($app->handle($request), $request->getMethod());
