<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Sync;

use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Registry\RegistryPatch;
use Patchline\Sync\Action\Action;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
use Patchline\Sync\Action\Warn;
use Patchline\Sync\ActionOutcome;
use Patchline\Sync\ActionStatus;
use Patchline\Sync\ExecutionResult;
use Patchline\Sync\Executor;
use Patchline\Sync\Plan;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\ForbiddenFilesystem;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Executor::class)]
#[CoversClass(ExecutionResult::class)]
#[CoversClass(ActionOutcome::class)]
#[CoversClass(ActionStatus::class)]
#[UsesClass(DownloadResult::class)]
#[UsesClass(PatchDownloader::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(Download::class)]
#[UsesClass(Remove::class)]
#[UsesClass(Rename::class)]
#[UsesClass(Warn::class)]
#[UsesClass(Plan::class)]
final class ExecutorTest extends TestCase
{
    private const BASE_URL = 'https://registry.test/patch';

    private TempDirectory $store;
    private TempDirectory $cache;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->store = TempDirectory::create();
        $this->cache = TempDirectory::create();
        $this->http = new FakeHttpClient();
    }

    protected function tearDown(): void
    {
        $this->store->remove();
        $this->cache->remove();
    }

    public function test_it_writes_a_downloaded_patch_to_its_target_path(): void
    {
        $patch = $this->servedPatch('A', "diff A\n");

        $result = $this->executor()->execute(new Plan([new Download($patch, 'm2-hotfixes/A.patch')], []));

        self::assertSame("diff A\n", file_get_contents($this->store->path('m2-hotfixes/A.patch')));
        self::assertSame([ActionStatus::Done], $this->statuses($result));
        self::assertSame('downloaded A to m2-hotfixes/A.patch', $result->outcomes[0]->message);
        self::assertFalse($result->hasFailures());
    }

    public function test_it_creates_the_m2_hotfixes_directory_when_it_does_not_exist(): void
    {
        $patch = $this->servedPatch('A', "diff A\n");
        self::assertDirectoryDoesNotExist($this->store->path('m2-hotfixes'));

        $this->executor()->execute(new Plan([new Download($patch, 'm2-hotfixes/A.patch')], []));

        self::assertDirectoryExists($this->store->path('m2-hotfixes'));
    }

    public function test_it_leaves_an_existing_target_untouched_and_reports_a_failure(): void
    {
        $patch = $this->servedPatch('A', "diff A\n");
        $this->put('m2-hotfixes/A.patch', 'local edit');

        $result = $this->executor()->execute(new Plan([new Download($patch, 'm2-hotfixes/A.patch')], []));

        self::assertSame('local edit', file_get_contents($this->store->path('m2-hotfixes/A.patch')));
        self::assertTrue($result->hasFailures());
        self::assertSame(
            'm2-hotfixes/A.patch exists with different content, left untouched',
            $result->outcomes[0]->message,
        );
    }

    public function test_it_does_not_write_anything_when_the_downloaded_sha256_does_not_match(): void
    {
        $patch = $this->patch('A', "expected\n");
        $this->http->respond(self::BASE_URL . '/A.diff', 200, "tampered\n");

        $result = $this->executor()->execute(new Plan([new Download($patch, 'm2-hotfixes/A.patch')], []));

        self::assertFileDoesNotExist($this->store->path('m2-hotfixes/A.patch'));
        self::assertTrue($result->hasFailures());
        self::assertStringContainsString('sha256 mismatch', $result->outcomes[0]->message);
    }

    public function test_it_does_not_remove_a_file_whose_content_changed_since_planning(): void
    {
        $this->put('m2-hotfixes/old.patch', 'changed');

        $result = $this->executor()->execute(new Plan([
            new Remove('m2-hotfixes/old.patch', hash('sha256', 'original')),
        ], []));

        self::assertFileExists($this->store->path('m2-hotfixes/old.patch'));
        self::assertTrue($result->hasFailures());
        self::assertSame(
            'm2-hotfixes/old.patch changed since planning, left untouched',
            $result->outcomes[0]->message,
        );
    }

    public function test_it_removes_a_file_whose_content_is_unchanged(): void
    {
        $this->put('m2-hotfixes/old.patch', 'original');

        $result = $this->executor()->execute(new Plan([
            new Remove('m2-hotfixes/old.patch', hash('sha256', 'original')),
        ], []));

        self::assertFileDoesNotExist($this->store->path('m2-hotfixes/old.patch'));
        self::assertSame([ActionStatus::Done], $this->statuses($result));
    }

    public function test_it_renames_a_file_whose_content_is_unchanged(): void
    {
        $this->put('m2-hotfixes/a.patch', 'body');

        $result = $this->executor()->execute(new Plan([
            new Rename('m2-hotfixes/a.patch', 'm2-hotfixes/b.patch', hash('sha256', 'body')),
        ], []));

        self::assertFileDoesNotExist($this->store->path('m2-hotfixes/a.patch'));
        self::assertSame('body', file_get_contents($this->store->path('m2-hotfixes/b.patch')));
        self::assertSame([ActionStatus::Done], $this->statuses($result));
    }

    public function test_it_does_not_rename_onto_an_existing_file_or_a_changed_source(): void
    {
        $this->put('m2-hotfixes/a.patch', 'body');
        $this->put('m2-hotfixes/b.patch', 'other');
        $this->put('m2-hotfixes/c.patch', 'edited');

        $result = $this->executor()->execute(new Plan([
            new Rename('m2-hotfixes/a.patch', 'm2-hotfixes/b.patch', hash('sha256', 'body')),
            new Rename('m2-hotfixes/c.patch', 'm2-hotfixes/d.patch', hash('sha256', 'original')),
        ], []));

        self::assertSame('other', file_get_contents($this->store->path('m2-hotfixes/b.patch')));
        self::assertFileDoesNotExist($this->store->path('m2-hotfixes/d.patch'));
        self::assertSame([ActionStatus::Failed, ActionStatus::Failed], $this->statuses($result));
        self::assertSame('m2-hotfixes/b.patch already exists, left untouched', $result->outcomes[0]->message);
        self::assertSame('m2-hotfixes/c.patch changed since planning, left untouched', $result->outcomes[1]->message);
    }

    public function test_it_fails_when_the_file_to_remove_or_rename_is_gone(): void
    {
        $result = $this->executor()->execute(new Plan([
            new Remove('m2-hotfixes/x.patch', 'abc'),
            new Rename('m2-hotfixes/y.patch', 'm2-hotfixes/z.patch', 'abc'),
        ], []));

        self::assertSame([ActionStatus::Failed, ActionStatus::Failed], $this->statuses($result));
        self::assertSame('m2-hotfixes/x.patch no longer exists', $result->outcomes[0]->message);
        self::assertSame('m2-hotfixes/y.patch no longer exists', $result->outcomes[1]->message);
    }

    public function test_it_skips_a_download_whose_target_already_has_the_same_content(): void
    {
        $patch = $this->servedPatch('A', "diff A\n");
        $this->put('m2-hotfixes/A.patch', "diff A\n");

        $result = $this->executor()->execute(new Plan([new Download($patch, 'm2-hotfixes/A.patch')], []));

        self::assertSame([ActionStatus::Skipped], $this->statuses($result));
        self::assertSame('m2-hotfixes/A.patch already has this content', $result->outcomes[0]->message);
        self::assertFalse($result->hasFailures());
    }

    public function test_it_reports_warnings_without_executing_them(): void
    {
        $result = $this->executor()->execute(new Plan([new Warn('careful', 'A')], []));

        self::assertSame([ActionStatus::Warning], $this->statuses($result));
        self::assertSame('careful', $result->outcomes[0]->message);
        self::assertFalse($result->hasFailures());
    }

    public function test_it_continues_with_remaining_actions_after_a_failed_action(): void
    {
        $bad = $this->patch('A', "expected\n");
        $this->http->fail(self::BASE_URL . '/A.diff');
        $good = $this->servedPatch('B', "diff B\n");

        $result = $this->executor()->execute(new Plan([
            new Download($bad, 'm2-hotfixes/A.patch'),
            new Download($good, 'm2-hotfixes/B.patch'),
        ], []));

        self::assertSame([ActionStatus::Failed, ActionStatus::Done], $this->statuses($result));
        self::assertFileExists($this->store->path('m2-hotfixes/B.patch'));
        self::assertTrue($result->hasFailures());
    }

    public function test_it_turns_a_filesystem_failure_into_a_failed_outcome(): void
    {
        $patch = $this->servedPatch('A', "diff A\n");
        $this->put('blocker', 'a file where a directory is needed');

        $result = $this->executor()->execute(new Plan([new Download($patch, 'blocker/A.patch')], []));

        self::assertSame([ActionStatus::Failed], $this->statuses($result));
    }

    public function test_it_fails_an_action_it_does_not_know_how_to_execute(): void
    {
        $unknown = new class () implements Action {
            public function describe(): string
            {
                return 'reformat the disk';
            }

            public function toArray(): array
            {
                return [];
            }
        };

        $result = $this->executor()->execute(new Plan([$unknown], []));

        self::assertSame([ActionStatus::Failed], $this->statuses($result));
        self::assertSame('unsupported action: reformat the disk', $result->outcomes[0]->message);
    }

    public function test_it_makes_no_filesystem_or_network_calls_in_dry_run_mode(): void
    {
        $patch = $this->patch('A', "diff A\n");
        $executor = new Executor(
            new ForbiddenFilesystem(),
            new PatchDownloader($this->http, new ForbiddenFilesystem(), self::BASE_URL, $this->cache->path()),
            $this->store->path(),
        );

        $result = $executor->execute(new Plan([
            new Remove('m2-hotfixes/old.patch', 'abc'),
            new Rename('m2-hotfixes/a.patch', 'm2-hotfixes/b.patch', 'abc'),
            new Download($patch, 'm2-hotfixes/A.patch'),
            new Warn('careful', null),
        ], []), true);

        self::assertSame([], $this->http->requests);
        self::assertSame(
            [ActionStatus::Planned, ActionStatus::Planned, ActionStatus::Planned, ActionStatus::Warning],
            $this->statuses($result),
        );
        self::assertSame('would download A to m2-hotfixes/A.patch', $result->outcomes[2]->message);
        self::assertFalse($result->hasFailures());
    }

    private function executor(): Executor
    {
        $filesystem = new LocalFilesystem();

        return new Executor(
            $filesystem,
            new PatchDownloader($this->http, $filesystem, self::BASE_URL, $this->cache->path()),
            $this->store->path(),
        );
    }

    private function patch(string $id, string $content): RegistryPatch
    {
        return new RegistryPatch(
            $id,
            ['2.4.9'],
            'ce',
            [],
            [],
            new \DateTimeImmutable('2026-01-01'),
            $id . '.diff',
            hash('sha256', $content),
        );
    }

    private function servedPatch(string $id, string $content): RegistryPatch
    {
        $this->http->respond(self::BASE_URL . '/' . $id . '.diff', 200, $content);

        return $this->patch($id, $content);
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->store->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /** @return list<ActionStatus> */
    private function statuses(ExecutionResult $result): array
    {
        return array_map(static fn (ActionOutcome $outcome): ActionStatus => $outcome->status, $result->outcomes);
    }
}
