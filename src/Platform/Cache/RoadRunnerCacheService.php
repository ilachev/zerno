<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use App\Platform\Logging\Logger;
use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\KeyValue\Factory;
use Spiral\RoadRunner\KeyValue\StorageInterface;

final class RoadRunnerCacheService implements CacheService
{
    private const string DEFAULT_NAMESPACE_VERSION = '1';

    private StorageInterface $storage;

    private bool $available = false;

    private bool $degraded = false;

    /**
     * Flag indicating that cache clear operation is in progress.
     */
    private bool $clearInProgress = false;

    public function __construct(
        private readonly CacheConfig $config,
        private readonly Logger $logger,
    ) {
        try {
            if ($this->isTestingEnvironment()) {
                $this->activateFallbackStorage('test environment');

                return;
            }

            $address = $this->config->address !== '' ? $this->config->address : 'tcp://127.0.0.1:6001';
            $rpc = RPC::create($address);
            $factory = new Factory($rpc);
            $engine = $this->config->engine !== '' ? $this->config->engine : 'local-memory';

            try {
                $storage = $factory->select($engine);
                $storage->has('cache_test_key');

                $this->storage = $storage;
                $this->available = true;
                $this->logger->info('KV storage is available');
            } catch (\Throwable $e) {
                $this->activateFallbackStorage('KV storage is not available', $e);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize cache service: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->activateFallbackStorage('cache service initialization failed', $e);
        }
    }

    private function isTestingEnvironment(): bool
    {
        return \defined('__PHPUNIT_PHAR__')
            || isset($_SERVER['ENVIRONMENT']) && $_SERVER['ENVIRONMENT'] === 'testing';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function set(CacheKey|string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->available) {
            return false;
        }

        $prefixedKey = $this->prefixKey($key);
        $ttl ??= $this->config->defaultTtl;

        try {
            $this->storage->set($prefixedKey, $value, $ttl);

            return true;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache set error', ['key' => $prefixedKey]);

            return false;
        }
    }

    public function get(CacheKey|string $key, mixed $default = null): mixed
    {
        if (!$this->available) {
            return $default;
        }

        $prefixedKey = $this->prefixKey($key);

        try {
            $value = $this->storage->get($prefixedKey);

            return $value ?? $default;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache get error', ['key' => $prefixedKey]);

            return $default;
        }
    }

    public function has(CacheKey|string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        $prefixedKey = $this->prefixKey($key);

        try {
            return $this->storage->has($prefixedKey);
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache has error', ['key' => $prefixedKey]);

            return false;
        }
    }

    public function delete(CacheKey|string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        $prefixedKey = $this->prefixKey($key);

        try {
            return $this->storage->delete($prefixedKey);
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache delete error', ['key' => $prefixedKey]);

            return false;
        }
    }

    public function clear(): bool
    {
        if (!$this->available || $this->clearInProgress) {
            $this->logger->debug('Cache clear skipped', [
                'reason' => !$this->available ? 'cache unavailable' : 'already in progress',
            ]);

            return false;
        }

        $this->clearInProgress = true;

        try {
            if ($this->storage instanceof FallbackStorage) {
                $this->storage->clear();
            }

            $namespaceVersion = $this->generateNamespaceVersion();
            $this->storage->set($this->namespaceVersionStorageKey(), $namespaceVersion);

            $this->logger->info('Cache namespace invalidated successfully', [
                'degraded' => $this->degraded,
                'namespaceVersion' => $namespaceVersion,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache clear error');

            return false;
        } finally {
            $this->clearInProgress = false;
        }
    }

    public function invalidateScope(CacheScope|string $scope): bool
    {
        if (!$this->available) {
            return false;
        }

        $scopeVersionKey = $this->scopeVersionStorageKey($scope);

        try {
            if ($this->storage instanceof FallbackStorage) {
                $this->storage->clear();
            }

            $scopeVersion = $this->generateNamespaceVersion();
            $this->storage->set($scopeVersionKey, $scopeVersion);

            $this->logger->info('Cache scope invalidated successfully', [
                'scope' => $this->normalizeScopeName($scope),
                'scopeVersion' => $scopeVersion,
                'degraded' => $this->degraded,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache scope invalidation error', [
                'scope' => $this->normalizeScopeName($scope),
            ]);

            return false;
        }
    }

    public function getOrSet(CacheKey|string $key, callable $callback, ?int $ttl = null): mixed
    {
        if (!$this->available) {
            return $callback();
        }

        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    private function prefixKey(CacheKey|string $key): string
    {
        if ($key instanceof CacheKey) {
            return $this->prefixScopedKey($key);
        }

        return $this->namespacePrefix() . $key;
    }

    private function prefixScopedKey(CacheKey $key): string
    {
        return $this->namespacePrefix()
            . $key->scope->name
            . ':'
            . $this->resolveScopeVersion($key->scope)
            . ':'
            . $key->identifier;
    }

    private function namespacePrefix(): string
    {
        return $this->namespaceBasePrefix() . $this->resolveNamespaceVersion() . ':';
    }

    private function namespaceBasePrefix(): string
    {
        $namespaceSeed = $this->config->namespaceSeed !== '' ? $this->config->namespaceSeed . ':' : '';

        return $this->config->defaultPrefix . $namespaceSeed;
    }

    private function namespaceVersionStorageKey(): string
    {
        return $this->namespaceBasePrefix() . '__namespace_version';
    }

    private function scopeVersionStorageKey(CacheScope|string $scope): string
    {
        return $this->namespacePrefix() . '__scope_version:' . $this->normalizeScopeName($scope);
    }

    private function resolveNamespaceVersion(): string
    {
        $storageKey = $this->namespaceVersionStorageKey();

        try {
            if ($this->storage->has($storageKey)) {
                $version = $this->storage->get($storageKey);

                if (\is_string($version) && $version !== '') {
                    return $version;
                }

                if (\is_int($version) || \is_float($version)) {
                    return (string) $version;
                }
            }

            $this->storage->set($storageKey, self::DEFAULT_NAMESPACE_VERSION);

            return self::DEFAULT_NAMESPACE_VERSION;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache namespace resolution error', [
                'key' => $storageKey,
            ]);

            return self::DEFAULT_NAMESPACE_VERSION;
        }
    }

    private function resolveScopeVersion(CacheScope|string $scope): string
    {
        $storageKey = $this->scopeVersionStorageKey($scope);

        try {
            if ($this->storage->has($storageKey)) {
                $version = $this->storage->get($storageKey);

                if (\is_string($version) && $version !== '') {
                    return $version;
                }

                if (\is_int($version) || \is_float($version)) {
                    return (string) $version;
                }
            }

            $this->storage->set($storageKey, self::DEFAULT_NAMESPACE_VERSION);

            return self::DEFAULT_NAMESPACE_VERSION;
        } catch (\Throwable $e) {
            $this->switchToFallback($e, 'Cache scope resolution error', [
                'scope' => $this->normalizeScopeName($scope),
                'key' => $storageKey,
            ]);

            return self::DEFAULT_NAMESPACE_VERSION;
        }
    }

    private function normalizeScopeName(CacheScope|string $scope): string
    {
        return $scope instanceof CacheScope ? $scope->name : $scope;
    }

    private function generateNamespaceVersion(): string
    {
        return (string) hrtime(true);
    }

    private function activateFallbackStorage(string $reason, ?\Throwable $exception = null): void
    {
        $this->storage = new FallbackStorage($this->config->fallbackMaxEntries);
        $this->available = true;
        $this->degraded = true;

        $context = ['reason' => $reason];
        if ($exception !== null) {
            $context['error'] = $exception->getMessage();
            $context['exception'] = $exception;
        }

        $this->logger->warning('Cache switched to in-memory fallback', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function switchToFallback(\Throwable $exception, string $message, array $context = []): void
    {
        $this->logger->error($message . ': ' . $exception->getMessage(), $context + [
            'exception' => $exception,
            'degraded' => $this->degraded,
        ]);

        if ($this->degraded) {
            $this->available = false;

            return;
        }

        $this->activateFallbackStorage($message, $exception);
    }
}
