<?php

declare(strict_types=1);

return [
    'prefixes' => [
        'GPBMetadata\\' => [
            __DIR__ . '/packages/roadrunner-php/roadrunner-api-dto/generated/GPBMetadata',
        ],
        'GPBMetadata\\Google\\Protobuf\\' => [
            __DIR__ . '/packages/google/protobuf/src/GPBMetadata/Google/Protobuf',
        ],
        'Google\\Protobuf\\' => [
            __DIR__ . '/packages/google/protobuf/src/Google/Protobuf',
        ],
        'Nyholm\\Psr7\\' => [
            __DIR__ . '/packages/nyholm/psr7/src',
        ],
        'Psr\\Http\\Message\\' => [
            __DIR__ . '/packages/psr/http-factory/src',
            __DIR__ . '/packages/psr/http-message/src',
        ],
        'Psr\\Log\\' => [
            __DIR__ . '/packages/psr/log/src',
        ],
        'RoadRunner\\' => [
            __DIR__ . '/packages/roadrunner-php/roadrunner-api-dto/generated/RoadRunner',
        ],
        'Spiral\\Goridge\\' => [
            __DIR__ . '/packages/spiral/goridge/src',
        ],
        'Spiral\\RoadRunner\\' => [
            __DIR__ . '/packages/spiral/roadrunner-worker/src',
        ],
        'Spiral\\RoadRunner\\Http\\' => [
            __DIR__ . '/packages/spiral/roadrunner-http/src',
        ],
        'Symfony\\Polyfill\\Php83\\' => [
            __DIR__ . '/packages/symfony/polyfill-php83',
        ],
        'Temporal\\' => [
            __DIR__ . '/packages/roadrunner-php/roadrunner-api-dto/generated/Temporal',
        ],
    ],
    'classmap' => [
        'DateError' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateError.php',
        'DateException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateException.php',
        'DateInvalidOperationException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateInvalidOperationException.php',
        'DateInvalidTimeZoneException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateInvalidTimeZoneException.php',
        'DateMalformedIntervalStringException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateMalformedIntervalStringException.php',
        'DateMalformedPeriodStringException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateMalformedPeriodStringException.php',
        'DateMalformedStringException' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateMalformedStringException.php',
        'DateObjectError' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateObjectError.php',
        'DateRangeError' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/DateRangeError.php',
        'Override' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/Override.php',
        'SQLite3Exception' => __DIR__ . '/packages/symfony/polyfill-php83/Resources/stubs/SQLite3Exception.php',
    ],
    'files' => [
        __DIR__ . '/packages/symfony/polyfill-php83/bootstrap.php',
    ],
];
