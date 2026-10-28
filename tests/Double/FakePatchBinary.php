<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use Patchline\Detection\PatchBinary;
use Patchline\Detection\PatchRun;

final class FakePatchBinary implements PatchBinary
{
    /** @var list<PatchCall> */
    private array $calls = [];

    /**
     * @param callable(PatchCall): PatchRun $script
     */
    public function __construct(private $script, private readonly bool $available = true)
    {
    }

    /**
     * Succeeds for the directions listed per diff text; anything else (including unknown diffs) fails.
     *
     * @param array<string, array{forward: bool, reverse: bool}> $outcomes
     */
    public static function byDiff(array $outcomes): self
    {
        return new self(static function (PatchCall $call) use ($outcomes): PatchRun {
            $key = $call->reverse ? 'reverse' : 'forward';

            return new PatchRun(($outcomes[$call->diff][$key] ?? false) ? 0 : 1);
        });
    }

    /**
     * @return list<PatchCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function dryRun(string $diff, string $workDir, bool $reverse): PatchRun
    {
        return $this->record(new PatchCall($diff, $workDir, $reverse, true));
    }

    public function apply(string $diff, string $workDir, bool $reverse): PatchRun
    {
        return $this->record(new PatchCall($diff, $workDir, $reverse, false));
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    private function record(PatchCall $call): PatchRun
    {
        $this->calls[] = $call;

        return ($this->script)($call);
    }
}
