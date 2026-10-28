<?php

declare(strict_types=1);

namespace Patchline\Cli;

final class Config
{
    /**
     * @param list<string> $failOnStates
     */
    public function __construct(
        private readonly ?string $command,
        private readonly string $root,
        private readonly OutputFormat $format,
        private readonly array $failOnStates,
        private readonly bool $failOnCrossCheck,
        private readonly ?string $registry,
        private readonly ?string $cacheDir,
        private readonly bool $noCache,
        private readonly bool $includeEntitled,
        private readonly bool $crossCheck,
        private readonly bool $noAnsi,
        private readonly bool $dryRun,
        private readonly bool $adopt,
        private readonly bool $prune,
    ) {
    }

    public function command(): ?string
    {
        return $this->command;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function format(): OutputFormat
    {
        return $this->format;
    }

    /**
     * @return list<string> PatchState values
     */
    public function failOnStates(): array
    {
        return $this->failOnStates;
    }

    public function failOnCrossCheck(): bool
    {
        return $this->failOnCrossCheck;
    }

    public function registry(): ?string
    {
        return $this->registry;
    }

    public function cacheDir(): ?string
    {
        return $this->cacheDir;
    }

    public function noCache(): bool
    {
        return $this->noCache;
    }

    public function includeEntitled(): bool
    {
        return $this->includeEntitled;
    }

    public function crossCheck(): bool
    {
        return $this->crossCheck;
    }

    public function noAnsi(): bool
    {
        return $this->noAnsi;
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function adopt(): bool
    {
        return $this->adopt;
    }

    public function prune(): bool
    {
        return $this->prune;
    }
}
