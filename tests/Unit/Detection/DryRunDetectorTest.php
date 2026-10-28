<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Detection;

use DateTimeImmutable;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DetectionState;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\DryRunDetector;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchBinary;
use Patchline\Detection\PatchRun;
use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FakePatchBinary;
use Patchline\Tests\Double\PatchCall;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Double\TemporaryDirectoryTrackingFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DryRunDetector::class)]
#[CoversClass(DetectionResult::class)]
#[CoversClass(DetectionResults::class)]
#[CoversClass(DetectionState::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(DownloadResult::class)]
#[UsesClass(PatchDownloader::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(Installation::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class DryRunDetectorTest extends TestCase
{
    private const BASE = 'https://registry.test/patch';

    private TempDirectory $store;
    private TempDirectory $cache;
    private FakeHttpClient $http;

    /** @var array<string, string> */
    private array $diffs = [];

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

    public function test_it_classifies_a_patch_as_applied_when_forward_fails_and_reverse_succeeds(): void
    {
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $binary = FakePatchBinary::byDiff([$this->text($a) => ['forward' => false, 'reverse' => true]]);

        $results = $this->detect([$a], $binary);

        self::assertSame(DetectionState::Applied, $results->get('999-A')->state);
    }

    public function test_it_classifies_a_patch_as_not_applied_when_forward_succeeds_and_reverse_fails(): void
    {
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $binary = FakePatchBinary::byDiff([$this->text($a) => ['forward' => true, 'reverse' => false]]);

        $results = $this->detect([$a], $binary);

        self::assertSame(DetectionState::NotApplied, $results->get('999-A')->state);
    }

    public function test_it_classifies_a_patch_as_unknown_when_both_directions_fail_and_keeps_both_outputs(): void
    {
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $binary = new FakePatchBinary(
            static fn (PatchCall $call): PatchRun => new PatchRun(
                1,
                $call->reverse ? 'reverse output' : 'forward output',
            ),
        );

        $result = $this->detect([$a], $binary)->get('999-A');

        self::assertSame(DetectionState::Unknown, $result->state);
        self::assertSame('forward output', $result->forwardOutput);
        self::assertSame('reverse output', $result->reverseOutput);
    }

    public function test_it_detects_an_older_patch_as_applied_when_a_newer_overlapping_patch_is_applied_on_top(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A']);
        $c = $this->patch('999-C', self::diffFor('f.txt', 'c'), ['999-B']);
        $root = $this->store->path();
        $binary = new FakePatchBinary(function (PatchCall $call) use ($a, $root): PatchRun {
            $inCopy = $call->workDir !== $root;
            if ($call->diff !== $this->text($a)) {
                return new PatchRun($call->reverse && $inCopy ? 0 : 1);
            }

            return new PatchRun($call->reverse === $inCopy ? 0 : 1);
        });

        $results = $this->detect([$a, $b, $c], $binary);

        self::assertSame(DetectionState::Applied, $results->get('999-A')->state);
        $reversals = array_values(array_filter(
            $binary->calls(),
            static fn (PatchCall $call): bool => !$call->dryRun && $call->reverse,
        ));
        self::assertSame(
            [$this->text($c), $this->text($b)],
            array_map(static fn (PatchCall $call): string => $call->diff, array_slice($reversals, 0, 2)),
        );
        self::assertSame("x\n", file_get_contents($this->store->path('f.txt')));
    }

    public function test_it_detects_a_patch_as_not_applied_when_its_overlapping_prerequisite_is_also_missing(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A']);
        $c = $this->patch('999-C', self::diffFor('f.txt', 'c'), ['999-B']);
        $root = $this->store->path();
        $binary = new FakePatchBinary(function (PatchCall $call) use ($c, $root): PatchRun {
            if ($call->diff !== $this->text($c)) {
                return new PatchRun($call->reverse ? 1 : 0);
            }
            $inCopy = $call->workDir !== $root;

            return new PatchRun($inCopy && !$call->reverse ? 0 : 1);
        });

        $results = $this->detect([$c, $b, $a], $binary);

        self::assertSame(DetectionState::NotApplied, $results->get('999-C')->state);
        $applies = array_values(array_filter($binary->calls(), static fn (PatchCall $call): bool => !$call->dryRun));
        self::assertSame(
            [$this->text($a), $this->text($b)],
            array_map(static fn (PatchCall $call): string => $call->diff, array_slice($applies, 0, 2)),
        );
    }

    public function test_it_marks_a_patch_as_not_applicable_when_all_its_sections_target_replaced_packages(): void
    {
        $a = $this->patch('999-A', self::diffFor('vendor/acme/foo/x.php', 'a'));
        $binary = FakePatchBinary::byDiff([]);

        $result = $this->detect([$a], $binary, ['acme/foo'])->get('999-A');

        self::assertSame(DetectionState::NotApplicable, $result->state);
        self::assertSame([], $binary->calls());
    }

    public function test_it_marks_a_patch_as_unknown_with_a_warning_when_its_diff_cannot_be_downloaded(): void
    {
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'), [], false);
        $binary = FakePatchBinary::byDiff([]);

        $result = $this->detect([$a], $binary)->get('999-A');

        self::assertSame(DetectionState::Unknown, $result->state);
        self::assertSame(
            ['The diff of 999-A could not be downloaded (http status 404); its state is unknown.'],
            $result->warnings,
        );
        self::assertSame([], $binary->calls());
    }

    public function test_it_keeps_sections_of_a_replaced_package_whose_files_are_present_and_warns(): void
    {
        mkdir($this->store->path('vendor/acme/foo'), 0777, true);
        file_put_contents($this->store->path('vendor/acme/foo/x.php'), "x\n");
        $present = self::diffFor('vendor/acme/foo/x.php', 'a');
        $a = $this->patch('999-A', $present . self::diffFor('vendor/acme/foo/y.php', 'a'));
        $binary = new FakePatchBinary(static fn (PatchCall $call): PatchRun => new PatchRun($call->reverse ? 1 : 0));

        $result = $this->detect([$a], $binary, ['acme/foo'])->get('999-A');

        self::assertSame(DetectionState::NotApplied, $result->state);
        self::assertSame($present, $binary->calls()[0]->diff);
        self::assertSame(
            [
                'Patch 999-A: files of replaced package(s) acme/foo are present although the package is replaced; '
                . 'checking them anyway.',
            ],
            $result->warnings,
        );
    }

    public function test_it_warns_that_detection_may_be_inaccurate_when_a_descendant_diff_is_unavailable(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A'], false);
        $binary = FakePatchBinary::byDiff([$this->text($a) => ['forward' => false, 'reverse' => true]]);

        $results = $this->detect([$a, $b], $binary);

        self::assertSame(DetectionState::Applied, $results->get('999-A')->state);
        self::assertSame(
            ['Descendant diffs unavailable for 999-B; the dry-run for 999-A may be inaccurate.'],
            $results->get('999-A')->warnings,
        );
    }

    public function test_it_removes_its_temporary_directory_even_when_the_patch_binary_throws(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A']);
        $root = $this->store->path();
        $binary = new FakePatchBinary(static function (PatchCall $call) use ($root): PatchRun {
            if ($call->workDir !== $root) {
                throw new RuntimeFailure('patch exploded');
            }

            return new PatchRun(0);
        });
        $filesystem = new TemporaryDirectoryTrackingFilesystem(new LocalFilesystem());

        try {
            $this->detect([$a, $b], $binary, [], $filesystem);
            self::fail('The failure of the patch binary should reach the caller.');
        } catch (RuntimeFailure) {
            self::assertCount(1, $filesystem->temporaryDirectories());
            self::assertDirectoryDoesNotExist($filesystem->temporaryDirectories()[0]);
        }
    }

    public function test_it_falls_back_to_the_store_and_warns_when_a_later_patch_cannot_be_reversed(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A']);
        $root = $this->store->path();
        $binary = new FakePatchBinary(function (PatchCall $call) use ($root, $b): PatchRun {
            if (!$call->dryRun) {
                return new PatchRun(1);
            }
            if ($call->diff === $this->text($b)) {
                return new PatchRun($call->reverse ? 0 : 1);
            }

            return new PatchRun($call->workDir === $root && !$call->reverse ? 0 : 1);
        });

        $result = $this->detect([$a, $b], $binary)->get('999-A');

        self::assertSame(DetectionState::NotApplied, $result->state);
        self::assertSame(
            ['Could not reverse a later patch while checking 999-A; the result may be inaccurate.'],
            $result->warnings,
        );
    }

    public function test_it_keeps_the_unknown_state_and_warns_when_an_earlier_patch_cannot_be_applied(): void
    {
        file_put_contents($this->store->path('f.txt'), "x\n");
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'));
        $b = $this->patch('999-B', self::diffFor('f.txt', 'b'), ['999-A']);
        $binary = new FakePatchBinary(static fn (PatchCall $call): PatchRun => new PatchRun(1, 'nope'));

        $result = $this->detect([$a, $b], $binary)->get('999-B');

        self::assertSame(DetectionState::Unknown, $result->state);
        self::assertSame('nope', $result->forwardOutput);
        self::assertSame(
            ['Could not apply an earlier patch while checking 999-B; the result may be inaccurate.'],
            $result->warnings,
        );
    }

    public function test_it_lists_all_warnings_of_all_patches_in_order(): void
    {
        $a = $this->patch('999-A', self::diffFor('f.txt', 'a'), [], false);
        $b = $this->patch('999-B', self::diffFor('g.txt', 'b'), [], false);

        $warnings = $this->detect([$a, $b], FakePatchBinary::byDiff([]))->warnings();

        self::assertCount(2, $warnings);
        self::assertStringContainsString('999-A', $warnings[0]);
        self::assertStringContainsString('999-B', $warnings[1]);
    }

    /**
     * @param list<string> $requires
     */
    private function patch(string $id, string $diff, array $requires = [], bool $downloadable = true): RegistryPatch
    {
        $this->diffs[$id] = $diff;
        if ($downloadable) {
            $this->http->respond(self::BASE . '/' . $id . '.diff', 200, $diff);
        }

        return new RegistryPatch(
            $id,
            ['CE'],
            'core',
            $requires,
            [],
            new DateTimeImmutable('2026-01-01'),
            $id . '.diff',
            hash('sha256', $diff),
        );
    }

    private function text(RegistryPatch $patch): string
    {
        return $this->diffs[$patch->id];
    }

    private static function diffFor(string $file, string $marker): string
    {
        return "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -1 +1 @@\n-x\n+{$marker}\n";
    }

    /**
     * @param list<RegistryPatch> $patches
     * @param list<string> $replaced
     */
    private function detect(
        array $patches,
        PatchBinary $binary,
        array $replaced = [],
        ?Filesystem $filesystem = null,
    ): DetectionResults {
        $filesystem ??= new LocalFilesystem();
        $installation = new Installation(
            $this->store->path(),
            [],
            $replaced,
            Edition::OpenSource,
            '9.9.9',
            null,
            false,
        );
        $downloader = new PatchDownloader($this->http, $filesystem, self::BASE, $this->cache->path());
        $ids = array_map(static fn (RegistryPatch $p): string => $p->id, $patches);
        $graph = new DependencyGraph(new Registry('1', self::BASE, [], array_combine($ids, $patches)), $ids);

        return (new DryRunDetector($installation, $downloader, new DiffParser(), $binary, $filesystem))
            ->detect($patches, $graph);
    }
}
