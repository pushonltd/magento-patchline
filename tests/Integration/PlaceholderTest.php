<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PlaceholderTest extends TestCase
{
    public function test_it_has_an_integration_suite(): void
    {
        self::assertDirectoryExists(__DIR__);
    }
}
