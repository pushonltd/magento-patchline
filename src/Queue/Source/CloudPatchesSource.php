<?php

declare(strict_types=1);

namespace Patchline\Queue\Source;

use Composer\Semver\Semver;
use JsonException;
use Patchline\Infrastructure\Filesystem;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use UnexpectedValueException;

final class CloudPatchesSource
{
    private const BASE = 'vendor/magento/magento-cloud-patches';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @param array<string, string> $packages installed package => version
     */
    public function collect(string $root, array $packages): SourceResult
    {
        $file = $root . '/' . self::BASE . '/patches.json';
        if (!$this->filesystem->isFile($file)) {
            return new SourceResult([]);
        }

        $definitions = $this->parse($this->filesystem->read($file));
        if ($definitions === null) {
            return new SourceResult([], [
                'vendor/magento/magento-cloud-patches/patches.json is not in the expected format; '
                . 'ignoring cloud-patches. Reinstall magento/magento-cloud-patches.',
            ]);
        }

        $entries = [];
        $warnings = [];
        foreach ($definitions as $package => $titles) {
            if (!isset($packages[$package])) {
                continue;
            }
            foreach ($titles as $title => $files) {
                foreach ($files as $constraint => $patchFile) {
                    try {
                        $matches = Semver::satisfies($packages[$package], $constraint);
                    } catch (UnexpectedValueException $e) {
                        $warnings[] = sprintf(
                            'Skipped cloud patch "%s" for %s: constraint "%s" cannot be parsed (%s).',
                            $title,
                            $package,
                            $constraint,
                            $e->getMessage(),
                        );
                        continue;
                    }
                    $relative = self::BASE . '/patches/' . $patchFile;
                    if (!$matches || !$this->filesystem->isFile($root . '/' . $relative)) {
                        continue;
                    }
                    $entries[] = new QueueEntry(
                        0,
                        QueueOrigin::CloudRequired,
                        $title,
                        $relative,
                        hash('sha256', $this->filesystem->read($root . '/' . $relative)),
                    );
                }
            }
        }

        return new SourceResult($entries, $warnings);
    }

    /**
     * @return array<string, array<string, array<string, string>>>|null
     */
    private function parse(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $result = [];
        foreach ($data as $package => $titles) {
            if (!is_array($titles)) {
                return null;
            }
            foreach ($titles as $title => $files) {
                if (!is_array($files)) {
                    return null;
                }
                foreach ($files as $constraint => $patchFile) {
                    if (!is_string($patchFile)) {
                        return null;
                    }
                    $result[(string) $package][(string) $title][(string) $constraint] = $patchFile;
                }
            }
        }

        return $result;
    }
}
