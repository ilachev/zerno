<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('Sample inside the Linux server namespace with readable /proc.');
}
$processes = [];
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    $pid = (int) basename(dirname($path));
    $command = @file_get_contents($path);
    if ($command === false || ($pid !== 1 && !str_contains($command, 'public/index.php'))) {
        continue;
    }
    $status = @file_get_contents(dirname($path) . '/status');
    $stat = @file_get_contents(dirname($path) . '/stat');
    if ($status === false || $stat === false || preg_match('/VmRSS:\s+(\d+) kB/', $status, $rss) !== 1
        || preg_match('/VmHWM:\s+(\d+) kB/', $status, $peak) !== 1) {
        throw new RuntimeException('Cannot sample an active server/worker process: ' . $pid);
    }
    $closingParenthesis = strrpos($stat, ')');
    if ($closingParenthesis === false) {
        throw new RuntimeException('Invalid process stat: ' . $pid);
    }
    $fields = explode(' ', trim(substr($stat, $closingParenthesis + 1)));
    if (!isset($fields[11], $fields[12], $fields[19])) {
        throw new RuntimeException('Incomplete process stat: ' . $pid);
    }
    $processes[] = [
        'pid' => $pid,
        'role' => $pid === 1 ? 'server' : 'worker',
        'start_ticks' => (int) $fields[19],
        'rss_bytes' => (int) $rss[1] * 1024,
        'peak_rss_bytes' => (int) $peak[1] * 1024,
        'cpu_ticks' => (int) $fields[11] + (int) $fields[12],
        'open_fds' => count(glob(dirname($path) . '/fd/*') ?: []),
    ];
}
echo json_encode(['time' => microtime(true), 'processes' => $processes], JSON_THROW_ON_ERROR) . "\n";
