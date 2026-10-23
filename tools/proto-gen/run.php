<?php

declare(strict_types=1);

use Project\ProtoGen\Compiler;
use Project\ProtoGen\Generator;
use Project\ProtoGen\Output;

require_once __DIR__ . '/bootstrap.php';

try {
    $arguments = $argv ?? [];
    $mode = $arguments[1] ?? 'check';
    if (!in_array($mode, ['generate', 'check', 'compile-check'], true)) {
        throw new InvalidArgumentException('Usage: run.php generate|check|compile-check [--protoc=/path/to/protoc]');
    }
    $protoc = 'protoc';
    foreach (array_slice($arguments, 2) as $argument) {
        if (!str_starts_with($argument, '--protoc=') || substr($argument, 9) === '') {
            throw new InvalidArgumentException('Unknown generator option: ' . $argument);
        }
        $protoc = substr($argument, 9);
    }
    $root = dirname(__DIR__, 2);
    $input = $root . '/contracts/echo.proto';
    $include = $root . '/third_party/codegen/proto';
    $descriptorPath = $root . '/contracts/echo.pb';
    $snapshotPath = $root . '/contracts/echo.inputs.json';
    $output = $root . '/gen/Generated/Echo';
    $inputs = ['echo.proto' => hash('sha256', Output::read($input))];
    foreach (['google/api/annotations.proto', 'google/api/http.proto', 'google/protobuf/descriptor.proto'] as $relative) {
        $inputs[$relative] = hash('sha256', Output::read($include . '/' . $relative));
    }
    $descriptor = $mode === 'check' ? Output::read($descriptorPath) : new Compiler($include)->compile($input, $protoc);
    $files = new Generator()->generate($descriptor, 'echo.proto');
    $snapshot = json_encode(['inputs' => $inputs, 'descriptor_sha256' => hash('sha256', $descriptor)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if ($mode === 'generate') {
        // Validate and stage all PHP before replacing the previous usable set.
        Output::replace($output, $files);
        if (file_put_contents($descriptorPath, $descriptor) !== strlen($descriptor)
            || file_put_contents($snapshotPath, $snapshot) !== strlen($snapshot)) {
            throw new RuntimeException('Cannot save the descriptor snapshot; rerun proto:gen');
        }
    } else {
        if (Output::read($snapshotPath) !== $snapshot || Output::read($descriptorPath) !== $descriptor) {
            throw new RuntimeException('Contract sources or compiled descriptor changed; run proto:gen');
        }
        Output::check($output, $files);
    }
    fwrite(STDOUT, 'Protobuf ' . $mode . ': ' . count($files) . " PHP files, no runtime descriptors.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
