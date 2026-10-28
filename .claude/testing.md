# Testing

## Suites

| Suite | Path | What | Runs |
|---|---|---|---|
| Unit | `tests/Unit` | Pure logic and classes with test doubles. No network, no real `patch`, no files outside a temp dir. | `composer test:unit` |
| Integration | `tests/Integration` | Real `patch` binary, real filesystem, local HTTP server (`php -S`) serving a fixture registry and diffs. | `composer test:integration` |
| CLI | `tests/Cli` | Runs `bin/patchline` (or `dist/patchline` when `PATCHLINE_BIN` is set) as a process against fixture stores; asserts stdout, stderr, exit code. | `composer test:cli` |

`composer test` runs all three. PHPUnit `^10.5 || ^11.5 || ^12.4` — test code must run on all three: use
attributes only (`#[CoversClass]`, `#[DataProvider]`, `#[Group]`), never doc-comment annotations, and no
mocking API (we use fakes, see below).

## PHPUnit configuration

Strict by default: risky tests, warnings, deprecations, notices and unexpected output fail the run; tests run
in **random order** (so hidden coupling between tests shows up); every test class declares
`#[CoversClass(...)]` for the classes it tests (`beStrictAboutCoverageMetadata`), so coverage numbers mean
what they say.

## Quality gates

| Gate | Threshold | Command |
|---|---|---|
| Line coverage of `src/` | ≥ 95 % | `composer test:coverage` |
| Mutation score (Infection) | MSI ≥ 85 %, covered MSI ≥ 90 % | `composer mutation` |
| PHPStan on `tests/` too | level 10, strict rules | `composer analyse` |

Pure domain code (`Version`, `Registry`, `Analysis`, `Sync\Planner`, `Detection\DiffParser`, `Queue`) is
expected to reach 100 % line coverage; the gap to 95 % is for `Cli` bootstrapping and OS error paths.

## Test style

- One behaviour per test, named after the requirement. Arrange / act / assert, visibly separated.
- Assert on outcomes (return values, files on disk, output, exit codes), not on which private methods ran.
- Tables of cases (version constraints, diff headers, state matrix) use data providers with named keys.
- Every bug fix starts with a failing regression test.
- Hermetic: no network outside the local test server, no writes outside temp dirs (removed in `tearDown`),
  no `sleep`, no dependence on wall-clock time (inject `Clock`) or on test order.
- Golden files (`tests/Fixtures/output/`) are compared byte for byte; regenerate deliberately with
  `PATCHLINE_UPDATE_GOLDEN=1 composer test` and review the diff.
- Real-data regression: the Adobe registry sample and the real `patches.json` constraint strings are fixtures.

## TDD loop

1. Write the failing test named after the requirement (`test_it_…`).
2. Make it pass with the simplest code.
3. Refactor; run `composer check` before marking the task done.

## Test doubles

`tests/Double/` holds hand-written fakes for the `Infrastructure` interfaces (`InMemoryFilesystem` or a
temp-dir helper, `FakeHttpClient` with canned responses and a request log, `FrozenClock`,
`FakeProcessRunner`). Prefer fakes to mocks. No mocking library.

## Fixtures

- `tests/Fixtures/registry/` — synthetic registries. The main one models a release line `9.9.9` with
  three chained CE patches (`999-A` ← `999-B` ← `999-C`, where B and C touch a file A touches), one EE
  patch with `entitlements: ["ee"]`, one standalone hotfix, and one patch for another area.
- `tests/Fixtures/diffs/` — the matching tiny diffs, with real sha256 values computed by a helper.
- `tests/Fixtures/stores/<name>/` — tiny fake Magento roots: `composer.json`, `composer.lock`, a few
  `vendor/magento/...` files the diffs touch, optionally `vendor/magento/magento-cloud-patches/patches.json`
  + `patches/` and `m2-hotfixes/`. A store exists for each state in the architecture's state table.
- Fixture stores are copied to a temp dir before a test touches them; tests never modify fixtures.
- `.claude/plans/patchline/reference/patch-registry.sample.json` is a real excerpt of Adobe's registry, to
  check the parser against real data (copy it into `tests/Fixtures/registry/adobe-sample.json`).

## Real-world smoke test (manual, not in CI)

Before a release, run `patchline status` and `patchline verify` read-only against a real Magento store you have
locally (a checkout with `vendor/` installed), with `--cache-dir=$(mktemp -d)` so nothing is written into it.
Where the store has Adobe's `vendor/bin/patch-status`, add `--cross-check`: both tools must agree. Never run
`sync` against a store you do not own. Do not commit store paths, names or output from such runs.
