<?php

declare(strict_types=1);

use Project\ProtoGen\Output;

require_once __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/zerno-contract-cost-' . bin2hex(random_bytes(8));
if (!mkdir($temporary)) {
    throw new RuntimeException('Cannot create measurement directory');
}

/** @param non-empty-list<string> $command */
function measureRun(array $command, string $directory): string
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start measurement process');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = hrtime(true) + 30_000_000_000;
    $output = '';

    try {
        do {
            $output .= stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            if ($errors !== '') {
                throw new RuntimeException('Measurement diagnostic: ' . $errors);
            }
            if (strlen($output) > 65_536 || hrtime(true) > $deadline) {
                throw new RuntimeException('Measurement exceeded its resource limit');
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(10_000);
            }
        } while ($status['running']);
        $remaining = stream_get_contents($pipes[1], 65_537);
        $errors = stream_get_contents($pipes[2], 65_537);
        if ($remaining === false || $errors === false || $errors !== '' || strlen($output) + strlen($remaining) > 65_536) {
            throw new RuntimeException('Invalid final measurement output');
        }
        $output .= $remaining;
        if ($status['exitcode'] !== 0) {
            throw new RuntimeException('Measurement exited with status ' . $status['exitcode']);
        }

        return $output;
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}

try {
    // The representation comparison needs only the two identical message definitions.
    $source = str_replace('App\\\Generated\\\Echo', 'Zerno\\\Measured', Output::read($root . '/contracts/echo.proto'));
    $source = str_replace('import "google/api/annotations.proto";', '', $source);
    $source = preg_replace('/service Echo \{.*?\n\}/s', '', $source);
    if ($source === null) {
        throw new RuntimeException('Cannot prepare message-only measurement contract');
    }
    file_put_contents($temporary . '/echo.proto', $source);
    $compiler = getenv('PROTOC') ?: 'protoc';
    measureRun([$compiler, '--proto_path=' . $temporary, '--proto_path=' . $root . '/third_party/codegen/proto', '--php_out=' . $temporary, 'echo.proto'], $temporary);
    $program = <<<'PHP'
        <?php
        declare(strict_types=1);
        require $argv[1] . '/bootstrap.php';
        $mode = $argv[3];
        $json = json_encode(['message' => str_repeat('Привет', 20)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $classes = count(get_declared_classes());
        $memory = memory_get_usage();
        $start = hrtime(true);
        if ($mode === 'protobuf') {
            require $argv[2] . '/GPBMetadata/PBEcho.php';
            require $argv[2] . '/Zerno/Measured/EchoRequest.php';
            require $argv[2] . '/Zerno/Measured/EchoResponse.php';
            $operation = static function () use ($json): string {
                $request = new Zerno\Measured\EchoRequest();
                $request->mergeFromJsonString($json);
                return new Zerno\Measured\EchoResponse(['message' => $request->getMessage()])->serializeToJsonString();
            };
        } elseif ($mode === 'dto') {
            $operation = static fn(): string => new App\Generated\Echo\EchoResponse(App\Generated\Echo\EchoRequest::fromJson($json)->message)->toJson();
        } else {
            require $argv[1] . ($mode === 'manual' ? '/examples/json-echo/EchoHandler.php' : '/examples/contract-echo/EchoEndpoint.php');
            $handler = $mode === 'manual'
                ? new Examples\JsonEcho\EchoHandler(65536)
                : new App\Generated\Echo\EchoSendHttp(new Examples\ContractEcho\EchoEndpoint(), 65536);
            $operation = static function () use ($handler, $json): string {
                $response = $handler->handle(new App\Platform\Http\Request('POST', '/echo', ['Content-Type' => 'application/json'], $json));
                try { return (string) $response->getBody(); } finally { $response->getBody()->close(); }
            };
        }
        $result = $operation();
        $first = hrtime(true) - $start;
        if ($result !== $json) { throw new RuntimeException('Measurements must return identical JSON'); }
        $loaded = count(get_declared_classes()) - $classes;
        $firstMemory = memory_get_usage() - $memory;
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        for ($i = 0; $i < 20000; ++$i) { $operation(); }
        $elapsed = hrtime(true) - $start;
        $retained = memory_get_usage() - $before;
        $peak = memory_get_peak_usage() - $before;
        echo json_encode(['mode' => $mode, 'iterations' => 20000, 'input_bytes' => strlen($json), 'first_ns' => $first, 'classes' => $loaded, 'first_php_bytes' => $firstMemory, 'ns_per_operation' => $elapsed / 20000, 'retained_php_bytes' => $retained, 'peak_php_bytes' => $peak], JSON_THROW_ON_ERROR), "\n";
        PHP;
    file_put_contents($temporary . '/measure.php', $program);
    $results = [];
    for ($series = 0; $series < 5; ++$series) {
        foreach (['dto', 'protobuf', 'manual', 'generated'] as $mode) {
            $results[] = json_decode(measureRun([PHP_BINARY, '-d', 'opcache.enable_cli=0', $temporary . '/measure.php', $root, $temporary, $mode], $root), true, 32, JSON_THROW_ON_ERROR);
        }
    }
    fwrite(STDOUT, json_encode(['php' => PHP_VERSION, 'compiler' => $compiler, 'opcache_cli' => false, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    Output::remove($temporary);
}
