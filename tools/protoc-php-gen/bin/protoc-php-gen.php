#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/../bootstrap.php';

use ProtoPhpGen\PhpGeneratorPlugin;

$plugin = new PhpGeneratorPlugin();
exit($plugin->run());
