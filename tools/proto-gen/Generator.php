<?php

declare(strict_types=1);

namespace Project\ProtoGen;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FileDescriptorSet;
use Google\Protobuf\Internal\FileOptions;

/** One file of proto3 string messages and unary POST operations. No runtime discovery. */
final class Generator
{
    /** @return array<string, string> */
    public function generate(string $descriptor, string $source): array
    {
        $set = new FileDescriptorSet();
        $set->mergeFromString($descriptor);
        if (\count($set->getFile()) !== 1) {
            throw new \InvalidArgumentException('Expected exactly one application descriptor without imported descriptors');
        }
        $file = $set->getFile()[0];
        if ($file->getName() !== $source || $file->getSyntax() !== 'proto3'
            || \count($file->getEnumType()) !== 0 || \count($file->getExtension()) !== 0
            || iterator_to_array($file->getDependency()) !== ['google/api/annotations.proto']) {
            throw new \InvalidArgumentException('Only proto3 messages/services with the google/api/annotations.proto import are supported');
        }
        $namespace = $file->getOptions()?->getPhpNamespace() ?? '';
        if (preg_match('/\AApp\\\Generated\\\[A-Z][a-zA-Z0-9]*(?:\\\[A-Z][a-zA-Z0-9]*)*\z/', $namespace) !== 1
            || $file->getOptions()?->serializeToString() !== new FileOptions()->setPhpNamespace($namespace)->serializeToString()) {
            throw new \InvalidArgumentException('Set only php_namespace to App\Generated\<Name> in file options');
        }
        $output = [];
        $messages = [];
        foreach ($file->getMessageType() as $message) {
            $this->add($output, $message->getName(), $this->dto($namespace, $message));
            $messages['.' . $file->getPackage() . '.' . $message->getName()] = $message->getName();
        }
        $paths = [];
        foreach ($file->getService() as $service) {
            if (($service->getOptions()?->serializeToString() ?? '') !== '') {
                throw new \InvalidArgumentException('Service options are unsupported: ' . $service->getName());
            }
            foreach ($service->getMethod() as $method) {
                $name = $service->getName() . $method->getName();
                $request = $messages[$method->getInputType()] ?? null;
                $response = $messages[$method->getOutputType()] ?? null;
                if ($method->getClientStreaming() || $method->getServerStreaming() || $request === null || $response === null) {
                    throw new \InvalidArgumentException('RPC must be unary and use messages from this file: ' . $name);
                }
                $path = HttpBinding::path($method->getOptions()?->serializeToString() ?? '');
                if (isset($paths[$path])) {
                    throw new \InvalidArgumentException('Duplicate POST route: ' . $path);
                }
                $paths[$path] = true;
                $this->add($output, $name . 'Endpoint', $this->endpoint($namespace, $name, $request, $response));
                $this->add($output, $name . 'Http', $this->http($namespace, $name, $request, $path));
            }
        }
        if ($paths === []) {
            throw new \InvalidArgumentException('Contract needs at least one unary POST RPC');
        }
        ksort($output);

        return $output;
    }

    private function dto(string $namespace, DescriptorProto $message): string
    {
        if (\count($message->getNestedType()) !== 0 || \count($message->getEnumType()) !== 0
            || \count($message->getOneofDecl()) !== 0 || \count($message->getExtension()) !== 0
            || ($message->getOptions()?->serializeToString() ?? '') !== '') {
            throw new \InvalidArgumentException('Nested types, enums, oneofs and message options are unsupported: ' . $message->getName());
        }
        $properties = $checks = $arguments = $encoding = '';
        $names = [];
        foreach ($message->getField() as $field) {
            $name = $field->getName();
            if ($field->getType() !== 9 || $field->getLabel() !== 1 || $field->getProto3Optional()
                || $field->getJsonName() !== $name || preg_match('/\A[a-z][a-zA-Z0-9]*\z/', $name) !== 1
                || ($field->getOptions()?->serializeToString() ?? '') !== '') {
                throw new \InvalidArgumentException('Only implicit singular string fields with identical PHP/JSON names are supported: ' . $message->getName() . '.' . $name);
            }
            $names[] = $name;
            $value = '$value' . \count($names);
            $properties .= "        public string \${$name} = '',\n";
            $checks .= "        {$value} = \$input->{$name} ?? '';\n        if (!\\is_string({$value})) {\n            throw new \\InvalidArgumentException('Expected string: {$name}');\n        }\n";
            $arguments .= "            {$value},\n";
            $encoding .= "        if (\$this->{$name} !== '') {\n            \$output['{$name}'] = \$this->{$name};\n        }\n";
        }
        $allowed = '[' . implode(', ', array_map(static fn(string $field): string => var_export($field, true), $names)) . ']';
        $name = $message->getName();

        return $this->header($namespace) . <<<PHP
            final readonly class {$name}
            {
                public function __construct(
            {$properties}    ) {}

                public static function fromJson(string \$json): self
                {
                    \$input = json_decode(\$json, false, 32, JSON_THROW_ON_ERROR);
                    if (!\$input instanceof \\stdClass || array_diff(array_keys(get_object_vars(\$input)), {$allowed}) !== []) {
                        throw new \\InvalidArgumentException('Expected an object containing only contract fields');
                    }
            {$checks}
                    return new self(
            {$arguments}        );
                }

                public function toJson(): string
                {
                    \$output = [];
            {$encoding}
                    return json_encode((object) \$output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }

            PHP;
    }

    private function endpoint(string $namespace, string $name, string $request, string $response): string
    {
        $requestType = '\\' . $namespace . '\\' . $request;
        $responseType = '\\' . $namespace . '\\' . $response;

        return $this->header($namespace) . <<<PHP
            interface {$name}Endpoint
            {
                public function handle({$requestType} \$request): {$responseType};
            }

            PHP;
    }

    private function http(string $namespace, string $name, string $request, string $path): string
    {
        $pathLiteral = var_export($path, true);
        $requestType = '\\' . $namespace . '\\' . $request;

        return $this->header($namespace) . <<<PHP
            use App\\Platform\\Http\\Request;
            use App\\Platform\\Http\\RequestHandler;
            use App\\Platform\\Http\\Response;
            use App\\Platform\\Routing\\RouteEntry;

            final readonly class {$name}Http implements RequestHandler
            {
                public function __construct(private {$name}Endpoint \$endpoint, private int \$maxBytes)
                {
                    if (\$maxBytes < 0) {
                        throw new \\InvalidArgumentException('JSON body byte limit must not be negative');
                    }
                }

                public function route(): RouteEntry
                {
                    return new RouteEntry('POST', {$pathLiteral}, \$this);
                }

                public function handle(Request \$request): Response
                {
                    \$body = \$request->getBody();
                    try {
                        if (\$request->getMethod() !== 'POST') {
                            return new Response(405, ['Allow' => 'POST']);
                        }
                        \$types = \$request->getHeader('Content-Type');
                        \$type = strtolower(trim(explode(';', \$types[0] ?? '', 2)[0]));
                        \$encoding = strtolower(trim(\$request->getHeaderLine('Content-Encoding')));
                        if (\\count(\$types) !== 1 || \$type !== 'application/json' || !\\in_array(\$encoding, ['', 'identity'], true)) {
                            return \$this->error(415, 'Unsupported media type');
                        }
                        try {
                            \$json = \$body->readLimited(\$this->maxBytes);
                        } catch (\\LengthException) {
                            return \$this->error(413, 'Request body too large');
                        }
                        try {
                            \$input = {$requestType}::fromJson(\$json);
                        } catch (\\JsonException|\\InvalidArgumentException) {
                            return \$this->error(400, 'Invalid JSON request');
                        }

                        return new Response(200, ['Content-Type' => 'application/json'], \$this->endpoint->handle(\$input)->toJson());
                    } finally {
                        \$body->close();
                    }
                }

                private function error(int \$status, string \$message): Response
                {
                    return new Response(\$status, ['Content-Type' => 'application/json'], json_encode(['error' => \$message], JSON_THROW_ON_ERROR));
                }
            }

            PHP;
    }

    private function header(string $namespace): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n// Generated by tools/proto-gen. Do not edit.\n\n";
    }

    /** @param array<string, string> $output */
    private function add(array &$output, string $name, string $php): void
    {
        if (preg_match('/\A[A-Z][a-zA-Z0-9]*\z/', $name) !== 1 || \in_array(strtolower($name . '.php'), array_map(strtolower(...), array_keys($output)), true)) {
            throw new \InvalidArgumentException('Invalid or colliding PHP type: ' . $name);
        }

        // Reject PHP reserved words and invalid generated signatures before touching output.
        try {
            if (token_get_all($php, TOKEN_PARSE) === []) {
                throw new \LogicException('Generated PHP is empty');
            }
        } catch (\ParseError $exception) {
            throw new \InvalidArgumentException('Contract produces an invalid PHP type: ' . $name, previous: $exception);
        }
        $output[$name . '.php'] = $php;
    }
}
