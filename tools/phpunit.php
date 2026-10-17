<?php

declare(strict_types=1);

$phar = __DIR__ . '/../third_party/tools/phpunit/phpunit.phar';
$excludedFiles = $GLOBALS['__PHPUNIT_ISOLATION_EXCLUDE_LIST'] ?? [];

if (!is_array($excludedFiles)) {
    $excludedFiles = [];
}

$excludedFiles[] = __FILE__;
$GLOBALS['__PHPUNIT_ISOLATION_EXCLUDE_LIST'] = $excludedFiles;

// The upstream stub distinguishes its CLI entrypoint from library-only loading.
$_SERVER['SCRIPT_NAME'] = $phar;

require $phar;
