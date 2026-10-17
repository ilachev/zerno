<?php

declare(strict_types=1);

use App\Capabilities\Session\Domain\SessionConfig;

return new SessionConfig(
    cookieName: 'session',
    cookieTtl: 86400,
    sessionTtl: PHP_INT_MAX,
);
