<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Registry;

use Patchline\Exception\RuntimeFailure;
use Patchline\Registry\Area;
use Patchline\Registry\ParseResult;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryParser;
use Patchline\Registry\RegistryPatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistryParser::class)]
#[CoversClass(Registry::class)]
#[CoversClass(RegistryPatch::class)]
#[CoversClass(Area::class)]
#[CoversClass(ParseResult::class)]
final class RegistryParserTest extends TestCase
{
    private const SHA = '334ad9ba8ba7158cc485babe3992fe3993ff697a3e871ad5890ab6d44dca4098';

    public function test_it_parses_the_real_adobe_registry_sample_into_typed_patches(): void
    {
        $data = $this->decodeFile(__DIR__ . '/../../Fixtures/registry/adobe-sample.json');

        $result = (new RegistryParser())->parse($data);

        self::assertSame([], $result->warnings);
        $registry = $result->registry;
        self::assertSame('1.0', $registry->schemaVersion);
        self::assertSame('https://repo.magento.com/patch', $registry->patchBaseUrl);
        self::assertCount(17, $registry->patches);
        self::assertTrue($registry->areas['CE']->detectsByBaseVersion());
        self::assertSame('magento/extension-b2b', $registry->areas['B2B']->composerPackage);
        $patch = $registry->patch('248p5-2026-07-001-CE');
        self::assertSame(['2.4.8-p5'], $patch->appliesTo);
        self::assertSame('CE', $patch->area);
        self::assertSame([], $patch->requires);
        self::assertSame('CVE-2026-48356', $patch->cves[0]);
        self::assertSame('2026-07-14', $patch->released->format('Y-m-d'));
        self::assertSame('248p5-2026-07-001-CE.diff', $patch->fileName);
        self::assertSame(self::SHA, $patch->sha256);
        self::assertTrue($registry->has('248p5-2026-07-001-CE'));
        self::assertFalse($registry->has('nope'));
    }

    public function test_it_preserves_registry_order_of_patches(): void
    {
        $data = $this->registry(['zz' => $this->patch('zz'), 'aa' => $this->patch('aa'), 'mm' => $this->patch('mm')]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame(['zz', 'aa', 'mm'], array_keys($result->registry->patches));
    }

    public function test_it_skips_a_patch_with_a_missing_sha256_and_reports_a_warning(): void
    {
        $bad = $this->patch('bad');
        unset($bad['sha256']);
        $data = $this->registry(['bad' => $bad, 'ok' => $this->patch('ok')]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame(['ok'], array_keys($result->registry->patches));
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('bad', $result->warnings[0]);
        self::assertStringContainsString('sha256', $result->warnings[0]);
    }

    public function test_it_skips_a_patch_whose_file_name_contains_a_path_separator_and_reports_a_warning(): void
    {
        $bad = $this->patch('bad');
        $bad['file_name'] = '../etc/bad.diff';
        $data = $this->registry(['bad' => $bad]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame([], $result->registry->patches);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('file_name', $result->warnings[0]);
    }

    public function test_it_skips_a_patch_whose_area_is_not_defined_and_reports_a_warning(): void
    {
        $bad = $this->patch('bad');
        $bad['area'] = 'Ghost';
        $data = $this->registry(['bad' => $bad]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame([], $result->registry->patches);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('Ghost', $result->warnings[0]);
    }

    public function test_it_defaults_entitlements_to_an_empty_list(): void
    {
        $withEntitlement = $this->patch('ee');
        $withEntitlement['entitlements'] = ['ee'];
        $data = $this->registry(['plain' => $this->patch('plain'), 'ee' => $withEntitlement]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame([], $result->registry->patch('plain')->entitlements);
        self::assertSame(['ee'], $result->registry->patch('ee')->entitlements);
    }

    public function test_it_rejects_a_registry_with_an_unsupported_schema_major_version(): void
    {
        $data = $this->registry([]);
        $data['_schema']['version'] = '2.0';

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('registry schema 2.0 is not supported, update patchline');

        (new RegistryParser())->parse($data);
    }

    public function test_it_rejects_a_patch_base_url_that_is_not_https(): void
    {
        $data = $this->registry([]);
        $data['_schema']['patch_base_url'] = 'http://example.com/patch';

        $this->expectException(RuntimeFailure::class);

        (new RegistryParser())->parse($data);
    }

    public function test_it_accepts_an_http_localhost_patch_base_url_only_when_insecure_localhost_is_allowed(): void
    {
        $local = $this->registry([]);
        $local['_schema']['patch_base_url'] = 'http://127.0.0.1:8080/patch';
        $named = $this->registry([]);
        $named['_schema']['patch_base_url'] = 'http://localhost/patch';
        $remote = $this->registry([]);
        $remote['_schema']['patch_base_url'] = 'http://example.com/patch';

        $allowing = new RegistryParser(allowInsecureLocalhost: true);

        self::assertSame('http://127.0.0.1:8080/patch', $allowing->parse($local)->registry->patchBaseUrl);
        self::assertSame('http://localhost/patch', $allowing->parse($named)->registry->patchBaseUrl);
        try {
            $allowing->parse($remote);
            self::fail('A non-local http host must be rejected.');
        } catch (RuntimeFailure) {
            $this->addToAssertionCount(1);
        }
        try {
            (new RegistryParser())->parse($local);
            self::fail('Localhost http must be rejected by default.');
        } catch (RuntimeFailure) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_it_rejects_a_patch_base_url_that_is_not_a_string(): void
    {
        $data = $this->registry([]);
        $data['_schema']['patch_base_url'] = 42;

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('The registry patch_base_url is not a string.');

        (new RegistryParser())->parse($data);
    }

    public function test_it_defaults_the_patch_base_url(): void
    {
        $data = $this->registry([]);
        unset($data['_schema']['patch_base_url']);

        $result = (new RegistryParser())->parse($data);

        self::assertSame('https://repo.magento.com/patch', $result->registry->patchBaseUrl);
    }

    public function test_it_rejects_a_document_that_is_not_a_registry(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('The patch registry is not a JSON object. Check the --registry source.');

        (new RegistryParser())->parse('nope');
    }

    public function test_it_skips_malformed_areas_and_patches_with_warnings(): void
    {
        $data = $this->registry(['p' => $this->patch('p')]);
        $data['_areas']['Broken'] = 'x';
        $data['_areas']['NoDetect'] = ['description' => 'd'];
        $data['patches']['notarray'] = 'x';
        $badDate = $this->patch('d');
        $badDate['released'] = '2026-13-45';
        $data['patches']['d'] = $badDate;
        $badSha = $this->patch('s');
        $badSha['sha256'] = 'ABC';
        $data['patches']['s'] = $badSha;
        $badList = $this->patch('l');
        $badList['applies_to'] = 'x';
        $data['patches']['l'] = $badList;
        $badReq = $this->patch('r');
        $badReq['requires'] = [1];
        $data['patches']['r'] = $badReq;

        $result = (new RegistryParser())->parse($data);

        self::assertSame(['p'], array_keys($result->registry->patches));
        self::assertSame(['CE'], array_keys($result->registry->areas));
        self::assertCount(7, $result->warnings);
    }

    #[DataProvider('schemaVersions')]
    public function test_it_accepts_only_registries_of_schema_major_version_1(string $version, bool $supported): void
    {
        $data = $this->registry([]);
        $data['_schema']['version'] = $version;

        try {
            (new RegistryParser())->parse($data);
            $accepted = true;
        } catch (RuntimeFailure) {
            $accepted = false;
        }

        self::assertSame($supported, $accepted);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function schemaVersions(): iterable
    {
        yield 'major only' => ['1', true];
        yield 'minor' => ['1.2', true];
        yield 'next major' => ['2.1', false];
        yield 'one as a later component' => ['0.1.0', false];
        yield 'one with a prefix' => ['v1.0', false];
        yield 'eleven' => ['11.0', false];
    }

    public function test_it_names_a_missing_schema_version_in_the_failure(): void
    {
        $data = $this->registry([]);
        unset($data['_schema']['version']);

        $this->expectExceptionMessage('registry schema (missing) is not supported, update patchline');

        (new RegistryParser())->parse($data);
    }

    public function test_it_removes_trailing_slashes_from_the_patch_base_url(): void
    {
        $data = $this->registry([]);
        $data['_schema']['patch_base_url'] = 'https://repo.magento.com/patch//';

        $registry = (new RegistryParser())->parse($data)->registry;

        self::assertSame('https://repo.magento.com/patch', $registry->patchBaseUrl);
    }

    public function test_it_rejects_an_https_looking_insecure_url_that_only_mentions_localhost_later(): void
    {
        $data = $this->registry([]);
        $data['_schema']['patch_base_url'] = 'http://evil.example/redirect?to=http://127.0.0.1/patch';

        $this->expectExceptionMessage(
            'The registry patch_base_url "http://evil.example/redirect?to=http://127.0.0.1/patch" must use https.',
        );

        (new RegistryParser(allowInsecureLocalhost: true))->parse($data);
    }

    public function test_it_keeps_area_descriptions_and_packages_and_numeric_ids_as_strings(): void
    {
        // Numeric-looking ids become integer array keys in PHP; the parser must still treat them as strings.
        $json = <<<'JSON'
            {
                "_schema": {"version": "1.0", "patch_base_url": "https://repo.magento.com/patch"},
                "_areas": {
                    "CE": {"description": "CE", "detection": "base_version"},
                    "PKG": {"description": "A package", "composer_package": "acme/pkg"},
                    "404": {"detection": "base_version"}
                },
                "patches": {
                    "123": {
                        "applies_to": ["9.9.9"], "area": "PKG", "requires": [], "cves": [],
                        "released": "2026-01-02", "file_name": "p123.diff",
                        "sha256": "%s"
                    }
                }
            }
            JSON;
        $data = json_decode(sprintf($json, self::SHA), true, 512, JSON_THROW_ON_ERROR);

        $registry = (new RegistryParser())->parse($data)->registry;

        self::assertSame('CE', self::area($registry, 'CE')->description);
        self::assertSame('A package', self::area($registry, 'PKG')->description);
        self::assertSame('acme/pkg', self::area($registry, 'PKG')->composerPackage);
        self::assertNull(self::area($registry, 'CE')->composerPackage);
        self::assertSame('', self::area($registry, '404')->description);
        self::assertTrue($registry->has('123'));
        self::assertSame('123', $registry->patch('123')->id);
    }

    public function test_it_keeps_reading_areas_after_a_malformed_one(): void
    {
        $data = $this->registry([]);
        $data['_areas'] = ['Broken' => 'x', 'Later' => ['detection' => 'base_version']];

        $result = (new RegistryParser())->parse($data);

        self::assertSame(['Later'], array_keys($result->registry->areas));
        self::assertSame(['Skipped area Broken: not an object.'], $result->warnings);
    }

    public function test_it_says_why_each_malformed_patch_was_skipped(): void
    {
        $data = $this->registry([
            'notarray' => 'x',
            'badfile' => ['file_name' => 'x.diff.evil'] + $this->patch('badfile'),
            'shalong' => ['sha256' => self::SHA . 'ff'] + $this->patch('shalong'),
            'shaprefix' => ['sha256' => 'ff' . self::SHA] + $this->patch('shaprefix'),
            'noapplies' => $this->without($this->patch('noapplies'), 'applies_to'),
            'norequires' => $this->without($this->patch('norequires'), 'requires'),
            'nocves' => $this->without($this->patch('nocves'), 'cves'),
            'intdate' => ['released' => 20260102] + $this->patch('intdate'),
            'ok' => $this->patch('ok'),
        ]);

        $result = (new RegistryParser())->parse($data);

        self::assertSame(['ok'], array_keys($result->registry->patches));
        self::assertSame([
            'Skipped patch notarray: not an object.',
            'Skipped patch badfile: file_name is missing or not a plain .diff file name.',
            'Skipped patch shalong: sha256 is missing or not 64 lowercase hex characters.',
            'Skipped patch shaprefix: sha256 is missing or not 64 lowercase hex characters.',
            'Skipped patch noapplies: applies_to is missing.',
            'Skipped patch norequires: requires is missing.',
            'Skipped patch nocves: cves is missing.',
            'Skipped patch intdate: released is missing or not a YYYY-MM-DD date.',
        ], $result->warnings);
    }

    public function test_it_keeps_every_entry_of_the_string_lists(): void
    {
        $patch = ['applies_to' => ['2.4.8', '2.4.9'], 'requires' => ['a', 'b'], 'cves' => ['CVE-1', 'CVE-2']]
            + $this->patch('multi');

        $parsed = (new RegistryParser())->parse($this->registry(['multi' => $patch]))->registry->patch('multi');

        self::assertSame(['2.4.8', '2.4.9'], $parsed->appliesTo);
        self::assertSame(['a', 'b'], $parsed->requires);
        self::assertSame(['CVE-1', 'CVE-2'], $parsed->cves);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function without(array $patch, string $key): array
    {
        unset($patch[$key]);

        return $patch;
    }

    /**
     * @return array<mixed>
     */
    private function decodeFile(string $path): array
    {
        $json = file_get_contents($path);
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $patches
     *
     * @return array{_schema: array<string, mixed>, _areas: array<string, mixed>, patches: array<string, mixed>}
     */
    private function registry(array $patches): array
    {
        return [
            '_schema' => ['version' => '1.0', 'patch_base_url' => 'https://repo.magento.com/patch'],
            '_areas' => ['CE' => ['description' => 'CE', 'detection' => 'base_version']],
            'patches' => $patches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function patch(string $id): array
    {
        return [
            'applies_to' => ['9.9.9'],
            'area' => 'CE',
            'requires' => [],
            'cves' => [],
            'released' => '2026-01-02',
            'file_name' => $id . '.diff',
            'sha256' => self::SHA,
        ];
    }

    private static function area(Registry $registry, string $key): Area
    {
        foreach ($registry->areas as $area) {
            if ($area->key === $key) {
                return $area;
            }
        }

        self::fail(sprintf('Area "%s" was not parsed.', $key));
    }
}
