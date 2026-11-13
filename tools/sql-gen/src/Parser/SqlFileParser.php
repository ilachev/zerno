<?php

declare(strict_types=1);

namespace SqlGen\Parser;

use SqlGen\Config\SqlArtifactNaming;
use SqlGen\Model\SqlFile;
use SqlGen\Model\SqlParameter;
use SqlGen\Model\SqlResultKind;
use SqlGen\Model\SqlStatement;

final class SqlFileParser implements NamedSqlFileParser
{
    public function __construct(
        private readonly ?SqlArtifactNaming $artifactNaming = null,
    ) {}

    public function parseFile(string $path): SqlFile
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException("Unable to read SQL file: {$path}");
        }

        $statements = $this->parseStatements($contents, $path);
        if ($statements === []) {
            throw new \RuntimeException("No named SQL statements found in: {$path}");
        }

        return new SqlFile(
            sourcePath: $path,
            moduleName: ($this->artifactNaming ?? new SqlArtifactNaming())->moduleNameFromPath($path),
            statements: $statements,
        );
    }

    /**
     * @return list<SqlParameter>
     */
    private function extractParameters(string $sql): array
    {
        $length = strlen($sql);
        $offset = 0;
        $uniqueNames = [];

        while ($offset < $length) {
            $character = $sql[$offset];

            if ($character === "'" || $character === '"') {
                $offset = $this->skipQuotedLiteral($sql, $offset, $character, $length);
                continue;
            }

            if ($character === '-' && ($sql[$offset + 1] ?? null) === '-') {
                $offset = $this->skipLineComment($sql, $offset + 2, $length);
                continue;
            }

            if (
                $character === ':'
                && ($sql[$offset - 1] ?? null) !== ':'
                && ($sql[$offset + 1] ?? null) !== ':'
            ) {
                $parameterName = $this->readParameterName($sql, $offset + 1, $length);
                if ($parameterName !== null) {
                    $uniqueNames[$parameterName] = true;
                    $offset += strlen($parameterName) + 1;
                    continue;
                }
            }

            $offset++;
        }

        return array_map(
            static fn(string $name): SqlParameter => new SqlParameter($name),
            array_keys($uniqueNames),
        );
    }

    /**
     * @return list<SqlStatement>
     */
    private function parseStatements(string $contents, string $path): array
    {
        $normalizedContents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = explode("\n", $normalizedContents);
        $statements = [];
        $currentName = null;
        $currentKind = null;
        $currentLines = [];
        $names = [];

        foreach ($lines as $line) {
            $header = $this->parseHeaderLine($line);
            if ($header !== null) {
                $nameKey = strtolower($header['name']);
                if (isset($names[$nameKey])) {
                    throw new \RuntimeException("Duplicate SQL statement name {$header['name']} in {$path}");
                }
                $names[$nameKey] = true;
                if ($currentName !== null && $currentKind !== null) {
                    $statements[] = $this->buildStatement($currentName, $currentKind, $currentLines, $path);
                }

                $currentName = $header['name'];
                $currentKind = $header['kind'];
                $currentLines = [];
                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $line;
            }
        }

        if ($currentName !== null && $currentKind !== null) {
            $statements[] = $this->buildStatement($currentName, $currentKind, $currentLines, $path);
        }

        return $statements;
    }

    /**
     * @return array{name: string, kind: string}|null
     */
    private function parseHeaderLine(string $line): ?array
    {
        $trimmed = trim($line);
        if (!str_starts_with($trimmed, '--')) {
            return null;
        }

        $body = ltrim(substr($trimmed, 2));
        if (!str_starts_with($body, 'name:')) {
            return null;
        }

        $declaration = trim(substr($body, strlen('name:')));
        if ($declaration === '') {
            throw new \RuntimeException('Named SQL header is missing statement declaration.');
        }

        $parts = $this->splitByWhitespace($declaration);
        if (count($parts) !== 2) {
            throw new \RuntimeException("Invalid named SQL header: {$line}");
        }

        [$name, $kindDeclaration] = $parts;

        if ($name === '' || !$this->isIdentifierStart($name[0]) || !$this->isIdentifier($name)) {
            throw new \RuntimeException("Invalid SQL statement name in header: {$line}");
        }

        if (!str_starts_with($kindDeclaration, ':')) {
            throw new \RuntimeException("Invalid SQL result kind in header: {$line}");
        }

        $kind = substr($kindDeclaration, 1);
        if (!in_array($kind, ['one', 'many', 'exec'], true)) {
            throw new \RuntimeException("Unsupported SQL result kind in header: {$line}");
        }

        return [
            'name' => $name,
            'kind' => $kind,
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function buildStatement(string $name, string $kind, array $lines, string $path): SqlStatement
    {
        $sql = trim(implode("\n", $lines));
        if ($sql === '') {
            throw new \RuntimeException("SQL statement {$name} in {$path} is empty");
        }

        return new SqlStatement(
            name: $name,
            resultKind: SqlResultKind::fromDeclaration($kind),
            sql: $sql,
            parameters: $this->extractParameters($sql),
        );
    }

    private function skipQuotedLiteral(string $sql, int $offset, string $quote, int $length): int
    {
        $offset++;

        while ($offset < $length) {
            $character = $sql[$offset];

            if ($character === $quote) {
                if ($quote === "'" && ($sql[$offset + 1] ?? null) === "'") {
                    $offset += 2;
                    continue;
                }

                return $offset + 1;
            }

            $offset++;
        }

        return $offset;
    }

    private function skipLineComment(string $sql, int $offset, int $length): int
    {
        while ($offset < $length && $sql[$offset] !== "\n") {
            $offset++;
        }

        return $offset;
    }

    private function readParameterName(string $sql, int $offset, int $length): ?string
    {
        $start = $offset;
        $firstCharacter = $sql[$offset] ?? null;

        if (!is_string($firstCharacter) || !$this->isIdentifierStart($firstCharacter)) {
            return null;
        }

        $offset++;

        while ($offset < $length) {
            $character = $sql[$offset];
            if (!$this->isIdentifierPart($character)) {
                break;
            }

            $offset++;
        }

        return substr($sql, $start, $offset - $start);
    }

    private function isIdentifier(string $value): bool
    {
        $length = strlen($value);
        for ($offset = 1; $offset < $length; $offset++) {
            if (!$this->isIdentifierPart($value[$offset])) {
                return false;
            }
        }

        return true;
    }

    private function isIdentifierStart(string $character): bool
    {
        return ctype_alpha($character) || $character === '_';
    }

    private function isIdentifierPart(string $character): bool
    {
        return ctype_alnum($character) || $character === '_';
    }

    /**
     * @return list<string>
     */
    private function splitByWhitespace(string $value): array
    {
        $parts = [];
        $buffer = '';
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $value[$offset];

            if (ctype_space($character)) {
                if ($buffer !== '') {
                    $parts[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            $buffer .= $character;
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }
}
