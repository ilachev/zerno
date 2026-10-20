<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\Body;
use App\Platform\Http\Request;
use App\Platform\Http\UploadedFile;
use App\Platform\Http\Uri;

final readonly class SapiRequestFactory
{
    public function fromGlobals(): Request
    {
        return $this->create(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            Body::fromFile('php://input'),
        );
    }

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $files
     */
    public function create(
        array $server,
        array $query = [],
        array $post = [],
        array $cookies = [],
        array $files = [],
        ?Body $body = null,
    ): Request {
        $method = $this->serverString($server, 'REQUEST_METHOD', 'GET');
        $target = $this->serverString($server, 'REQUEST_URI', '/');
        if (
            ($target !== '*' || $method !== 'OPTIONS')
            && (!str_starts_with($target, '/') || str_contains($target, '\\') || preg_match('/[\x00-\x20\x7f#]|%(?![a-fA-F0-9]{2})/', $target) === 1)
        ) {
            throw new \InvalidArgumentException('Invalid request target');
        }

        $protocol = $this->serverString($server, 'SERVER_PROTOCOL', 'HTTP/1.1');
        if (preg_match('/\AHTTP\/(\d+(?:\.\d+)?)\z/', $protocol, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP protocol');
        }

        $version = match ($matches[1]) {
            '2.0' => '2',
            '3.0' => '3',
            default => $matches[1],
        };
        $request = new Request($method, $this->createUri($server, $target), serverParams: $server)
            ->withProtocolVersion($version)
            ->withRequestTarget($target)
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withUploadedFiles($this->uploadedFiles($files))
            ->withBody($body ?? Body::create());

        foreach ($this->headers($server) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($method === 'POST' && \in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
            $request = $request->withParsedBody($post);
        }

        return $request;
    }

    /** @param array<array-key, mixed> $server */
    private function createUri(array $server, string $target): Uri
    {
        $https = $this->serverString($server, 'HTTPS', 'off');
        $scheme = $https !== '' && $https !== '0' && strcasecmp($https, 'off') !== 0 ? 'https' : 'http';
        $authority = $this->serverString($server, 'HTTP_HOST');

        if (!\array_key_exists('HTTP_HOST', $server)) {
            $authority = $this->serverString($server, 'SERVER_NAME', $this->serverString($server, 'SERVER_ADDR'));
            if (filter_var($authority, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $authority = '[' . $authority . ']';
            }

            if (isset($server['SERVER_PORT'])) {
                $port = $server['SERVER_PORT'];
                if (!\is_string($port) && !\is_int($port)) {
                    throw new \InvalidArgumentException('Invalid server port');
                }
                $authority .= ':' . $port;
            }
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/\A\[([^]]+)\](?::([0-9]+))?\z/', $authority, $parts) !== 1
                || filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Invalid request authority');
            }
        } else {
            if (preg_match("~\\A((?:[a-zA-Z0-9._\\~!$&'()*+,;=-]|%[a-fA-F0-9]{2})+)(?::([0-9]+))?\\z~", $authority, $parts) !== 1) {
                throw new \InvalidArgumentException('Invalid request authority');
            }
        }

        $port = isset($parts[2]) ? (int) $parts[2] : null;
        if ($port !== null && $port > 65535) {
            throw new \InvalidArgumentException('Invalid request port');
        }

        return new Uri($scheme . '://' . $authority . ($target === '*' ? '' : $target));
    }

    /**
     * @param array<array-key, mixed> $server
     * @return array<string, string>
     */
    private function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $name => $value) {
            if (\is_string($name) && str_starts_with($name, 'HTTP_')) {
                if (!\is_string($value)) {
                    throw new \InvalidArgumentException('Invalid request header');
                }
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $name) {
            if (isset($server[$name])) {
                $headers[str_replace('_', '-', $name)] = $this->serverString($server, $name);
            }
        }

        if (!isset($headers['AUTHORIZATION'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['AUTHORIZATION'] = $this->serverString($server, 'REDIRECT_HTTP_AUTHORIZATION');
            } elseif (isset($server['PHP_AUTH_USER'])) {
                $headers['AUTHORIZATION'] = 'Basic ' . base64_encode(
                    $this->serverString($server, 'PHP_AUTH_USER') . ':' . $this->serverString($server, 'PHP_AUTH_PW'),
                );
            } elseif (isset($server['PHP_AUTH_DIGEST'])) {
                $headers['AUTHORIZATION'] = $this->serverString($server, 'PHP_AUTH_DIGEST');
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function uploadedFiles(array $files): array
    {
        $uploads = [];
        foreach ($files as $name => $file) {
            if (!\is_array($file)) {
                throw new \InvalidArgumentException('Invalid uploaded file');
            }
            $uploads[$name] = $this->uploadedFile($file);
        }

        return $uploads;
    }

    /**
     * @param array<array-key, mixed> $file
     * @return UploadedFile|array<array-key, mixed>
     */
    private function uploadedFile(array $file): UploadedFile|array
    {
        $error = $file['error'] ?? null;
        if (\is_array($error)) {
            $uploads = [];
            foreach ($error as $index => $_error) {
                $nested = [];
                foreach (['name', 'type', 'tmp_name', 'size', 'error'] as $name) {
                    $values = $file[$name] ?? null;
                    if (!\is_array($values) || !\array_key_exists($index, $values)) {
                        throw new \InvalidArgumentException('Invalid uploaded file structure');
                    }
                    $nested[$name] = $values[$index];
                }
                $uploads[$index] = $this->uploadedFile($nested);
            }

            return $uploads;
        }

        $path = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        $name = $file['name'] ?? null;
        $type = $file['type'] ?? null;
        if (!\is_int($error) || !\is_string($path) || !\is_int($size) || $size < 0 || !\is_string($name) || !\is_string($type)) {
            throw new \InvalidArgumentException('Invalid uploaded file metadata');
        }

        // Preserve the upload path so moveTo() retains SAPI upload validation.
        return new UploadedFile($path, $size, $error, $name, $type);
    }

    /** @param array<array-key, mixed> $server */
    private function serverString(array $server, string $name, string $default = ''): string
    {
        $value = $server[$name] ?? $default;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Invalid server parameter');
        }

        return $value;
    }
}
