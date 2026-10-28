<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\Sync\ActionStatus;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\VerifyResult;

/**
 * JUnit XML for GitLab, Bitbucket and Jenkins. Built by hand: no ext-dom at runtime.
 * Suites: `patchline` (one testcase per patch), plus `patchline-sync` and `patchline-verify` when those ran.
 */
final class JunitFormatter implements Formatter
{
    public function format(
        Report $report,
        ?ExecutionResult $sync = null,
        ?VerifyResult $verify = null,
        bool $dryRun = false,
        ?CrossCheckResult $crossCheck = null,
    ): FormattedOutput {
        $suites = [self::suite('patchline', array_map($this->patchCase(...), $report->patches))];
        if ($sync !== null) {
            $suites[] = self::suite('patchline-sync', $this->syncCases($sync));
        }
        if ($verify !== null) {
            $suites[] = self::suite('patchline-verify', $this->verifyCases($verify));
        }
        if ($crossCheck !== null) {
            $suites[] = self::suite('patchline-cross-check', $this->crossCheckCases($crossCheck));
        }

        return new FormattedOutput(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<testsuites>\n" . implode('', $suites) . "</testsuites>\n",
        );
    }

    /** @return array{xml: string, kind: string} */
    private function patchCase(PatchReport $patch): array
    {
        $id = $patch->patch->id;
        $message = match ($patch->state) {
            PatchState::Missing => $id . ' is not applied and not queued',
            PatchState::Duplicate => $id . ' is queued more than once',
            PatchState::Misordered => $id . ' is queued before a patch it requires',
            default => null,
        };
        if ($message !== null) {
            return self::failure($patch->patch->area, $id, $message, $patch->state->value);
        }
        if ($patch->state === PatchState::Entitled || $patch->state === PatchState::Unknown) {
            return self::skipped($patch->patch->area, $id, $patch->state->value);
        }

        return self::passed($patch->patch->area, $id);
    }

    /** @return list<array{xml: string, kind: string}> */
    private function syncCases(ExecutionResult $sync): array
    {
        $cases = [];
        foreach ($sync->outcomes as $outcome) {
            $name = $outcome->action->describe();
            $cases[] = match ($outcome->status) {
                ActionStatus::Failed => self::failure('sync', $name, $outcome->message, 'failed'),
                ActionStatus::Skipped, ActionStatus::Planned => self::skipped('sync', $name, $outcome->status->value),
                ActionStatus::Done, ActionStatus::Warning => self::passed('sync', $name),
            };
        }

        return $cases;
    }

    /** @return list<array{xml: string, kind: string}> */
    private function verifyCases(VerifyResult $verify): array
    {
        $cases = [];
        foreach ($verify->steps as $step) {
            $path = $step->entry->path;
            $cases[] = match ($step->outcome) {
                ReplayOutcome::Failed => self::failure('verify', $path, $path . ' would fail', 'failed', $step->output),
                ReplayOutcome::SkippedNotInstalled => self::skipped('verify', $path, $step->outcome->label()),
                default => self::passed('verify', $path),
            };
        }

        return $cases;
    }

    /** @return list<array{xml: string, kind: string}> */
    private function crossCheckCases(CrossCheckResult $crossCheck): array
    {
        if (!$crossCheck->ran()) {
            return [self::skipped('cross-check', 'patch-status', (string) $crossCheck->warning)];
        }
        if (!$crossCheck->hasDisagreements()) {
            return [self::passed('cross-check', 'patch-status')];
        }

        return array_map(
            static fn (Disagreement $d): array => self::failure('cross-check', $d->id, $d->describe(), 'disagreement'),
            $crossCheck->disagreements,
        );
    }

    /** @param list<array{xml: string, kind: string}> $cases */
    private static function suite(string $name, array $cases): string
    {
        $count = static fn (string $kind): int => count(array_filter(
            $cases,
            static fn (array $case): bool => $case['kind'] === $kind,
        ));

        return sprintf(
            "  <testsuite name=\"%s\" tests=\"%d\" failures=\"%d\" skipped=\"%d\">\n%s  </testsuite>\n",
            self::escape($name),
            count($cases),
            $count('failure'),
            $count('skipped'),
            implode('', array_column($cases, 'xml')),
        );
    }

    /** @return array{xml: string, kind: string} */
    private static function passed(string $class, string $name): array
    {
        return ['xml' => self::open($class, $name) . "/>\n", 'kind' => 'passed'];
    }

    /** @return array{xml: string, kind: string} */
    private static function skipped(string $class, string $name, string $message): array
    {
        return [
            'xml' => self::open($class, $name) . sprintf(
                ">\n      <skipped message=\"%s\"/>\n    </testcase>\n",
                self::escape($message),
            ),
            'kind' => 'skipped',
        ];
    }

    /** @return array{xml: string, kind: string} */
    private static function failure(
        string $class,
        string $name,
        string $message,
        string $type,
        string $details = '',
    ): array {
        return [
            'xml' => self::open($class, $name) . sprintf(
                ">\n      <failure message=\"%s\" type=\"%s\">%s</failure>\n    </testcase>\n",
                self::escape($message),
                self::escape($type),
                self::escape(trim($details)),
            ),
            'kind' => 'failure',
        ];
    }

    private static function open(string $class, string $name): string
    {
        return sprintf('    <testcase classname="%s" name="%s"', self::escape($class), self::escape($name));
    }

    private static function escape(string $text): string
    {
        $valid = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($valid ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
