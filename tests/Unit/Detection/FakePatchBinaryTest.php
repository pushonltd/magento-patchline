<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Detection;

use Patchline\Tests\Double\FakePatchBinary;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakePatchBinaryTest extends TestCase
{
    public function test_it_answers_by_diff_and_direction_and_records_calls(): void
    {
        $fake = FakePatchBinary::byDiff(['A' => ['forward' => false, 'reverse' => true]]);

        self::assertTrue($fake->dryRun('A', '/w', true)->succeeded);
        self::assertFalse($fake->dryRun('A', '/w', false)->succeeded);
        self::assertFalse($fake->apply('B', '/x', false)->succeeded);
        self::assertCount(3, $fake->calls());
        self::assertTrue($fake->calls()[0]->dryRun);
        self::assertFalse($fake->calls()[2]->dryRun);
        self::assertSame('/x', $fake->calls()[2]->workDir);
    }
}
