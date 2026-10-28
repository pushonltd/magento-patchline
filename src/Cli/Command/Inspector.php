<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Analysis\AnalysisInput;
use Patchline\Analysis\Analyzer;
use Patchline\Cli\Config;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DryRunDetector;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Download\CredentialsResolver;
use Patchline\Download\PatchDownloader;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Clock;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\HttpClient;
use Patchline\Infrastructure\ProcessRunner;
use Patchline\Installation\InstallationReader;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\Source\CloudPatchesSource;
use Patchline\Queue\Source\HotfixDirSource;
use Patchline\Registry\ComponentResolver;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\LoadedRegistry;
use Patchline\Registry\RegistryLoader;
use Patchline\Registry\RegistryPatch;

/**
 * The part every command shares: store + registry in, Report out. The registry is loaded once and the
 * downloader is reused, so a second inspection (after sync) does not download diffs again.
 */
final class Inspector
{
    private ?LoadedRegistry $registry = null;
    private ?PatchDownloader $downloader = null;
    private readonly SystemPatchBinary $patchBinary;

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $env,
        private readonly Filesystem $filesystem,
        private readonly HttpClient $http,
        ProcessRunner $processes,
        private readonly Clock $clock,
    ) {
        $this->patchBinary = new SystemPatchBinary($processes);
    }

    public function patchBinary(): SystemPatchBinary
    {
        return $this->patchBinary;
    }

    public function downloader(): PatchDownloader
    {
        return $this->downloader ??= $this->createDownloader();
    }

    public function inspect(): Inspection
    {
        $installation = (new InstallationReader($this->filesystem))->read($this->config->root());
        $loaded = $this->registry();
        $resolver = new ComponentResolver($loaded->registry);
        $applicable = $resolver->applicablePatches($installation);
        $graph = new DependencyGraph($loaded->registry, array_map(
            static fn (RegistryPatch $patch): string => $patch->id,
            $applicable,
        ));
        $this->requirePatchBinary();
        $detections = (new DryRunDetector(
            $installation,
            $this->downloader(),
            new DiffParser(),
            $this->patchBinary,
            $this->filesystem,
        ))->detect($applicable, $graph);
        $queue = PatchQueue::fromSources(
            (new CloudPatchesSource($this->filesystem))
                ->collect($installation->root, $installation->packages),
            (new HotfixDirSource($this->filesystem))->collect($installation->root),
        );
        $report = (new Analyzer())->analyze(new AnalysisInput(
            $installation,
            $resolver->installedComponents($installation),
            $applicable,
            $graph,
            $detections,
            $queue,
            $loaded->source,
            $this->config->includeEntitled(),
            $loaded->warnings,
        ));

        return new Inspection($installation, $report);
    }

    private function registry(): LoadedRegistry
    {
        return $this->registry ??= (new RegistryLoader(
            $this->filesystem,
            $this->http,
            $this->clock,
            $this->allowInsecure(),
        ))->load(
            $this->config->root(),
            $this->config->registry(),
            $this->config->cacheDir(),
            $this->config->noCache(),
        );
    }

    private function createDownloader(): PatchDownloader
    {
        $baseUrl = $this->registry()->registry->patchBaseUrl;
        $origins = ['https://repo.magento.com'];
        if ($this->allowInsecure()) {
            $origins[] = self::origin($baseUrl);
        }

        return new PatchDownloader(
            $this->http,
            $this->filesystem,
            $baseUrl,
            $this->config->cacheDir() ?? $this->config->root() . '/var/patch_metadata',
            (new CredentialsResolver($this->filesystem))->resolve($this->config->root(), $this->env),
            $this->config->noCache(),
            $origins,
        );
    }

    private function requirePatchBinary(): void
    {
        if (!$this->patchBinary->isAvailable()) {
            throw new RuntimeFailure(
                'The "patch" program is not installed or not on PATH. '
                . 'Install it (for example "apt-get install patch" or "brew install gpatch") and run patchline again.',
            );
        }
    }

    private function allowInsecure(): bool
    {
        return ($this->env['PATCHLINE_ALLOW_INSECURE_REGISTRY'] ?? '') === '1';
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . $port;
    }
}
