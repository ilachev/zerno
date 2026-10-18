#!/usr/bin/env php
<?php

declare(strict_types=1);

use Project\HttpFixtures\AttachmentFiles;

require_once __DIR__ . '/http-fixtures/AttachmentFiles.php';

final readonly class HttpWireResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(public int $status, public array $headers, public string $body) {}
}

function wireSame(mixed $expected, mixed $actual, string $context): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($context . ': expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

function wireExecutable(string $name): string
{
    $candidates = str_contains($name, '/') ? [$name] : array_map(
        static fn(string $directory): string => $directory . '/' . $name,
        explode(PATH_SEPARATOR, getenv('PATH') ?: ''),
    );
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Required platform executable is unavailable: ' . $name);
}

function wirePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate a loopback port: ' . $message);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false) {
        throw new RuntimeException('Cannot read the loopback address');
    }

    return (int) substr($address, strrpos($address, ':') + 1);
}

/** @return resource */
function wireConnect(int $port)
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, 1);
    if ($socket === false) {
        throw new RuntimeException('Cannot connect to the fixture: ' . $message);
    }

    return $socket;
}

/** @param resource $socket */
function wireTimeout($socket, float $deadline): void
{
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        throw new RuntimeException('Fixture response exceeded the 3 second deadline');
    }
    stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
}

/** @param resource $socket */
function wireRead($socket, int $length, float $deadline): string
{
    $result = '';

    while (strlen($result) < $length) {
        wireTimeout($socket, $deadline);
        $chunk = fread($socket, max(1, $length - strlen($result)));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete fixture response or socket timeout');
        }
        $result .= $chunk;
    }

    return $result;
}

/** @param resource $socket */
function wireWrite($socket, string $data, float $deadline): void
{
    while ($data !== '') {
        wireTimeout($socket, $deadline);
        $written = fwrite($socket, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Cannot write the fixture request');
        }
        $data = substr($data, $written);
    }
}

function wireResponse(string $raw, bool $fastcgi, string $method): HttpWireResponse
{
    $parts = explode("\r\n\r\n", $raw, 2);
    if (count($parts) !== 2) {
        throw new RuntimeException('Fixture response has no complete header block');
    }
    [$head, $body] = $parts;
    $status = 200;
    $headers = [];
    foreach (explode("\r\n", $head) as $index => $line) {
        if (!$fastcgi && $index === 0) {
            if (preg_match('/\AHTTP\/1\.[01] ([0-9]{3})(?: |\z)/', $line, $match) !== 1) {
                throw new RuntimeException('Invalid HTTP status line: ' . $line);
            }
            $status = (int) $match[1];

            continue;
        }
        $separator = strpos($line, ':');
        if ($separator === false) {
            throw new RuntimeException('Invalid response header: ' . $line);
        }
        $name = strtolower(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($fastcgi && $name === 'status') {
            $status = (int) $value;
        } else {
            $headers[$name][] = $value;
        }
    }
    if ($method !== 'HEAD' && $status !== 204 && $status !== 304 && in_array('chunked', $headers['transfer-encoding'] ?? [], true)) {
        $decoded = '';

        while (true) {
            $separator = strpos($body, "\r\n");
            if ($separator === false || preg_match('/\A[0-9a-f]+\z/i', substr($body, 0, $separator)) !== 1) {
                throw new RuntimeException('Invalid fixture chunk framing');
            }
            $size = (int) hexdec(substr($body, 0, $separator));
            $body = substr($body, $separator + 2);
            if ($size === 0) {
                wireSame("\r\n", $body, 'Fixture chunk terminator');
                break;
            }
            if (strlen($body) < $size + 2 || substr($body, $size, 2) !== "\r\n") {
                throw new RuntimeException('Incomplete fixture chunk');
            }
            $decoded .= substr($body, 0, $size);
            $body = substr($body, $size + 2);
        }
        $body = $decoded;
    }

    return new HttpWireResponse($status, $headers, $body);
}

/** @param array<string, string> $headers */
function wireHttp(int $port, string $method, string $target, array $headers): HttpWireResponse
{
    $socket = wireConnect($port);
    $deadline = microtime(true) + 3;

    try {
        $request = $method . ' ' . $target . " HTTP/1.1\r\nHost: fixture.test\r\nConnection: close\r\nContent-Length: 0\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        wireWrite($socket, $request . "\r\n", $deadline);
        $raw = '';

        while (!feof($socket)) {
            wireTimeout($socket, $deadline);
            $chunk = fread($socket, 8192);
            if ($chunk === false || ($chunk === '' && !feof($socket))) {
                throw new RuntimeException('Incomplete HTTP response or socket timeout');
            }
            $raw .= $chunk;
            if (strlen($raw) > 33_554_432) {
                throw new RuntimeException('Fixture response exceeded the bounded 32 MiB client limit');
            }
        }

        return wireResponse($raw, false, $method);
    } finally {
        fclose($socket);
    }
}

function wireFcgiRecord(int $type, string $data): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($data), 0, 0) . $data;
}

/** @param int<0, max> $length */
function wireFcgiLength(int $length): string
{
    return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
}

/** @param array<string, string> $headers */
function wireFastcgi(int $port, string $root, string $method, string $target, array $headers): HttpWireResponse
{
    $socket = wireConnect($port);
    $deadline = microtime(true) + 3;
    $parameters = [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_SOFTWARE' => 'zerno-wire-check',
        'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_NAME' => 'fixture.test', 'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $method, 'REQUEST_URI' => $target,
        'QUERY_STRING' => explode('?', $target, 2)[1] ?? '', 'SCRIPT_NAME' => '/http.php', 'HTTP_HOST' => 'fixture.test',
        'SCRIPT_FILENAME' => $root . '/public/http.php', 'DOCUMENT_ROOT' => $root . '/public', 'CONTENT_LENGTH' => '0',
    ];
    foreach ($headers as $name => $value) {
        $parameters['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $encoded = '';
    foreach ($parameters as $name => $value) {
        $encoded .= wireFcgiLength(strlen($name)) . wireFcgiLength(strlen($value)) . $name . $value;
    }

    try {
        wireWrite($socket, wireFcgiRecord(1, pack('nC', 1, 0) . str_repeat("\0", 5))
            . wireFcgiRecord(4, $encoded) . wireFcgiRecord(4, '') . wireFcgiRecord(5, ''), $deadline);
        $output = '';
        $error = '';

        while (true) {
            $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', wireRead($socket, 8, $deadline));
            if ($header === false || $header['version'] !== 1 || $header['request'] !== 1) {
                throw new RuntimeException('Invalid FastCGI response header');
            }
            $length = $header['length'] ?? null;
            $padding = $header['padding'] ?? null;
            if (!is_int($length) || !is_int($padding)) {
                throw new RuntimeException('Invalid FastCGI content length');
            }
            $data = wireRead($socket, $length, $deadline);
            wireRead($socket, $padding, $deadline);
            if ($header['type'] === 6) {
                $output .= $data;
            } elseif ($header['type'] === 7) {
                $error .= $data;
            } elseif ($header['type'] === 3) {
                wireSame(8, strlen($data), 'FastCGI END_REQUEST length');
                wireSame(pack('NC', 0, 0), substr($data, 0, 5), 'FastCGI application/protocol status');
                wireSame('', $error, 'FastCGI stderr');

                return wireResponse($output, true, $method);
            }
            if (strlen($output) + strlen($error) > 33_554_432) {
                throw new RuntimeException('FastCGI response exceeded the bounded 32 MiB client limit');
            }
        }
    } finally {
        fclose($socket);
    }
}

/** @param array<string, string> $headers */
function wireRequest(string $runtime, int $port, string $root, string $method, string $target, array $headers): HttpWireResponse
{
    return $runtime === 'fpm'
        ? wireFastcgi($port, $root, $method, $target, $headers)
        : wireHttp($port, $method, $target, $headers);
}

function wireMatrix(string $runtime, int $port, string $root, string $errorFormat): int
{
    /** @var list<array{string, string, int, array<string, string>|null, list<string>}> $cases */
    $cases = [
        ['GET', '/__wire/static', 200, [], []],
        ['GET', '/__wire/static?tag=a%20b&count=2', 200, [], []],
        ['HEAD', '/__wire/static', 200, null, []],
        ['GET', '/__wire/static///', 200, [], []],
        ['POST', '/__wire/static/', 405, null, ['GET', 'HEAD']],
        ['GET', '/__wire/items/current', 200, [], []],
        ['GET', '/__wire/items/42', 200, ['id' => '42'], []],
        ['DELETE', '/__wire/items/current', 200, ['id' => 'current'], []],
        ['POST', '/__wire/items/42', 405, null, ['GET', 'DELETE']],
        ['HEAD', '/__wire/items/42', 405, null, ['GET', 'DELETE']],
        ['GET', '/__wire/items/42/', 404, null, []],
        ['POST', '/__wire/items/42/', 404, null, []],
        ['GET', '/__wire/files/report.json', 200, ['name' => 'report'], []],
        ['GET', '/__wire/files/reportXjson', 404, null, []],
        ['GET', '/__wire/a+b/42', 200, ['id' => '42'], []],
        ['GET', '/__wire/aab/42', 404, null, []],
        ['GET', '/__wire/missing', 404, null, []],
        ['HEAD', '/__wire/missing', 404, null, []],
        ['GET', '/__wire/failure', 500, null, []],
        ['HEAD', '/__wire/failure', 500, null, []],
        ['GET', '/__wire/status/204', 204, null, []],
        ['GET', '/__wire/status/304', 304, null, []],
        ['GET', '/__wire/headers', 200, [], []],
    ];
    $pid = null;
    foreach ($cases as $index => [$method, $target, $status, $parameters, $allow]) {
        $context = $method . ' ' . $target;
        $requestId = 'wire-' . $index;
        $response = wireRequest($runtime, $port, $root, $method, $target, ['X-Request-ID' => $requestId]);
        wireSame($status, $response->status, $context . ' status');
        wireSame([$requestId], $response->headers['x-request-id'] ?? [], $context . ' request ID');
        $actualAllow = array_map(trim(...), explode(',', implode(',', $response->headers['allow'] ?? [])));
        $actualAllow = $actualAllow === [''] ? [] : $actualAllow;
        sort($allow);
        sort($actualAllow);
        wireSame($allow, $actualAllow, $context . ' Allow');
        if ($status >= 400) {
            wireSame([$errorFormat === 'html' ? 'text/html; charset=utf-8' : 'application/json'], $response->headers['content-type'] ?? [], $context . ' error representation');
            wireSame([], $response->headers['set-cookie'] ?? [], $context . ' no session');
        }
        if ($method === 'HEAD' || $status === 204 || $status === 304) {
            wireSame('', $response->body, $context . ' forbidden body');
        } elseif ($status >= 400) {
            if ($errorFormat === 'html') {
                wireSame(['no-store'], $response->headers['cache-control'] ?? [], $context . ' error cache policy');
                wireSame(true, str_contains($response->body, '<html lang="ru">'), $context . ' HTML document');
                wireSame(true, str_contains($response->body, '<h1>'), $context . ' HTML title');
                wireSame(false, str_contains($response->body, 'wire-private-exception-details'), $context . ' private details');
            } else {
                $message = match ($status) {
                    404 => 'Not found',
                    405 => 'Method not allowed',
                    default => 'Internal Server Error',
                };
                wireSame(['error' => $message], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR), $context . ' error');
            }
        } else {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException($context . ': expected a JSON object');
            }
            wireSame($parameters, $payload['params'] ?? null, $context . ' parameters');
            wireSame($requestId, $payload['requestId'] ?? null, $context . ' context ID');
            wireSame(explode('?', $target, 2)[0], $payload['path'] ?? null, $context . ' URI path');
            if (str_contains($target, '?')) {
                wireSame('tag=a%20b&count=2', $payload['query'] ?? null, $context . ' URI query');
                wireSame(['tag' => 'a b', 'count' => '2'], $payload['queryParams'] ?? null, $context . ' parsed query');
            }
        }
        if ($status < 400) {
            wireSame([$method], $response->headers['x-fixture-method'] ?? [], $context . ' handler method');
            $currentPid = $response->headers['x-fixture-pid'] ?? [];
            if (count($currentPid) !== 1 || $currentPid[0] === '') {
                throw new RuntimeException($context . ': missing fixture worker PID');
            }
            $pid ??= $currentPid;
            wireSame($pid, $currentPid, $context . ' single worker reuse');
        }
        if ($target === '/__wire/headers') {
            wireSame(['first', 'second'], $response->headers['x-fixture-multi'] ?? [], 'Repeated header');
            wireSame(['first=one; Path=/; HttpOnly', 'second=two; Path=/; HttpOnly'], $response->headers['set-cookie'] ?? [], 'Repeated Set-Cookie');
        }
    }
    $previousId = '';
    foreach (['first', 'second', '', 'third'] as $index => $id) {
        $provided = $index < 2 ? 'sequence-' . $id : '';
        $response = wireRequest($runtime, $port, $root, 'GET', '/__wire/context' . ($id !== '' ? '/' . $id : ''), $provided !== '' ? ['X-Request-ID' => $provided] : []);
        wireSame(200, $response->status, 'Sequence status');
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Sequence response must be a JSON object');
        }
        $requestId = $response->headers['x-request-id'][0] ?? '';
        if ($requestId === '' || $requestId === $previousId) {
            throw new RuntimeException('Request ID leaked between sequential requests');
        }
        if ($provided !== '') {
            wireSame($provided, $requestId, 'Sequence supplied request ID');
        }
        wireSame($requestId, $payload['requestId'] ?? null, 'Sequence context ID');
        wireSame($id !== '' ? ['id' => $id] : [], $payload['params'] ?? null, 'Sequence route parameters');
        wireSame($pid, $response->headers['x-fixture-pid'] ?? [], 'Sequence worker reuse');
        $previousId = $requestId;
    }

    return count($cases) + 4;
}

/** @param array<int, string> $hashes */
function wireAttachments(string $runtime, int $port, string $root, array $hashes): int
{
    $pid = null;
    $count = 0;
    $retainedMemory = null;
    // Include repeated large transfers after warmup in the same PHP worker.
    $sizes = [...AttachmentFiles::SIZES, ...array_fill(0, 4, 16_777_233)];
    foreach ($sizes as $index => $size) {
        foreach (['GET', 'HEAD'] as $method) {
            $context = $method . ' attachment ' . $size . ' iteration ' . $index;
            $requestId = 'attachment-' . $index . '-' . $method;
            $response = wireRequest($runtime, $port, $root, $method, '/__wire/attachment/' . $size, ['X-Request-ID' => $requestId]);
            wireSame(200, $response->status, $context . ' status');
            wireSame([$requestId], $response->headers['x-request-id'] ?? [], $context . ' request ID');
            wireSame([(string) $size], $response->headers['content-length'] ?? [], $context . ' Content-Length');
            wireSame(['application/octet-stream'], $response->headers['content-type'] ?? [], $context . ' Content-Type');
            wireSame(['attachment; filename="fixture-' . $size . '.bin"; filename*=UTF-8\'\'' . rawurlencode('Отчёт "' . $size . '".bin')], $response->headers['content-disposition'] ?? [], $context . ' Content-Disposition');
            wireSame(['nosniff'], $response->headers['x-content-type-options'] ?? [], $context . ' nosniff');
            wireSame(['no-store'], $response->headers['cache-control'] ?? [], $context . ' cache policy');
            wireSame([], $response->headers['set-cookie'] ?? [], $context . ' no session');
            wireSame([$method], $response->headers['x-fixture-method'] ?? [], $context . ' handler method');
            wireSame(['32M'], $response->headers['x-fixture-memory-limit'] ?? [], $context . ' PHP memory limit');
            $currentPid = $response->headers['x-fixture-pid'] ?? [];
            if (count($currentPid) !== 1 || $currentPid[0] === '') {
                throw new RuntimeException($context . ': missing fixture worker PID');
            }
            $pid ??= $currentPid;
            wireSame($pid, $currentPid, $context . ' worker reuse');
            if ($method === 'HEAD') {
                wireSame('', $response->body, $context . ' empty body');
            } else {
                wireSame($size, strlen($response->body), $context . ' exact bytes');
                wireSame($hashes[$size], hash('sha256', $response->body), $context . ' exact hash');
            }
            if ($index >= count(AttachmentFiles::SIZES)) {
                $memory = $response->headers['x-fixture-memory-usage'][0] ?? '';
                if (!ctype_digit($memory)) {
                    throw new RuntimeException($context . ': missing allocated memory counter');
                }
                $retainedMemory ??= (int) $memory;
                if ((int) $memory > $retainedMemory + 2_097_152) {
                    throw new RuntimeException($context . ': retained PHP allocation grew by more than 2 MiB after warmup');
                }
            }
            ++$count;
        }
    }

    return $count;
}

/**
 * @param non-empty-list<string> $command
 * @param array<string, string> $environment
 * @return resource
 */
function wireStart(array $command, string $root, string $log, array $environment)
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_replace(getenv(), $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start fixture runtime: ' . $command[0]);
    }
    fclose($pipes[0]);

    return $process;
}

/** @param resource $process */
function wireStop($process): void
{
    proc_terminate($process);
    $deadline = microtime(true) + 3;

    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
}

/** @param array<int, string> $hashes */
function wireRun(string $runtime, string $binary, string $root, string $temporary, string $errorFormat, array $hashes): void
{
    $port = wirePort();
    $log = $temporary . '/' . $runtime . '-' . $errorFormat . '.log';
    $config = $temporary . '/' . $runtime . '-' . $errorFormat . ($runtime === 'fpm' ? '.conf' : '.yaml');
    $environment = ['APP_CONFIG' => $root . '/tools/http-fixtures/' . ($runtime === 'fpm' ? 'config.php' : 'roadrunner.php'), 'STORAGE_ENGINE' => 'wire-unavailable', 'ENVIRONMENT' => 'testing', 'WIRE_ERROR_FORMAT' => $errorFormat, 'WIRE_FILE_DIRECTORY' => $temporary, 'WIRE_HTTP_LOGGING' => '1'];
    if ($runtime === 'fpm') {
        $contents = "[global]\ndaemonize = no\nerror_log = {$log}\npid = {$temporary}/fpm.pid\n[wire]\nlisten = 127.0.0.1:{$port}\npm = static\npm.max_children = 1\nclear_env = no\ncatch_workers_output = yes\nchdir = {$root}\nsecurity.limit_extensions = .php\nphp_admin_value[memory_limit] = 32M\n";
        $command = [$binary, '-F', '-y', $config];
    } else {
        $worker = PHP_BINARY . ' -d memory_limit=32M public/index.php';
        $contents = "version: '3'\nserver:\n  command: " . json_encode($worker, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\n  env: " . json_encode($environment, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\nhttp:\n  address: 127.0.0.1:{$port}\n  pool:\n    num_workers: 1\n    allocate_timeout: 5s\n    destroy_timeout: 2s\nlogs:\n  level: info\n  mode: production\n";
        $command = [$binary, 'serve', '-w', $root, '-c', $config];
    }
    if (file_put_contents($config, $contents) !== strlen($contents)) {
        throw new RuntimeException('Cannot write the temporary fixture configuration');
    }
    $process = wireStart($command, $root, $log, $environment);

    try {
        $ready = false;
        $deadline = microtime(true) + 10;

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(20_000);
        }
        if (!$ready) {
            throw new RuntimeException('Fixture runtime did not become ready within 10 seconds');
        }
        $count = wireMatrix($runtime, $port, $root, $errorFormat);
        $count += wireAttachments($runtime, $port, $root, $hashes);
        $logged = file_get_contents($log);
        wireSame(true, $logged !== false && str_contains($logged, 'Application responded.') && str_contains($logged, 'body_size'), 'Response metadata reached the runtime log');
        fwrite(STDOUT, sprintf("HTTP wire: %s, %s errors, %d cases passed (%s).\n", $runtime, $errorFormat, $count, $binary));
    } catch (Throwable $exception) {
        $output = is_file($log) ? file_get_contents($log) : '';

        throw new RuntimeException($runtime . ': ' . $exception->getMessage() . "\n" . substr($output === false ? '' : $output, -4096), 0, $exception);
    } finally {
        wireStop($process);
    }
}

$temporary = null;
$exitCode = 0;

try {
    $options = getopt('', ['runtime:', 'php-fpm:', 'rr:', 'help']);
    if (!is_array($options)) {
        throw new RuntimeException('Cannot read HTTP wire options');
    }
    if (isset($options['help'])) {
        fwrite(STDOUT, "Usage: php tools/check-http.php [--runtime=all|fpm|rr] [--php-fpm=/path/to/php-fpm] [--rr=/path/to/rr]\nRequires real PHP-FPM/RoadRunner executables; no package installation or database.\n");
        exit(0);
    }
    $selected = $options['runtime'] ?? 'all';
    if (!in_array($selected, ['all', 'fpm', 'rr'], true)) {
        throw new InvalidArgumentException('Expected --runtime=all, fpm or rr');
    }
    $runtimes = $selected === 'all' ? ['fpm', 'rr'] : [$selected];
    $binaries = [];
    foreach ($runtimes as $runtime) {
        $binary = $options[$runtime === 'fpm' ? 'php-fpm' : 'rr'] ?? ($runtime === 'fpm' ? 'php-fpm' : 'rr');
        if (!is_string($binary) || $binary === '') {
            throw new InvalidArgumentException('Expected an executable path for ' . $runtime);
        }
        $binaries[$runtime] = wireExecutable($binary);
    }
    $temporary = sys_get_temp_dir() . '/zerno-http-wire-' . bin2hex(random_bytes(6));
    if (!mkdir($temporary, 0o700)) {
        throw new RuntimeException('Cannot create the temporary fixture directory');
    }
    $hashes = AttachmentFiles::prepare($temporary);
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (): never {
                throw new RuntimeException('HTTP wire check interrupted');
            });
        }
    }
    foreach ($binaries as $runtime => $binary) {
        foreach (['json', 'html'] as $errorFormat) {
            wireRun($runtime, $binary, dirname(__DIR__), $temporary, $errorFormat, $hashes);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'HTTP wire check failed: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($temporary !== null && is_dir($temporary)) {
        foreach (new FilesystemIterator($temporary, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file instanceof SplFileInfo) {
                unlink($file->getPathname());
            }
        }
        rmdir($temporary);
    }
}

exit($exitCode);
