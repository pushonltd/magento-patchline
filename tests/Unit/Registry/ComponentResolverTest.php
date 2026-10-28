<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Registry;

use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Registry\Area;
use Patchline\Registry\ComponentResolver;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentResolver::class)]
#[CoversClass(InstalledComponents::class)]
#[UsesClass(Installation::class)]
#[UsesClass(Area::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class ComponentResolverTest extends TestCase
{
    public function test_it_treats_the_base_version_area_as_installed_at_the_magento_version(): void
    {
        $resolver = new ComponentResolver($this->registry([]));

        $components = $resolver->installedComponents($this->installation('2.4.9', []));

        self::assertSame(['base' => '2.4.9'], $components->versions);
    }

    public function test_it_treats_a_package_area_as_installed_only_when_its_package_is_locked(): void
    {
        $resolver = new ComponentResolver($this->registry([]));

        $with = $resolver->installedComponents($this->installation('2.4.9', ['vendor/pkg' => '1.2.3']));
        $without = $resolver->installedComponents($this->installation('2.4.9', []));

        self::assertSame(['base' => '2.4.9', 'pkg' => '1.2.3'], $with->versions);
        self::assertSame(['base' => '2.4.9'], $without->versions);
    }

    public function test_it_selects_patches_whose_applies_to_lists_the_installed_version_exactly(): void
    {
        $registry = $this->registry([
            $this->patch('A', ['2.4.9', '2.4.8'], 'base'),
            $this->patch('B', ['2.4.9-p1'], 'base'),
        ]);

        $ids = $this->ids((new ComponentResolver($registry))->applicablePatches($this->installation('2.4.9', [])));

        self::assertSame(['A'], $ids);
    }

    public function test_it_ignores_patches_for_another_release_line(): void
    {
        $registry = $this->registry([$this->patch('A', ['2.4.8'], 'base')]);

        $patches = (new ComponentResolver($registry))->applicablePatches($this->installation('2.4.9', []));

        self::assertSame([], $patches);
    }

    public function test_it_ignores_patches_for_areas_that_are_not_installed(): void
    {
        $registry = $this->registry([$this->patch('A', ['1.2.3'], 'pkg')]);

        $patches = (new ComponentResolver($registry))->applicablePatches($this->installation('1.2.3', []));

        self::assertSame([], $patches);
    }

    public function test_it_returns_applicable_patches_in_registry_order(): void
    {
        $registry = $this->registry([
            $this->patch('Z', ['2.4.9'], 'base'),
            $this->patch('P', ['1.2.3'], 'pkg'),
            $this->patch('A', ['2.4.9'], 'base'),
        ]);

        $ids = $this->ids((new ComponentResolver($registry))->applicablePatches(
            $this->installation('2.4.9', ['vendor/pkg' => '1.2.3']),
        ));

        self::assertSame(['Z', 'P', 'A'], $ids);
    }

    /**
     * @param list<RegistryPatch> $patches
     */
    private function registry(array $patches): Registry
    {
        $byId = [];
        foreach ($patches as $patch) {
            $byId[$patch->id] = $patch;
        }

        return new Registry('1', 'https://example.test/', [
            'base' => new Area('base', 'Base', null),
            'pkg' => new Area('pkg', 'Package', 'vendor/pkg'),
        ], $byId);
    }

    /**
     * @param list<string> $appliesTo
     */
    private function patch(string $id, array $appliesTo, string $area): RegistryPatch
    {
        return new RegistryPatch(
            $id,
            $appliesTo,
            $area,
            [],
            [],
            new \DateTimeImmutable('2025-01-01'),
            $id . '.patch',
            str_repeat('a', 64),
        );
    }

    /**
     * @param array<string, string> $packages
     */
    private function installation(string $base, array $packages): Installation
    {
        return new Installation('/store', $packages, [], Edition::OpenSource, $base, null, false);
    }

    /**
     * @param list<RegistryPatch> $patches
     * @return list<string>
     */
    private function ids(array $patches): array
    {
        return array_map(static fn (RegistryPatch $p): string => $p->id, $patches);
    }
}
