<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use LogicException;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SystemClock;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FakeProcessRunner;
use Patchline\Tests\Double\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(ProcessResult::class)]
final class DoublesTest extends TestCase
{
    public function test_it_reads_the_system_clock(): void
    {
        self::assertEqualsWithDelta(time(), (new SystemClock())->now()->getTimestamp(), 5);
    }

    public function test_it_serves_canned_http_responses_and_logs_requests(): void
    {
        $http = (new FakeHttpClient())->respond('https://a/x', 200, 'body')->fail('https://down');

        self::assertSame('body', $http->get('https://a/x', ['X' => 'y'])->body);
        self::assertSame(404, $http->get('https://a/none')->status);
        self::assertCount(2, $http->requests);
        $this->expectException(RuntimeFailure::class);
        $http->get('https://down');
    }

    public function test_it_replays_queued_process_results(): void
    {
        $runner = (new FakeProcessRunner())
            ->enqueue(new ProcessResult(0, 'out', ''))
            ->enqueue(static fn (array $c): ProcessResult => new ProcessResult(1, '', $c[0]));

        self::assertSame('out', $runner->run(['a'])->stdout);
        self::assertSame('b', $runner->run(['b'], 'in', '/tmp')->stderr);
        $this->expectException(LogicException::class);
        $runner->run(['c']);
    }

    public function test_it_freezes_time(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01'));
        $clock->advanceTo(new DateTimeImmutable('2026-02-01'));

        self::assertSame('2026-02-01', $clock->now()->format('Y-m-d'));
    }
}
