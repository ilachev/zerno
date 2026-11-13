<?php

declare(strict_types=1);

/** Owns a bounded checker child and its process group, including RoadRunner workers. */
final class CatalogProcess
{
    private const int OUTPUT_LIMIT = 262_144;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $pid;

    private string $output = '';

    private bool $closed = false;

    /** @param non-empty-list<string> $command
     * @param array<string, string> $environment
     */
    public function __construct(array $command, string $directory, array $environment)
    {
        // A separate session lets cleanup reach workers even if the server exits first.
        $launcher = 'if (posix_setsid() < 0) { exit(126); } pcntl_exec($argv[1], array_slice($argv, 2)); exit(127);';
        $process = proc_open([PHP_BINARY, '-r', $launcher, ...$command], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start child process');
        }
        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'];
        fclose($pipes[0]);
        unset($pipes[0]);
        $this->pipes = $pipes;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    public function poll(): ?int
    {
        foreach ($this->pipes as $pipe) {
            $chunk = stream_get_contents($pipe, self::OUTPUT_LIMIT - strlen($this->output) + 1);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read child diagnostics');
            }
            if (strlen($this->output) + strlen($chunk) > self::OUTPUT_LIMIT) {
                throw new RuntimeException('Child diagnostics exceeded 256 KiB');
            }
            $this->output .= $chunk;
        }
        $status = proc_get_status($this->process);

        return $status['running'] ? null : $status['exitcode'];
    }

    public function wait(float $deadline): string
    {
        while (true) {
            $exit = $this->poll();
            if ($exit !== null) {
                // Drain the final bytes after observing process exit.
                $this->poll();
                if ($exit !== 0) {
                    throw new RuntimeException('Child exited with status ' . $exit . '; private diagnostics were withheld');
                }

                return $this->output;
            }
            if (hrtime(true) / 1_000_000_000 >= $deadline) {
                throw new RuntimeException('Child exceeded its deadline');
            }
            usleep(10_000);
        }
    }

    public function stop(): void
    {
        if ($this->closed) {
            return;
        }
        @posix_kill(-$this->pid, SIGTERM);
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        $deadline = hrtime(true) + 2_000_000_000;

        while ((proc_get_status($this->process)['running'] || @posix_kill(-$this->pid, 0)) && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        @posix_kill(-$this->pid, SIGKILL);
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, SIGKILL);
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        $deadline = hrtime(true) + 1_000_000_000;

        while ((proc_get_status($this->process)['running'] || @posix_kill(-$this->pid, 0)) && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        if (proc_get_status($this->process)['running'] || @posix_kill(-$this->pid, 0)) {
            throw new RuntimeException('Child process group did not stop');
        }
        proc_close($this->process);
        $this->closed = true;
    }
}
