<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit;

use Patchline\PackageVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageVersion::class)]
final class PackageVersionTest extends TestCase
{
    public function test_it_reports_the_package_version(): void
    {
        self::assertSame(PackageVersion::VERSION, PackageVersion::current());
        self::assertSame('0.1.0-dev', PackageVersion::current());
    }
}
