<?php

declare(strict_types=1);

namespace Project\ProtoGen;

final readonly class Compiler
{
    public function __construct(private string $includes) {}

    public function compile(string $input, string $executable = 'protoc', float $timeoutSeconds = 10): string
    {
        $output = tempnam(sys_get_temp_dir(), 'zerno-descriptor-');
        if ($output === false) {
            throw new \RuntimeException('Cannot allocate compiler output');
        }
        $process = null;
        $pipes = [];

        try {
            $process = proc_open([
                $executable, '--proto_path=' . \dirname($input), '--proto_path=' . $this->includes,
                '--descriptor_set_out=' . $output, basename($input),
            ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, \dirname($input), null, ['bypass_shell' => true]);
            if (!\is_resource($process)) {
                throw new \RuntimeException('Cannot start protoc; provide the platform compiler explicitly');
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
            $diagnostics = '';
            do {
                foreach ($pipes as $pipe) {
                    $chunk = stream_get_contents($pipe, 65_537);
                    if ($chunk === false) {
                        throw new \RuntimeException('Cannot read protoc output');
                    }
                    $diagnostics .= $chunk;
                }
                clearstatcache(true, $output);
                if (\strlen($diagnostics) > 65_536 || hrtime(true) >= $deadline || filesize($output) > 4_194_304) {
                    throw new \RuntimeException('protoc exceeded its time or output limit');
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    usleep(10_000);
                }
            } while ($status['running']);
            // A process may finish between the last read and status check.
            foreach ($pipes as $pipe) {
                $chunk = stream_get_contents($pipe, 65_537);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot drain protoc output');
                }
                $diagnostics .= $chunk;
            }
            if (\strlen($diagnostics) > 65_536) {
                throw new \RuntimeException('protoc exceeded its time or output limit');
            }
            if ($status['exitcode'] !== 0) {
                throw new \RuntimeException('protoc failed: ' . trim($diagnostics));
            }
            $bytes = file_get_contents($output);
            if ($bytes === false || $bytes === '' || \strlen($bytes) > 4_194_304) {
                throw new \RuntimeException('protoc produced no usable descriptor');
            }

            return $bytes;
        } finally {
            if (\is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (\is_resource($process)) {
                proc_close($process);
            }
            unlink($output);
        }
    }
}
