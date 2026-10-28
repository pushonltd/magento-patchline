<?php

declare(strict_types=1);

namespace Patchline\Detection;

use Patchline\Exception\RuntimeFailure;

final class DetectionResults
{
    /**
     * @param array<string, DetectionResult> $results by patch id, in detection order
     */
    public function __construct(private readonly array $results)
    {
    }

    /**
     * @return array<string, DetectionResult>
     */
    public function all(): array
    {
        return $this->results;
    }

    public function has(string $id): bool
    {
        return isset($this->results[$id]);
    }

    public function get(string $id): DetectionResult
    {
        return $this->results[$id] ?? throw new RuntimeFailure('No detection result for ' . $id . '.');
    }

    /**
     * @return list<string> every warning of every patch, in detection order
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->results as $result) {
            array_push($warnings, ...$result->warnings);
        }

        return $warnings;
    }
}
