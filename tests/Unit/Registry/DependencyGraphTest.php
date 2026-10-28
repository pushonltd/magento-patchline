<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Registry;

use Patchline\Registry\DependencyGraph;
use Patchline\Registry\GraphProblem;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyGraph::class)]
#[CoversClass(GraphProblem::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class DependencyGraphTest extends TestCase
{
    public function test_it_returns_transitive_prerequisites_of_a_patch(): void
    {
        $registry = $this->registry(['A' => [], 'B' => ['A'], 'C' => ['B'], 'D' => []]);

        $graph = new DependencyGraph($registry, ['A', 'B', 'C', 'D']);

        self::assertSame(['B', 'A'], $graph->prerequisitesOf('C'));
        self::assertSame([], $graph->prerequisitesOf('A'));
    }

    public function test_it_returns_transitive_descendants_of_a_patch(): void
    {
        $registry = $this->registry(['A' => [], 'B' => ['A'], 'C' => ['B'], 'D' => []]);

        $graph = new DependencyGraph($registry, ['A', 'B', 'C', 'D']);

        self::assertSame(['B', 'C'], $graph->descendantsOf('A'));
        self::assertSame([], $graph->descendantsOf('D'));
    }

    public function test_it_orders_patches_so_every_prerequisite_comes_first(): void
    {
        $registry = $this->registry(['C' => ['B'], 'B' => ['A'], 'A' => []]);

        $graph = new DependencyGraph($registry, ['C', 'B', 'A']);

        self::assertSame(['A', 'B', 'C'], $graph->order());
    }

    public function test_it_breaks_ordering_ties_by_release_date_and_then_id(): void
    {
        $registry = $this->registry(
            ['Z' => [], 'Y' => [], 'X' => [], 'W' => ['Z']],
            ['Z' => '2024-01-01', 'Y' => '2024-01-01', 'X' => '2023-01-01', 'W' => '2022-01-01'],
        );

        $graph = new DependencyGraph($registry, ['Z', 'Y', 'X', 'W']);

        self::assertSame(['X', 'Y', 'Z', 'W'], $graph->order());
    }

    public function test_it_reports_required_ids_that_are_outside_the_scope(): void
    {
        $registry = $this->registry(['A' => [], 'B' => ['A', 'GHOST']]);

        $graph = new DependencyGraph($registry, ['B']);

        self::assertSame(['A', 'GHOST'], $graph->missingRequirements('B'));
        self::assertSame([], $graph->prerequisitesOf('B'));
    }

    public function test_it_reports_a_dependency_cycle_instead_of_looping(): void
    {
        $registry = $this->registry(['A' => ['C'], 'B' => ['A'], 'C' => ['B'], 'D' => []]);

        $graph = new DependencyGraph($registry, ['A', 'B', 'C', 'D']);

        self::assertSame(['D'], $graph->order());
        $problems = $graph->problems();
        self::assertCount(1, $problems);
        self::assertSame(GraphProblem::CYCLE, $problems[0]->kind);
        self::assertSame(['A', 'B', 'C'], $problems[0]->ids);
    }

    public function test_it_ignores_scope_ids_the_registry_does_not_know(): void
    {
        $registry = $this->registry(['A' => [], 'B' => ['A']]);

        $graph = new DependencyGraph($registry, ['A', 'GHOST', 'B']);

        self::assertSame(['A', 'B'], $graph->order());
        self::assertSame([], $graph->missingRequirements('GHOST'));
    }

    public function test_it_reports_no_problems_for_an_acyclic_graph(): void
    {
        $registry = $this->registry(['A' => [], 'B' => ['A']]);

        $graph = new DependencyGraph($registry, ['A', 'B']);

        self::assertSame([], $graph->problems());
    }

    /**
     * @param array<string, list<string>> $requires
     * @param array<string, string> $released
     */
    private function registry(array $requires, array $released = []): Registry
    {
        $patches = [];
        foreach ($requires as $id => $required) {
            $patches[$id] = new RegistryPatch(
                $id,
                ['2.4.9'],
                'core',
                $required,
                [],
                new \DateTimeImmutable($released[$id] ?? '2024-01-01'),
                $id . '.patch',
                str_repeat('a', 64),
            );
        }

        return new Registry('1', 'https://example.test/', [], $patches);
    }
}
