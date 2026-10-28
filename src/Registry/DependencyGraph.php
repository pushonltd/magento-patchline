<?php

declare(strict_types=1);

namespace Patchline\Registry;

/**
 * The `requires` relationships between the patches in a scope (a list of applicable ids).
 */
final class DependencyGraph
{
    /** @var array<string, true> */
    private array $scope = [];

    /** @var array<string, RegistryPatch> */
    private array $patches = [];

    /** @var array<string, list<string>> in-scope requirements per id */
    private array $requires = [];

    /** @var list<string> */
    private array $cycle;

    /**
     * @param list<string> $scope
     */
    public function __construct(private readonly Registry $registry, array $scope)
    {
        foreach ($scope as $id) {
            if (!$registry->has($id)) {
                continue;
            }
            $this->scope[$id] = true;
            $this->patches[$id] = $registry->patch($id);
        }
        foreach ($this->patches as $id => $patch) {
            $this->requires[$id] = array_values(array_filter(
                $patch->requires,
                fn (string $required): bool => isset($this->scope[$required]),
            ));
        }
        $this->cycle = $this->findCycleMembers();
    }

    /**
     * @return list<string> everything `$id` transitively requires within scope, nearest first
     */
    public function prerequisitesOf(string $id): array
    {
        return array_values(array_filter(
            $this->walk($id, $this->requires),
            static fn (string $found): bool => $found !== $id,
        ));
    }

    /**
     * @return list<string> everything in scope that transitively requires `$id`
     */
    public function descendantsOf(string $id): array
    {
        $dependents = [];
        foreach ($this->requires as $patchId => $required) {
            foreach ($required as $requiredId) {
                $dependents[$requiredId][] = $patchId;
            }
        }

        return array_values(array_filter(
            $this->walk($id, $dependents),
            static fn (string $found): bool => $found !== $id,
        ));
    }

    /**
     * @return list<string> required ids that are not in scope (not applicable or unknown to the registry)
     */
    public function missingRequirements(string $id): array
    {
        if (!$this->registry->has($id)) {
            return [];
        }

        return array_values(array_filter(
            $this->registry->patch($id)->requires,
            fn (string $required): bool => !isset($this->scope[$required]),
        ));
    }

    /**
     * Topological order (prerequisites first), ties broken by release date then id. Ids that are part of
     * a cycle are left out; see problems().
     *
     * @return list<string>
     */
    public function order(): array
    {
        $cyclic = array_flip($this->cycle);
        $pending = array_diff_key($this->patches, $cyclic);
        $done = [];
        $order = [];
        while ($pending !== []) {
            $ready = array_filter(
                $pending,
                fn (RegistryPatch $patch, string $id): bool => $this->isReady($id, $done, $cyclic),
                ARRAY_FILTER_USE_BOTH,
            );
            $next = $this->earliest($ready);
            $order[] = $next;
            $done[$next] = true;
            unset($pending[$next]);
        }

        return $order;
    }

    /**
     * @return list<GraphProblem>
     */
    public function problems(): array
    {
        if ($this->cycle === []) {
            return [];
        }

        return [new GraphProblem(
            GraphProblem::CYCLE,
            $this->cycle,
            sprintf('These patches require each other in a loop: %s. Check the registry.', implode(', ', $this->cycle)),
        )];
    }

    /**
     * @param array<string, list<string>> $edges
     * @return list<string>
     */
    private function walk(string $start, array $edges): array
    {
        $seen = [];
        $result = [];
        $queue = [$start];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges[$current] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $result[] = $next;
                $queue[] = $next;
            }
        }

        return $result;
    }

    /**
     * @param array<string, true> $done
     * @param array<int|string, int> $cyclic
     */
    private function isReady(string $id, array $done, array $cyclic): bool
    {
        foreach ($this->requires[$id] as $required) {
            if (!isset($done[$required]) && !isset($cyclic[$required])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, RegistryPatch> $candidates non-empty
     */
    private function earliest(array $candidates): string
    {
        $ids = array_keys($candidates);
        usort(
            $ids,
            fn (string $a, string $b): int => [$candidates[$a]->released, $a] <=> [$candidates[$b]->released, $b],
        );

        return $ids[0];
    }

    /**
     * @return list<string> ids that can reach themselves through `requires`, sorted
     */
    private function findCycleMembers(): array
    {
        $members = [];
        foreach (array_keys($this->requires) as $id) {
            if (!in_array($id, $this->walk($id, $this->requires), true)) {
                continue;
            }

            $members[] = $id;
        }
        sort($members);

        return $members;
    }
}
