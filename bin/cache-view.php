#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Platform\Cache\CacheConfig;
use App\Platform\Cache\CacheService;
use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\KeyValue\Factory;
use Spiral\RoadRunner\KeyValue\StorageInterface;

// Read the optional cache key passed from the command line.
$specificKey = $argv[1] ?? null;

// Bootstrap the application container.
/** @var callable(Container<object>): void $containerConfig */
$containerConfig = require __DIR__ . '/../config/container.php';

$container = new DIContainer();
$containerConfig($container);

// Resolve the cache dependencies used by the inspection tool.
/** @var CacheService $cacheService */
$cacheService = $container->get(CacheService::class);

/** @var CacheConfig $config */
$config = $container->get(CacheConfig::class);

// Abort early when the KV backend is unavailable.
if (!$cacheService->isAvailable()) {
    echo "\033[31mКеш-сервис недоступен\033[0m\n";
    exit(1);
}

// Connect to RoadRunner KV directly to inspect raw entries.
try {
    $address = $config->address !== '' ? $config->address : 'tcp://127.0.0.1:6001';
    $rpc = RPC::create($address);
    $factory = new Factory($rpc);
    if ($config->engine === '') {
        echo "\033[31mНе задано имя движка кеша\033[0m\n";
        exit(1);
    }

    /** @var non-empty-string $engine */
    $engine = $config->engine;

    /** @var StorageInterface $storage */
    $storage = $factory->select($engine);

    // Print a short summary of the connected cache backend.
    echo "Информация о кеш-сервисе:\n";
    echo '- Название движка: ' . $storage->getName() . "\n";
    echo "- Доступность: \033[32mДоступен\033[0m\n";
    echo '- Адрес: ' . $address . "\n";
    echo '- Префикс ключей: ' . $config->defaultPrefix . "\n";
    echo '- TTL по умолчанию: ' . $config->defaultTtl . " сек.\n\n";

    // Inspect one specific key when it was passed explicitly.
    if ($specificKey !== null) {
        echo "Поиск ключа: \033[36m{$specificKey}\033[0m\n";

        // Add the default prefix when the caller passed a logical key.
        $fullKey = $specificKey;
        if (strpos($specificKey, $config->defaultPrefix) !== 0) {
            $fullKey = $config->defaultPrefix . $specificKey;
            echo "Используем полный ключ с префиксом: \033[36m{$fullKey}\033[0m\n";
        }

        if ($cacheService->has($specificKey)) {
            $value = $cacheService->get($specificKey);
            outputKeyValue($specificKey, $value, $storage);
        } elseif ($cacheService->has($fullKey)) {
            $value = $cacheService->get($fullKey);
            outputKeyValue($fullKey, $value, $storage);
        } else {
            echo "\033[33mКлюч не найден в кеше\033[0m\n";
        }

        exit(0);
    }

    // Otherwise probe the cache using known prefixes and log-derived keys.
    echo "Поиск всех ключей в кеше...\n";

    // RoadRunner KV does not expose a "list all keys" operation.
    $prefixes = ['session:', 'geo:', 'cache:', $config->defaultPrefix];

    $found = false;
    $foundCount = 0;

    foreach ($prefixes as $prefix) {
        // Probe a batch of plausible keys for each known prefix.
        $testKeys = [];
        for ($i = 0; $i < 1000; ++$i) {
            $testKey = $prefix . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $testKeys[] = $testKey;

            // Query small batches to keep the probing overhead bounded.
            if (count($testKeys) >= 50) {
                $results = $storage->getMultiple($testKeys, null);
                foreach ($results as $key => $value) {
                    if ($value !== null) {
                        $found = true;
                        ++$foundCount;
                        outputKeyValue($key, $value, $storage);
                    }
                }
                $testKeys = [];
            }
        }
    }

    // Probe keys previously mentioned in the application logs.
    $keys = findKeysInLogs($config->defaultPrefix);
    foreach ($keys as $key) {
        if (!$cacheService->has($key)) {
            continue;
        }

        $value = $cacheService->get($key);
        if ($value !== null) {
            $found = true;
            ++$foundCount;
            outputKeyValue($key, $value, $storage);
        }
    }

    if (!$found) {
        echo "\033[33mНе найдено ни одного ключа в кеше\033[0m\n";
        echo "Примечание: RoadRunner KV не предоставляет метод для получения списка всех ключей,\n";
        echo "поэтому скрипт проверяет только наиболее вероятные ключи.\n";
    } else {
        echo "\nНайдено ключей: \033[32m{$foundCount}\033[0m\n";
    }

    echo "\nДля просмотра конкретного ключа выполните команду:\n";
    echo "php bin/cache-view.php название_ключа\n";

} catch (Throwable $e) {
    echo "\033[31mОшибка при обращении к кеш-сервису: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}

/**
 * Prints the cache key, value and expiration metadata.
 */
function outputKeyValue(string $key, mixed $value, StorageInterface $storage): void
{
    if ($key === '') {
        echo "----------------------------------------\n";
        echo "Ключ пропущен: пустое имя\n\n";

        return;
    }

    echo "----------------------------------------\n";
    echo "Ключ: \033[32m{$key}\033[0m\n";

    echo 'Тип: ' . gettype($value) . "\n";

    echo 'Значение: ';
    if (is_string($value)) {
        // Pretty-print JSON payloads when possible.
        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "JSON данные\n";
            echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } elseif (strlen($value) > 100) {
            // Trim very long strings to keep the output readable.
            echo substr($value, 0, 100) . "...\n";
            echo 'Размер: ' . strlen($value) . " байт\n";
        } else {
            echo $value . "\n";
        }
    } elseif (is_array($value)) {
        echo "\n" . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo var_export($value, true) . "\n";
    }

    // Print expiration metadata when the backend provides it.
    try {
        $ttl = $storage->getTtl($key);
        if ($ttl instanceof DateTimeInterface) {
            $now = new DateTimeImmutable();
            $diffSeconds = $ttl->getTimestamp() - $now->getTimestamp();

            echo 'Истекает: ' . $ttl->format('Y-m-d H:i:s');
            echo ' (через ' . formatTimeRemaining($diffSeconds) . ")\n";
        } else {
            echo "TTL: бессрочно\n";
        }
    } catch (Throwable $e) {
        echo 'TTL: ошибка при получении времени жизни - ' . $e->getMessage() . "\n";
    }

    echo "\n";
}

/**
 * Formats the remaining lifetime into a readable string.
 */
function formatTimeRemaining(int $seconds): string
{
    if ($seconds < 0) {
        return 'истёк';
    }

    if ($seconds < 60) {
        return "{$seconds} сек.";
    }

    if ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes} мин. {$remainingSeconds} сек.";
    }

    $hours = floor($seconds / 3600);
    $remainingSeconds = $seconds % 3600;
    $minutes = floor($remainingSeconds / 60);
    $remainingSeconds %= 60;

    return "{$hours} ч. {$minutes} мин. {$remainingSeconds} сек.";
}

/**
 * Finds cache keys mentioned in log files.
 *
 * @return array<string>
 */
function findKeysInLogs(string $prefix): array
{
    $keys = [];
    $logFiles = glob(__DIR__ . '/../var/log/*.log');
    if ($logFiles === false) {
        return [];
    }

    foreach ($logFiles as $logFile) {
        if (file_exists($logFile) && is_readable($logFile)) {
            $content = file_get_contents($logFile);
            if ($content === false) {
                continue;
            }

            preg_match_all('/"key":"([^"]+)"/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $key) {
                    // Keep only keys that belong to the configured cache prefix.
                    if (strpos($key, $prefix) === 0) {
                        $keys[$key] = true;
                    }
                }
            }
        }
    }

    return array_keys($keys);
}
