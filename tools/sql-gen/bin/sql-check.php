#!/usr/bin/env php
<?php

declare(strict_types=1);

use SqlGen\Application\SqlGenerationService;
use SqlGen\Check\GeneratedOutputChecker;
use SqlGen\Config\DefaultSqlGenerationProfile;
use SqlGen\Config\GeneratorConfig;
use SqlGen\Config\SqlGenerationProfileResolver;

require_once __DIR__ . '/../bootstrap.php';

$options = getopt('', ['input-dir:', 'output-dir:', 'namespace:', 'schema:', 'runtime-namespace::', 'bootstrap::', 'profile-class::']);

if (!is_array($options)) {
    fwrite(STDERR, "Failed to read options.\n");
    exit(1);
}

$inputDir = $options['input-dir'] ?? null;
$outputDir = $options['output-dir'] ?? null;
$namespace = $options['namespace'] ?? null;
$schemaPath = $options['schema'] ?? null;
$runtimeNamespace = $options['runtime-namespace'] ?? 'App\Platform\Storage\Sql';
$bootstrap = $options['bootstrap'] ?? null;
$profileClass = $options['profile-class'] ?? null;

if (
    !is_string($inputDir)
    || !is_string($outputDir)
    || !is_string($namespace)
    || !is_string($schemaPath)
    || !is_string($runtimeNamespace)
    || ($bootstrap !== null && !is_string($bootstrap))
    || ($profileClass !== null && !is_string($profileClass))
) {
    fwrite(
        STDERR,
        "Usage: sql-check.php --input-dir=sql/queries --output-dir=gen/Generated/Sql --namespace=App\\\\Generated\\\\Sql --schema=sql/schema.sql [--runtime-namespace=App\\\\Platform\\\\Storage\\\\Sql] [--bootstrap=codegen/sql-gen-bootstrap.php --profile-class=ProjectCodegen\\\\Sql\\\\BaseApiTemplateSqlGenerationProfile]\n",
    );
    exit(1);
}

if ($bootstrap !== null && $bootstrap !== '') {
    require_once $bootstrap;
}

$profile = $profileClass !== null && $profileClass !== ''
    ? (new SqlGenerationProfileResolver())->resolve($profileClass)
    : DefaultSqlGenerationProfile::withRuntimeNamespace($runtimeNamespace);

$config = new GeneratorConfig(
    inputDir: $inputDir,
    outputDir: $outputDir,
    namespace: $namespace,
    schemaPath: $schemaPath,
    profile: $profile,
);

try {
    $generationService = new SqlGenerationService();
    $checker = new GeneratedOutputChecker();
    $checker->assertSynchronized($config->outputDir, $generationService->generate($config));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
