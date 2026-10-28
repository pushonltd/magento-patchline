<?php

declare(strict_types=1);

namespace Patchline\CrossCheck;

use JsonException;
use Patchline\Analysis\Report;
use Patchline\Detection\DetectionState;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\ProcessRunner;

/**
 * Runs Adobe's `vendor/bin/patch-status --format=json` on the store and compares its applied, missing and
 * unknown lists with Patchline's detection results (not the final states: Adobe's tool has no queue).
 * Run it after Patchline's own analysis so both tools see the same registry and diff caches.
 */
final class PatchStatusCrossCheck
{
    private const STDERR_EXCERPT = 200;

    public function __construct(private readonly ProcessRunner $processes, private readonly Filesystem $filesystem)
    {
    }

    public function check(Report $report, string $root, bool $noCache): CrossCheckResult
    {
        $script = $root . '/vendor/bin/patch-status';
        if (!$this->filesystem->isFile($script)) {
            return CrossCheckResult::skipped(
                'Adobe patch-status not installed on this store; cross-check skipped',
            );
        }

        $command = [PHP_BINARY, $script, '--root=' . $root, '--format=json'];
        if ($noCache) {
            $command[] = '--no-cache';
        }
        $process = $this->processes->run($command);
        if ($process->exitCode !== 0) {
            return CrossCheckResult::skipped(sprintf(
                'Adobe patch-status exited with status %d%s; cross-check skipped',
                $process->exitCode,
                self::excerpt($process->stderr),
            ));
        }

        $adobe = self::parse($process->stdout);
        if ($adobe === null) {
            return CrossCheckResult::skipped('Adobe patch-status output was not valid JSON; cross-check skipped');
        }

        return CrossCheckResult::compared(count($report->patches), $this->disagreements($report, $adobe));
    }

    /**
     * @param array<string, string> $adobe patch id => applied|not_applied|unknown
     * @return list<Disagreement>
     */
    private function disagreements(Report $report, array $adobe): array
    {
        $disagreements = [];
        $known = [];
        foreach ($report->patches as $patch) {
            $id = $patch->patch->id;
            $known[$id] = true;
            $theirs = $adobe[$id] ?? Disagreement::NOT_LISTED;
            $ours = $patch->detection->state;
            if (self::expectedFrom($ours) === $theirs) {
                continue;
            }

            $disagreements[] = new Disagreement($id, $ours->value, $theirs);
        }
        foreach ($adobe as $id => $theirs) {
            if (isset($known[$id])) {
                continue;
            }

            $disagreements[] = new Disagreement($id, DetectionState::NotApplicable->value, $theirs);
        }

        return $disagreements;
    }

    /** What Adobe's JSON says about a patch Patchline detects in the given state. */
    private static function expectedFrom(DetectionState $state): string
    {
        return $state === DetectionState::NotApplicable ? Disagreement::NOT_LISTED : $state->value;
    }

    /**
     * @return array<string, string>|null null when the document is not patch-status JSON
     */
    private static function parse(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $lists = [
            'applied_patches' => DetectionState::Applied->value,
            'missing_patches' => DetectionState::NotApplied->value,
            'unknown_patches' => DetectionState::Unknown->value,
        ];
        $byId = [];
        foreach ($lists as $key => $state) {
            $ids = $data[$key] ?? null;
            if (!is_array($ids)) {
                return null;
            }
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    return null;
                }
                $byId[$id] = $state;
            }
        }

        return $byId;
    }

    private static function excerpt(string $stderr): string
    {
        $text = trim($stderr);
        if ($text === '') {
            return '';
        }

        return ': ' . substr(str_replace(["\r", "\n"], ' ', $text), 0, self::STDERR_EXCERPT);
    }
}
