<?php

declare(strict_types=1);

namespace Patchline;

final class PackageVersion
{
    public const VERSION = '0.1.0-dev';

    public static function current(): string
    {
        return self::VERSION;
    }
}
