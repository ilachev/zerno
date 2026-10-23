<?php

declare(strict_types=1);

namespace Project\Security;

/** Owns the scanner process, deadline and bounded diagnostics. */
final class Process
{
    /** @param non-empty-list<string> $command
     * @return array{exit: int, output: string}
     */
    public static function run(array $command, float $timeoutSeconds = 120, int $outputLimit = 2_097_152): array
    {
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start security platform tool');
        }
        fclose($pipes[0]);
        unset($pipes[0]);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $output = '';

        try {
            do {
                foreach ($pipes as $pipe) {
                    $chunk = stream_get_contents($pipe, $outputLimit + 1);
                    if ($chunk === false) {
                        throw new \RuntimeException('Cannot read security platform diagnostics');
                    }
                    $output .= $chunk;
                }
                if (\strlen($output) > $outputLimit || hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Security platform tool exceeded its time or output limit');
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    usleep(10_000);
                }
            } while ($status['running']);
            foreach ($pipes as $pipe) {
                $chunk = stream_get_contents($pipe, $outputLimit + 1);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot drain security platform diagnostics');
                }
                $output .= $chunk;
            }
            if (\strlen($output) > $outputLimit) {
                throw new \RuntimeException('Security platform tool exceeded its output limit');
            }

            return ['exit' => $status['exitcode'], 'output' => $output];
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
