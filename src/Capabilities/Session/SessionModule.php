<?php

declare(strict_types=1);

namespace App\Capabilities\Session;

use App\Capabilities\Capability;
use App\Capabilities\Session\Application\ClientConfig;
use App\Capabilities\Session\Application\ClientDetector;
use App\Capabilities\Session\Application\GeoLocationConfig;
use App\Capabilities\Session\Application\GeoLocationService;
use App\Capabilities\Session\Application\SessionPayloadFactory;
use App\Capabilities\Session\Domain\SessionConfig;
use App\Capabilities\Session\Domain\SessionRepository;
use App\Capabilities\Session\Domain\SessionService;
use App\Capabilities\Session\Infrastructure\DefaultSessionPayloadFactory;
use App\Capabilities\Session\Infrastructure\FingerprintClientDetector;
use App\Capabilities\Session\Infrastructure\GeoLocation\IP2LocationGeoLocationService;
use App\Capabilities\Session\Infrastructure\Persistence\SqlSessionRepository;
use App\Capabilities\Session\Transport\Http\SessionMiddleware;
use App\Platform\Cache\ScopedCacheFactory;
use App\Platform\Config\ProjectPath;
use App\Platform\DI\Container;
use App\Platform\Hydration\JsonFieldAdapter;
use App\Platform\Logging\Logger;

/**
 * @implements Capability<object>
 */
final readonly class SessionModule implements Capability
{
    public function register(Container $container): void
    {
        $container->set(
            SessionConfig::class,
            static function (): SessionConfig {
                /** @var SessionConfig $config */
                $config = require ProjectPath::getConfigPath('session.php');

                return $config;
            },
        );

        $container->set(
            ClientConfig::class,
            static function (): ClientConfig {
                /** @var ClientConfig $config */
                $config = require ProjectPath::getConfigPath('client.php');

                return $config;
            },
        );

        $container->set(
            GeoLocationConfig::class,
            static function (): GeoLocationConfig {
                /** @var GeoLocationConfig $config */
                $config = require ProjectPath::getConfigPath('geolocation.php');

                return $config;
            },
        );

        $container->bind(SqlSessionRepository::class, SqlSessionRepository::class);
        $container->bind(ScopedCacheFactory::class, ScopedCacheFactory::class);

        $container->bind(SessionRepository::class, SqlSessionRepository::class);

        $container->set(
            SessionService::class,
            static function (Container $container): SessionService {
                /** @var SessionRepository $repository */
                $repository = $container->get(SessionRepository::class);

                /** @var Logger $logger */
                $logger = $container->get(Logger::class);

                return new SessionService($repository, $logger);
            },
        );

        $container->bind(GeoLocationService::class, IP2LocationGeoLocationService::class);
        $container->set(
            IP2LocationGeoLocationService::class,
            static function (Container $container): IP2LocationGeoLocationService {
                /** @var GeoLocationConfig $config */
                $config = $container->get(GeoLocationConfig::class);

                /** @var ScopedCacheFactory $scopedCacheFactory */
                $scopedCacheFactory = $container->get(ScopedCacheFactory::class);

                /** @var Logger $logger */
                $logger = $container->get(Logger::class);

                return new IP2LocationGeoLocationService($config, $scopedCacheFactory, $logger);
            },
        );

        $container->bind(SessionPayloadFactory::class, DefaultSessionPayloadFactory::class);
        $container->set(
            DefaultSessionPayloadFactory::class,
            static function (Container $container): DefaultSessionPayloadFactory {
                /** @var GeoLocationService $geoLocationService */
                $geoLocationService = $container->get(GeoLocationService::class);

                return new DefaultSessionPayloadFactory($geoLocationService);
            },
        );

        $container->set(
            ClientDetector::class,
            static function (Container $container): ClientDetector {
                /** @var SessionRepository $sessionRepository */
                $sessionRepository = $container->get(SessionRepository::class);

                /** @var ClientConfig $clientConfig */
                $clientConfig = $container->get(ClientConfig::class);

                return new FingerprintClientDetector($sessionRepository, $clientConfig);
            },
        );

        $container->set(
            SessionMiddleware::class,
            static function (Container $container): SessionMiddleware {
                /** @var SessionService $sessionService */
                $sessionService = $container->get(SessionService::class);

                /** @var Logger $logger */
                $logger = $container->get(Logger::class);

                /** @var SessionConfig $config */
                $config = $container->get(SessionConfig::class);

                /** @var SessionPayloadFactory $sessionPayloadFactory */
                $sessionPayloadFactory = $container->get(SessionPayloadFactory::class);

                /** @var JsonFieldAdapter $jsonAdapter */
                $jsonAdapter = $container->get(JsonFieldAdapter::class);

                return new SessionMiddleware(
                    $sessionService,
                    $logger,
                    $config,
                    $sessionPayloadFactory,
                    $jsonAdapter,
                );
            },
        );
    }
}
