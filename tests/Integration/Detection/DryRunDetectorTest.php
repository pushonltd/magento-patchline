<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Detection;

use DateTimeImmutable;
use FilesystemIterator;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DetectionState;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\DryRunDetector;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(DryRunDetector::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(DetectionResults::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(SystemPatchBinary::class)]
#[UsesClass(DownloadResult::class)]
#[UsesClass(PatchDownloader::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(Installation::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class DryRunDetectorTest extends TestCase
{
    private const BASE = 'https://registry.test/patch';
    private const FILE = 'vendor/magento/module-x/f.php';
    private const TOOL = 'vendor/magento/module-x/tool';

    private TempDirectory $store;
    private TempDirectory $cache;

    protected function setUp(): void
    {
        $this->store = TempDirectory::create();
        $this->cache = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->store->remove();
        $this->cache->remove();
    }

    /**
     * @return array<string, array{int, array<string, DetectionState>}>
     */
    public static function chainStates(): array
    {
        $applied = DetectionState::Applied;
        $missing = DetectionState::NotApplied;

        return [
            'nothing applied' => [0, ['999-A' => $missing, '999-B' => $missing, '999-C' => $missing]],
            'only A applied' => [1, ['999-A' => $applied, '999-B' => $missing, '999-C' => $missing]],
            'A and B applied' => [2, ['999-A' => $applied, '999-B' => $applied, '999-C' => $missing]],
            'all applied' => [3, ['999-A' => $applied, '999-B' => $applied, '999-C' => $applied]],
        ];
    }

    /**
     * @param array<string, DetectionState> $expected
     */
    #[DataProvider('chainStates')]
    public function test_it_detects_every_state_of_an_overlapping_chain_with_the_real_patch_binary(
        int $appliedCount,
        array $expected,
    ): void {
        $versions = $this->versions();
        $this->writeStoreFile($versions[$appliedCount]);
        $patches = $this->chain($versions);
        $before = $this->checksums();

        $results = $this->detector($patches)->detect($patches, $this->graph($patches));

        $states = array_map(static fn ($result): DetectionState => $result->state, $results->all());
        self::assertSame($expected, $states);
        self::assertSame($before, $this->checksums());
        self::assertSame([], $results->warnings());
    }

    /**
     * Like Adobe's monthly patches: every patch requires all earlier ones and rewrites a shared tool file, and B
     * touches only that tool file. D cannot be classified on the store (it builds on the missing C), and A only
     * reverses once B is out of the way, so B must take part in the replay although it shares no file with D.
     */
    public function test_it_replays_a_prerequisite_that_only_shares_a_file_with_another_prerequisite(): void
    {
        $versions = $this->versions();
        $this->writeStoreFile($versions[1]);
        file_put_contents($this->store->path(self::TOOL), "tool 2\n");
        $diffs = [
            '999-A' => $this->diff($versions[0], $versions[1]) . $this->toolDiff(0, 1),
            '999-B' => $this->toolDiff(1, 2),
            '999-C' => $this->diff($versions[1], $versions[2]),
            '999-D' => $this->diff($versions[2], $versions[3]),
        ];
        $patches = [];
        $requires = [];
        foreach ($diffs as $id => $diff) {
            $patches[] = $this->registryPatch($id, $diff, $requires);
            $requires[] = $id;
        }

        $results = $this->detector($patches)->detect($patches, $this->graph($patches));

        $states = array_map(static fn ($result): DetectionState => $result->state, $results->all());
        $applied = DetectionState::Applied;
        $missing = DetectionState::NotApplied;
        self::assertSame(['999-A' => $applied, '999-B' => $applied, '999-C' => $missing, '999-D' => $missing], $states);
        self::assertSame([], $results->warnings());
    }

    public function test_it_treats_a_missing_file_as_not_applied_for_a_patch_that_creates_it(): void
    {
        $diff = "diff --git a/new.php b/new.php\n--- /dev/null\n+++ b/new.php\n@@ -0,0 +1 @@\n+hello\n";
        $patch = $this->registryPatch('999-N', $diff, []);

        $results = $this->detector([$patch])->detect([$patch], $this->graph([$patch]));

        self::assertSame(DetectionState::NotApplied, $results->get('999-N')->state);
        self::assertFileDoesNotExist($this->store->path('new.php'));
    }

    /**
     * @return list<list<string>> file contents (lines) after 0, 1, 2 and 3 patches
     */
    private function versions(): array
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = 'line' . $i;
        }
        $versions = [$lines];
        foreach ([1 => 'A', 4 => 'B', 7 => 'C'] as $index => $name) {
            $lines[$index] = 'changed by ' . $name;
            $versions[] = array_values($lines);
        }

        return $versions;
    }

    /**
     * @param list<string> $lines
     */
    private function writeStoreFile(array $lines): void
    {
        $path = $this->store->path(self::FILE);
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param list<list<string>> $versions
     * @return list<RegistryPatch>
     */
    private function chain(array $versions): array
    {
        $patches = [];
        $requires = [];
        foreach (['999-A', '999-B', '999-C'] as $i => $id) {
            $patches[] = $this->registryPatch($id, $this->diff($versions[$i], $versions[$i + 1]), $requires);
            $requires = [$id];
        }

        return $patches;
    }

    /**
     * A one-line change as a unified diff with three lines of context.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private function diff(array $old, array $new): string
    {
        $changed = array_keys(array_diff_assoc($new, $old))[0];
        $start = max(0, $changed - 3);
        $end = min(count($old) - 1, $changed + 3);
        $body = '';
        for ($i = $start; $i <= $end; $i++) {
            $body .= $i === $changed ? '-' . $old[$i] . "\n+" . $new[$i] . "\n" : ' ' . $old[$i] . "\n";
        }
        $length = $end - $start + 1;

        return sprintf(
            "diff --git a/%1\$s b/%1\$s\n--- a/%1\$s\n+++ b/%1\$s\n@@ -%2\$d,%3\$d +%2\$d,%3\$d @@\n%4\$s",
            self::FILE,
            $start + 1,
            $length,
            $body,
        );
    }

    private function toolDiff(int $from, int $to): string
    {
        return sprintf(
            "diff --git a/%1\$s b/%1\$s\n--- a/%1\$s\n+++ b/%1\$s\n@@ -1 +1 @@\n-tool %2\$d\n+tool %3\$d\n",
            self::TOOL,
            $from,
            $to,
        );
    }

    /**
     * @param list<string> $requires
     */
    private function registryPatch(string $id, string $diff, array $requires): RegistryPatch
    {
        $this->http()->respond(self::BASE . '/' . $id . '.diff', 200, $diff);

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

    private ?FakeHttpClient $http = null;

    private function http(): FakeHttpClient
    {
        return $this->http ??= new FakeHttpClient();
    }

    /**
     * @param list<RegistryPatch> $patches
     */
    private function graph(array $patches): DependencyGraph
    {
        $ids = array_map(static fn (RegistryPatch $patch): string => $patch->id, $patches);

        return new DependencyGraph(new Registry('1', self::BASE, [], array_combine($ids, $patches)), $ids);
    }

    /**
     * @param list<RegistryPatch> $patches
     */
    private function detector(array $patches): DryRunDetector
    {
        $filesystem = new LocalFilesystem();
        $installation = new Installation($this->store->path(), [], [], Edition::OpenSource, '9.9.9', null, false);

        return new DryRunDetector(
            $installation,
            new PatchDownloader($this->http(), $filesystem, self::BASE, $this->cache->path()),
            new DiffParser(),
            new SystemPatchBinary(new SymfonyProcessRunner()),
            $filesystem,
        );
    }

    /**
     * @return array<string, string> sha256 by relative path of every file in the store
     */
    private function checksums(): array
    {
        $sums = [];
        $root = $this->store->path();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $path = $file->getPathname();
            $sums[substr($path, strlen($root) + 1)] = (string) hash_file('sha256', $path);
        }
        ksort($sums);

        return $sums;
    }
}
