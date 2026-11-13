<?php

declare(strict_types=1);

namespace SqlGen\Application;

use SqlGen\Config\GeneratorConfig;
use SqlGen\Generator\GeneratedFile;
use SqlGen\Generator\PhpQueryGenerator;
use SqlGen\Parser\SqlFileParser;
use SqlGen\Schema\DatabaseSchemaParser;
use SqlGen\Schema\SqlSchemaParser;

final readonly class SqlGenerationService
{
    public function __construct(
        private DatabaseSchemaParser $sqlSchemaParser = new SqlSchemaParser(),
    ) {}

    /**
     * @return list<GeneratedFile>
     */
    public function generate(GeneratorConfig $config): array
    {
        if (!is_dir($config->inputDir)) {
            throw new \RuntimeException('SQL input directory does not exist: ' . $config->inputDir);
        }
        $schema = $this->sqlSchemaParser->parseFile($config->schemaPath);
        $generator = new PhpQueryGenerator($config, $schema);
        $sqlFileParser = new SqlFileParser($config->profile->artifactNaming());
        $files = glob(rtrim($config->inputDir, '/') . '/*.sql');
        if (!is_array($files) || $files === []) {
            throw new \RuntimeException("Failed to list SQL files in {$config->inputDir}");
        }
        $files = array_values(array_filter($files, is_file(...)));
        if ($files === []) {
            throw new \RuntimeException("No SQL source files in {$config->inputDir}");
        }

        sort($files);

        $generatedFiles = [];
        $moduleSources = [];
        $outputSources = [];

        foreach ($files as $file) {
            $sqlFile = $sqlFileParser->parseFile($file);
            $moduleKey = strtolower($sqlFile->moduleName);
            if (isset($moduleSources[$moduleKey])) {
                throw new \RuntimeException("SQL module {$sqlFile->moduleName} from {$file} collides with {$moduleSources[$moduleKey]}");
            }
            $moduleSources[$moduleKey] = $file;

            foreach ($generator->generateForSqlFile($sqlFile) as $generatedFile) {
                $pathKey = strtolower(str_replace('\\', '/', $generatedFile->path));
                if (isset($outputSources[$pathKey])) {
                    throw new \RuntimeException("Generated PHP path {$generatedFile->path} from {$file} collides with {$outputSources[$pathKey]}");
                }
                $outputSources[$pathKey] = $file;
                $generatedFiles[] = $generatedFile;
            }
        }

        usort(
            $generatedFiles,
            static fn(GeneratedFile $left, GeneratedFile $right): int => $left->path <=> $right->path,
        );

        return $generatedFiles;
    }
}
