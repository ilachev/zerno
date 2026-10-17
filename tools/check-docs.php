#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function docsObject(mixed $value, string $context): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException('Expected an object: ' . $context);
    }
    $object = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new RuntimeException('Expected string keys: ' . $context);
        }
        $object[$key] = $item;
    }

    return $object;
}

function docsString(mixed $value, string $context): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Expected a non-empty string: ' . $context);
    }

    return $value;
}

/** @return list<mixed> */
function docsList(mixed $value, string $context): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException('Expected a list: ' . $context);
    }

    return $value;
}

function docsInsideRoot(string $root, string $path, string $context): string
{
    $resolved = realpath($path);
    if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
        throw new RuntimeException('Missing target or path outside the repository: ' . $context);
    }

    return $resolved;
}

function docsInventoryPath(string $root, mixed $value, string $context): string
{
    $path = docsString($value, $context);
    if (preg_match('~\A[A-Za-z]:|[\\\\\x00-\x1F]|\A/~', $path) === 1
        || array_intersect(['', '.', '..'], explode('/', $path)) !== []) {
        throw new RuntimeException('Expected a safe root-relative path: ' . $context . ' (' . $path . ')');
    }
    docsInsideRoot($root, $root . '/' . $path, $context . ' (' . $path . ')');

    return $path;
}

function docsRead(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read: ' . $path);
    }

    return $contents;
}

function docsWithoutFences(string $markdown): string
{
    $character = '';
    $length = 0;
    $lines = explode("\n", $markdown);
    foreach ($lines as &$line) {
        if ($character !== '') {
            if (preg_match('/\A {0,3}' . preg_quote($character, '/') . '{' . $length . ',}[ \t]*\r?\z/', $line) === 1) {
                $character = '';
            }
            $line = '';
        } elseif (preg_match('/\A {0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
            $character = $match[1][0];
            $length = strlen($match[1]);
            $line = '';
        }
    }
    unset($line);

    return implode("\n", $lines);
}

/** @return list<string> */
function docsHeadingSlugs(string $path): array
{
    $headings = [];
    $previous = '';
    foreach (explode("\n", docsWithoutFences(docsRead($path))) as $line) {
        if (preg_match('/\A {0,3}#{1,6}[ \t]+(.+?)\s*#*\s*\z/u', $line, $match) === 1) {
            $headings[] = $match[1];
        } elseif ($previous !== '' && preg_match('/\A {0,3}(?:=+|-+)[ \t]*\r?\z/', $line) === 1) {
            $headings[] = $previous;
        }
        $previous = trim($line);
    }
    $slugs = [];
    foreach ($headings as $heading) {
        $heading = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $heading) ?? $heading;
        $heading = html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', trim($heading));
        if ($slug === null) {
            throw new RuntimeException('Invalid UTF-8 heading: ' . $path);
        }
        $slug = preg_replace('/\s/u', '-', $slug) ?? $slug;
        $candidate = $slug;
        for ($suffix = 1; docsContainsSlug($slugs, $candidate); ++$suffix) {
            $candidate = $slug . '-' . $suffix;
        }
        $slugs[] = $candidate;
    }

    return $slugs;
}

/** @param list<string> $slugs */
function docsContainsSlug(array $slugs, string $fragment): bool
{
    foreach ($slugs as $slug) {
        // Unicode case folding comes from PCRE, without requiring mbstring.
        if (preg_match('/\A' . preg_quote($slug, '/') . '\z/iu', $fragment) === 1) {
            return true;
        }
    }

    return false;
}

function docsCheckLinks(string $root, string $relative): int
{
    $path = $root . '/' . $relative;
    $markdown = docsWithoutFences(docsRead($path));
    $markdown = preg_replace_callback('/(`+).*?\1/s', static fn(array $match): string => str_repeat("\n", substr_count($match[0], "\n")), $markdown) ?? $markdown;
    preg_match_all('~\]\(\s*(?:<([^>\r\n]+)>|((?:[^()\s\\\]|\\\.|\([^()\r\n]*\))+))~u', $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $count = 0;
    foreach ($matches as $match) {
        $target = ($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? '');
        $target = html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~\A(?:https?://|mailto:)~i', $target) === 1) {
            continue;
        }
        $context = $relative . ':' . (substr_count(substr($markdown, 0, $match[0][1]), "\n") + 1) . ' (' . $target . ')';
        if (preg_match('~\A(?:/|[A-Za-z][A-Za-z0-9+.-]*:)~', $target) === 1) {
            throw new RuntimeException('Absolute local or unsupported link: ' . $context);
        }
        [$destination, $fragment] = array_pad(explode('#', $target, 2), 2, '');
        $destination = rawurldecode(explode('?', $destination, 2)[0]);
        $destination = preg_replace('/\\\([() ])/', '$1', $destination) ?? $destination;
        if (str_contains($destination, "\0") || str_contains($destination, '\\') || str_starts_with($destination, '/')) {
            throw new RuntimeException('Invalid local link: ' . $context);
        }
        $resolved = docsInsideRoot($root, $destination === '' ? $path : dirname($path) . '/' . $destination, $context);
        if ($fragment !== '' && strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'md'
            && !docsContainsSlug(docsHeadingSlugs($resolved), rawurldecode($fragment))) {
            throw new RuntimeException('Missing Markdown heading: ' . $context);
        }
        ++$count;
    }

    return $count;
}

/** @return list<string> */
function docsRequiredPaths(string $root): array
{
    $paths = ['README.md', 'AGENTS.md', 'CLAUDE.md', 'tools/sql-gen/README.md', 'tools/protoc-php-gen/README.md', 'third_party/README.md'];
    foreach (['docs', 'tools', 'examples'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()
                && ($directory === 'docs' ? $file->getExtension() === 'md' : $file->getFilename() === 'README.md')) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

try {
    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        throw new RuntimeException('Cannot resolve the repository root.');
    }
    $inventoryPath = 'docs/documentation-inventory.json';
    $inventory = docsObject(json_decode(docsRead($root . '/' . $inventoryPath), true, 512, JSON_THROW_ON_ERROR), $inventoryPath);
    if (($inventory['schema_version'] ?? null) !== 1) {
        throw new RuntimeException('Unsupported documentation inventory schema_version; expected 1.');
    }
    $reviewedAt = docsString($inventory['reviewed_at'] ?? null, 'reviewed_at');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewedAt);
    if ($date === false || $date->format('Y-m-d') !== $reviewedAt) {
        throw new RuntimeException('Invalid reviewed_at date; expected YYYY-MM-DD.');
    }
    docsString($inventory['scope'] ?? null, 'scope');
    foreach (docsList($inventory['exclusions'] ?? null, 'exclusions') as $offset => $value) {
        $context = 'exclusions[' . $offset . ']';
        $exclusion = docsObject($value, $context);
        docsString($exclusion['path'] ?? null, $context . '.path');
        docsString($exclusion['reason'] ?? null, $context . '.reason');
    }
    $kinds = ['instructions', 'navigation', 'architecture', 'operations', 'design', 'roadmap', 'tool-guide', 'history', 'reference', 'inventory'];
    $statuses = ['current', 'accepted-plan', 'historical', 'compatibility'];
    $ids = [];
    $paths = [];
    $documents = [];
    $artifacts = [];
    foreach (['documents', 'artifacts'] as $section) {
        foreach (docsList($inventory[$section] ?? null, $section) as $offset => $value) {
            $context = $section . '[' . $offset . ']';
            $entry = docsObject($value, $context);
            $id = docsString($entry['id'] ?? null, $context . '.id');
            $path = docsInventoryPath($root, $entry['path'] ?? null, $context . '.path');
            if (isset($ids[$id]) || isset($paths[$path])) {
                throw new RuntimeException('Duplicate documentation id or path: ' . $id . ' (' . $path . ')');
            }
            if (!is_file($root . '/' . $path)) {
                throw new RuntimeException('Inventory entry must be a file: ' . $path);
            }
            if (!in_array($entry['kind'] ?? null, $kinds, true) || !in_array($entry['status'] ?? null, $statuses, true)) {
                throw new RuntimeException('Invalid kind or status: ' . $context);
            }
            docsString($entry['summary'] ?? null, $context . '.summary');
            $readWhen = docsList($entry['read_when'] ?? null, $context . '.read_when');
            if ($readWhen === []) {
                throw new RuntimeException('read_when must not be empty: ' . $context);
            }
            foreach ($readWhen as $item) {
                docsString($item, $context . '.read_when');
            }
            foreach (docsList($entry['source_paths'] ?? null, $context . '.source_paths') as $sourcePath) {
                docsInventoryPath($root, $sourcePath, $context . '.source_paths');
            }
            $ids[$id] = true;
            $paths[$path] = true;
            if ($section === 'documents') {
                if (pathinfo($path, PATHINFO_EXTENSION) !== 'md') {
                    throw new RuntimeException('Document entry must be Markdown: ' . $path);
                }
                $documents[] = $path;
            } else {
                $artifacts[] = $path;
            }
        }
    }
    if (!in_array($inventoryPath, $artifacts, true)) {
        throw new RuntimeException('The documentation inventory must list itself in artifacts.');
    }
    foreach (docsRequiredPaths($root) as $path) {
        if (!in_array($path, $documents, true)) {
            throw new RuntimeException('Unlisted Markdown document: ' . $path);
        }
    }
    $links = 0;
    foreach ($documents as $path) {
        $links += docsCheckLinks($root, $path);
    }
    fwrite(STDOUT, sprintf("Documentation: %d documents, %d artifacts, %d local links verified.\n", count($documents), count($artifacts), $links));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Documentation check failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
