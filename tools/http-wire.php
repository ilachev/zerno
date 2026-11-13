<?php

declare(strict_types=1);

final readonly class HttpWireResponse
{
    /** @param array<array-key, list<string>> $headers */
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
function wireConnect(int $port, float $timeout = 1)
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, $timeout);
    if ($socket === false) {
        throw new RuntimeException('Cannot connect to the fixture: ' . $message);
    }

    return $socket;
}

/** @param resource $socket */
function wireTimeout($socket, float $deadline): void
{
    $remaining = $deadline - hrtime(true) / 1_000_000_000;
    if ($remaining <= 0) {
        throw new RuntimeException('HTTP response exceeded its deadline');
    }
    stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
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

function wireResponse(string $raw, string $method): HttpWireResponse
{
    $parts = explode("\r\n\r\n", $raw, 2);
    if (count($parts) !== 2) {
        throw new RuntimeException('Fixture response has no complete header block');
    }
    [$head, $body] = $parts;
    $status = 200;
    $headers = [];
    foreach (explode("\r\n", $head) as $index => $line) {
        if ($index === 0) {
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
        $headers[$name][] = $value;
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

/** @param array<array-key, string> $headers */
function wireHttp(int $port, string $method, string $target, array $headers, string $body = '', bool $chunked = false, float $timeout = 3, int $responseLimit = 33_554_432): HttpWireResponse
{
    $deadline = hrtime(true) / 1_000_000_000 + $timeout;
    $socket = wireConnect($port, min(1, $timeout));

    try {
        $request = $method . ' ' . $target . " HTTP/1.1\r\nHost: fixture.test\r\nConnection: close\r\n"
            . ($chunked ? "Transfer-Encoding: chunked\r\n" : 'Content-Length: ' . strlen($body) . "\r\n");
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        wireWrite($socket, $request . "\r\n", $deadline);
        if ($chunked) {
            foreach (str_split($body, 8192) as $chunk) {
                wireWrite($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n", $deadline);
            }
            wireWrite($socket, "0\r\n\r\n", $deadline);
        } else {
            wireWrite($socket, $body, $deadline);
        }
        $raw = '';

        while (!feof($socket)) {
            wireTimeout($socket, $deadline);
            $chunk = fread($socket, 8192);
            if ($chunk === false || ($chunk === '' && !feof($socket))) {
                throw new RuntimeException('Incomplete HTTP response or socket timeout');
            }
            $raw .= $chunk;
            if (strlen($raw) > $responseLimit) {
                throw new RuntimeException('HTTP response exceeded its byte limit');
            }
        }

        return wireResponse($raw, $method);
    } finally {
        fclose($socket);
    }
}
