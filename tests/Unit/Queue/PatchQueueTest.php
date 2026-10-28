<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Queue;

use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Queue\Source\SourceResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchQueue::class)]
#[CoversClass(QueueEntry::class)]
#[CoversClass(SourceResult::class)]
final class PatchQueueTest extends TestCase
{
    public function test_it_queues_cloud_entries_before_hotfix_entries_and_numbers_them(): void
    {
        $queue = $this->queue();

        self::assertSame(['c1', 'c2', 'h1'], array_map(static fn ($e) => $e->path, $queue->entries));
        self::assertSame([1, 2, 3], array_map(static fn ($e) => $e->position, $queue->entries));
        self::assertSame(['w1', 'w2'], $queue->warnings);
    }

    public function test_it_finds_entries_and_the_first_position_by_sha256(): void
    {
        $queue = $this->queue();

        self::assertSame(['c1', 'h1'], array_map(static fn ($e) => $e->path, $queue->entriesWithSha('same')));
        self::assertSame(1, $queue->positionOf('same'));
        self::assertNull($queue->positionOf('unknown'));
        self::assertSame([], $queue->entriesWithSha('unknown'));
    }

    private function queue(): PatchQueue
    {
        return PatchQueue::fromSources(
            new SourceResult([
                new QueueEntry(0, QueueOrigin::CloudRequired, 'T', 'c1', 'same'),
                new QueueEntry(0, QueueOrigin::CloudRequired, 'T2', 'c2', 'other'),
            ], ['w1']),
            new SourceResult([new QueueEntry(0, QueueOrigin::Hotfix, null, 'h1', 'same')], ['w2']),
        );
    }
}
