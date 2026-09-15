<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use WordPress\Reprint\Build\Rector\DowngradeArrayDestructuringRector;
use WordPress\Reprint\Build\Rector\DowngradeClassConstantVisibilityRector;
use WordPress\Reprint\Build\Rector\DowngradeFunctionTypeDeclarationsRector;

require_once __DIR__ . '/vendor/autoload.php';

// PHP 7.0 target: null coalescing is native there, so that rule is left out.
return RectorConfig::configure()
    ->withoutParallel()
    ->withPhpVersion(PhpVersion::PHP_70)
    ->withRules([
        DowngradeFunctionTypeDeclarationsRector::class,
        DowngradeClassConstantVisibilityRector::class,
        DowngradeArrayDestructuringRector::class,
    ]);
