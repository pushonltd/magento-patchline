<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Update;

use Patchline\Update\UpdateAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateAvailability::class)]
final class UpdateAvailabilityTest extends TestCase
{
    public function test_it_reports_an_update_when_the_latest_version_is_greater_than_the_current_version(): void
    {
        self::assertSame('0.0.10', UpdateAvailability::newerThan('0.0.9', '0.0.10'));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function noUpdate(): array
    {
        return [
            'equal' => ['0.0.2', '0.0.2'],
            'current newer' => ['0.1.0', '0.0.2'],
            'no latest' => ['0.0.2', null],
        ];
    }

    #[DataProvider('noUpdate')]
    public function test_it_reports_no_update_when_the_current_version_is_equal_or_newer(
        string $current,
        ?string $latest,
    ): void {
        self::assertNull(UpdateAvailability::newerThan($current, $latest));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notReleases(): array
    {
        return [
            'dev branch' => ['dev-main@abc1234'],
            'unknown' => ['unknown'],
            'beta' => ['1.0.0-beta1'],
            'no version set' => ['1.0.0+no-version-set'],
            'patch suffix' => ['1.0.0-p1'],
            'garbage' => ['not a version'],
        ];
    }

    #[DataProvider('notReleases')]
    public function test_it_reports_no_update_when_the_current_version_is_not_a_release(string $current): void
    {
        self::assertNull(UpdateAvailability::newerThan($current, '9.9.9'));
    }
}
