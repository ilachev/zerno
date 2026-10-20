#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @param list<string> $arguments */
function projectCommand(string $command, array $arguments = []): void
{
    $root = dirname(__DIR__);
    $sqlArguments = [
        '--input-dir=sql/queries',
        '--output-dir=gen/Generated/Sql',
        '--namespace=App\Generated\Sql',
        '--schema=sql/schema.sql',
        '--runtime-namespace=App\Platform\Storage\Sql',
    ];

    switch ($command) {
        case 'help':
            fwrite(STDOUT, <<<'HELP'
                Usage: php tools/project.php <command> [arguments...]

                verify                 Integrity, documentation, style, analysis, SQL synchronization and unit tests
                verify:integration     PostgreSQL SQL validation and integration tests
                verify:full            All root, SQL-generator and PostgreSQL checks
                third-party:check      Verify the bundled dependency manifest and file hashes
                docs:check             Verify the documentation inventory and local Markdown links
                http:check             Check real PHP-FPM/RoadRunner HTTP behavior; forwards runtime options
                lint | fixcs           Check or fix PHP style
                phpstan                Run root static analysis
                test                   Run root unit tests; forwards PHPUnit arguments
                test:integration       Run isolated PostgreSQL integration tests
                test:full              Unit and PostgreSQL integration tests
                sql:gen | sql:check    Generate or check typed SQL artifacts
                sql:check:pg           Validate SQL against PostgreSQL using TEST_DB_* settings
                tools:sql:check        Run style, PHPStan and tests for tools/sql-gen
                tools:sql:<lint|fixcs|phpstan|test>
                migrate                Run migrations; accepts action and application configuration
                services:start         Start the PostgreSQL development service without running migrations
                services:stop | services:status
                run                    Start the HTTP/PDO core through RoadRunner
                perf:prepare           Create an isolated PostgreSQL baseline fixture; uses TEST_DB_*
                perf:baseline          Run the JSON or SELECT k6 baseline; uses BASE_URL and CASE
                perf:catalog           Run the public catalog HTML k6 scenario; uses BASE_URL and CASE
                perf:cleanup <schema>  Remove a baseline fixture created by perf:prepare
                build [version]        Create a runtime tar.gz in dist/

                Bundled PHP dependencies and quality tools are used directly; no installation step.

                HELP);

            return;
        case 'verify':
            projectSequence(['third-party:check', 'docs:check', 'lint', 'phpstan', 'sql:check', 'test']);

            return;
        case 'verify:integration':
            projectSequence(['sql:check:pg', 'test:integration']);

            return;
        case 'verify:full':
            projectSequence(['verify', 'tools:sql:check', 'verify:integration']);

            return;
        case 'test:full':
            projectSequence(['test', 'test:integration']);

            return;
        case 'third-party:check':
            projectRun([PHP_BINARY, $root . '/tools/check-third-party.php', ...$arguments], $root);

            return;
        case 'docs:check':
            projectRun([PHP_BINARY, $root . '/tools/check-docs.php', ...$arguments], $root);

            return;
        case 'http:check':
            projectRun([PHP_BINARY, $root . '/tools/check-http.php', ...$arguments], $root);

            return;
        case 'lint':
        case 'fixcs':
            projectDirectory($root . '/var');
            projectRun([
                PHP_BINARY, $root . '/tools/php-cs-fixer.php', 'fix',
                ...($command === 'lint' ? ['--dry-run'] : []), ...$arguments,
            ], $root);

            return;
        case 'phpstan':
            projectDirectory($root . '/var');
            $paths = $arguments === []
                ? ['src', 'tests', 'config', 'public', 'bootstrap.php', 'bootstrap', 'tools/http-fixtures', 'load-tests/baseline', 'examples/document-catalog', ...(glob($root . '/tools/*.php') ?: [])]
                : [];
            projectRun([
                PHP_BINARY, $root . '/tools/phpstan.php', 'analyse',
                '--autoload-file=' . $root . '/tests/bootstrap.php', '--memory-limit=256M', ...$paths, ...$arguments,
            ], $root);

            return;
        case 'test':
        case 'test:integration':
            projectDirectory($root . '/var');
            projectRun([
                PHP_BINARY, $root . '/tools/phpunit.php', '--colors=never', '-c',
                $command === 'test' ? 'phpunit.xml' : 'phpunit.integration.xml', ...$arguments,
            ], $root, $command === 'test:integration' ? projectTestEnvironment() : []);

            return;
        case 'sql:gen':
            projectRemoveDirectory($root . '/gen/Generated/Sql');
            projectRun([PHP_BINARY, $root . '/tools/sql-gen/bin/sql-gen.php', ...$sqlArguments, ...$arguments], $root);

            return;
        case 'sql:check':
            projectRun([PHP_BINARY, $root . '/tools/sql-gen/bin/sql-check.php', ...$sqlArguments, ...$arguments], $root);

            return;
        case 'sql:check:pg':
            $testEnvironment = projectTestEnvironment();
            $environment = [];
            foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $name) {
                $environment['DB_' . $name] = $testEnvironment['TEST_DB_' . $name];
            }
            projectRun([
                PHP_BINARY, $root . '/tools/sql-gen/bin/sql-check-pg.php',
                '--input-dir=sql/queries', '--schema=sql/schema.sql', ...$arguments,
            ], $root, $environment);

            return;
        case 'migrate':
            projectRun([PHP_BINARY, $root . '/bin/migrate.php', ...$arguments], $root);

            return;
        case 'services:start':
            projectRun(['docker', 'compose', 'up', '-d', '--pull', 'never'], $root);
            for ($attempt = 0;; ++$attempt) {
                try {
                    projectRun(['docker', 'compose', 'exec', '-T', 'db-postgres', 'pg_isready', '-U', 'app'], $root);
                    break;
                } catch (RuntimeException $exception) {
                    if ($attempt >= 29) {
                        throw $exception;
                    }
                    usleep(1_000_000);
                }
            }

            return;
        case 'services:stop':
        case 'services:status':
            projectRun(['docker', 'compose', $command === 'services:stop' ? 'stop' : 'ps', ...$arguments], $root);

            return;
        case 'run':
            projectRequireExecutable('rr');
            projectRun(['rr', 'serve', '-c', '.rr.yaml', ...$arguments], $root);

            return;
        case 'perf:prepare':
        case 'perf:cleanup':
            projectRun([PHP_BINARY, $root . '/load-tests/baseline/database.php', $command === 'perf:prepare' ? 'prepare' : 'cleanup', ...$arguments], $root);

            return;
        case 'perf:baseline':
        case 'perf:catalog':
            projectRequireExecutable('k6');
            $scenario = $command === 'perf:catalog' ? 'catalog' : 'baseline';
            projectRun(['k6', 'run', '--no-usage-report', $root . '/load-tests/' . $scenario . '/scenario.js', ...$arguments], $root);

            return;
        case 'build':
            projectCommand('third-party:check');
            projectBuild($arguments[0] ?? 'dev');

            return;
    }

    $parts = explode(':', $command);
    if (count($parts) === 3 && $parts[0] === 'tools' && $parts[1] === 'sql') {
        $tool = $parts[1];
        $operation = $parts[2];
        if ($operation === 'check') {
            projectSequence(['tools:' . $tool . ':lint', 'tools:' . $tool . ':phpstan', 'tools:' . $tool . ':test']);

            return;
        }
        $directory = $root . '/tools/sql-gen';
        $executable = match ($operation) {
            'lint', 'fixcs' => ['php-cs-fixer.php', 'fix', ...($operation === 'lint' ? ['--dry-run'] : [])],
            'phpstan' => ['phpstan.php', 'analyse', '--no-progress', '--memory-limit=256M', '--autoload-file=' . $directory . '/tests/bootstrap.php'],
            'test' => ['phpunit.php', '--colors=never'],
            default => throw new InvalidArgumentException('Unknown tool operation: ' . $command),
        };
        $launcher = array_shift($executable);
        projectRun([PHP_BINARY, $root . '/tools/' . $launcher, ...$executable, ...$arguments], $directory);

        return;
    }

    throw new InvalidArgumentException('Unknown command: ' . $command . '. Run php tools/project.php help.');
}

/** @param list<string> $commands */
function projectSequence(array $commands): void
{
    foreach ($commands as $command) {
        fwrite(STDOUT, '> ' . $command . "\n");
        projectCommand($command);
    }
}

/**
 * @param non-empty-list<string> $command
 * @param array<string, string> $overrides
 */
function projectRun(array $command, string $directory, array $overrides = []): void
{
    $environment = getenv();
    $process = proc_open(
        $command,
        [STDIN, STDOUT, STDERR],
        $pipes,
        $directory,
        array_replace($environment, $overrides),
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ' . $command[0]);
    }
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(basename($command[0]) . ' exited with status ' . $status . '.');
    }
}

/** @return array<string, string> */
function projectTestEnvironment(): array
{
    $defaults = ['HOST' => 'localhost', 'PORT' => '5432', 'NAME' => 'app_test', 'USER' => 'app', 'PASSWORD' => 'password'];
    $environment = [];
    foreach ($defaults as $name => $default) {
        $value = getenv('TEST_DB_' . $name);
        $environment['TEST_DB_' . $name] = $value !== false && ($value !== '' || $name === 'PASSWORD') ? $value : $default;
    }

    return $environment;
}

function projectDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }
}

function projectRequireExecutable(string $name): void
{
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        if ($directory !== '' && is_executable($directory . DIRECTORY_SEPARATOR . $name)) {
            return;
        }
    }

    throw new RuntimeException('Required platform executable is not on PATH: ' . $name);
}

function projectRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Could not read directory entry: ' . $directory);
        }
        if ($entry->isDir() && !$entry->isLink()) {
            projectRemoveDirectory($entry->getPathname());
        } elseif (!unlink($entry->getPathname())) {
            throw new RuntimeException('Could not remove: ' . $entry->getPathname());
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Could not remove directory: ' . $directory);
    }
}

function projectGzip(string $source, string $destination): void
{
    $input = fopen($source, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not read release archive: ' . $source);
    }
    $output = gzopen($destination, 'wb6');
    if ($output === false) {
        fclose($input);

        throw new RuntimeException('Could not compress release archive: ' . $destination);
    }

    try {
        while (!feof($input)) {
            $buffer = fread($input, 1024 * 1024);
            if ($buffer === false || ($buffer !== '' && gzwrite($output, $buffer) !== strlen($buffer))) {
                throw new RuntimeException('Could not compress release archive: ' . $destination);
            }
        }
    } finally {
        fclose($input);
        gzclose($output);
    }
}

function projectBuild(string $version): void
{
    if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $version) !== 1) {
        throw new InvalidArgumentException('The build version must contain only letters, digits, dots, underscores and hyphens.');
    }
    $root = dirname(__DIR__);
    projectDirectory($root . '/dist');
    projectDirectory($root . '/var');
    $temporary = $root . '/var/release-' . bin2hex(random_bytes(8));
    projectDirectory($temporary);
    $tarPath = $temporary . '/application.tar';

    try {
        $archive = new PharData($tarPath);
        foreach ([
            'bootstrap.php', 'bootstrap', 'src', 'gen', 'config', 'public', 'bin',
            'third_party/runtime', 'docs', 'README.md',
            'examples/document-catalog',
            '.rr.yaml',
        ] as $relative) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new RuntimeException('Missing runtime release input: ' . $relative);
            }
            if (is_file($path)) {
                $archive->addFile($path, $relative);

                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo) {
                    throw new RuntimeException('Could not read release input: ' . $relative);
                }
                if ($file->isFile() && !$file->isLink()) {
                    $archive->addFile($file->getPathname(), substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        foreach (glob($root . '/third_party/*') ?: [] as $notice) {
            if (is_file($notice) && basename($notice) !== 'manifest.json') {
                $archive->addFile($notice, 'third_party/' . basename($notice));
            }
        }
        $inventoryContents = file_get_contents($root . '/third_party/manifest.json');
        if ($inventoryContents === false) {
            throw new RuntimeException('Could not read release dependency inventory.');
        }
        $inventory = json_decode($inventoryContents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($inventory) || !is_array($inventory['distributions'] ?? null)) {
            throw new RuntimeException('Invalid release dependency inventory.');
        }
        $inventory['distributions'] = array_values(array_filter(
            $inventory['distributions'],
            static fn(mixed $distribution): bool => is_array($distribution) && ($distribution['manifest'] ?? null) === 'runtime/manifest.json',
        ));
        if (count($inventory['distributions']) !== 1) {
            throw new RuntimeException('The release inventory must contain the runtime distribution.');
        }
        $archive->addFromString('third_party/manifest.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        foreach (['LICENSE*', 'NOTICE*', 'COPYING*'] as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $notice) {
                if (is_file($notice)) {
                    $archive->addFile($notice, basename($notice));
                }
            }
        }
        $archive->addEmptyDir('var');
        unset($archive);
        projectGzip($tarPath, $tarPath . '.gz');
        $output = $root . '/dist/zerno-' . $version . '.tar.gz';
        if (!rename($tarPath . '.gz', $output)) {
            throw new RuntimeException('Could not publish release archive: ' . $output);
        }
        fwrite(STDOUT, $output . "\n");
    } finally {
        projectRemoveDirectory($temporary);
    }
}

try {
    $arguments = $argv ?? [];
    projectCommand($arguments[1] ?? 'help', array_slice($arguments, 2));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
