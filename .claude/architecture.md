# Patchline architecture

Patchline (`pushonltd/magento-patchline`, command `patchline`, repository https://github.com/pushonltd/magento-patchline, by PushON Ltd) checks and syncs Adobe's isolated security
patches for a Magento store. It reads Adobe's public registry
(`https://repo.magento.com/patch/patch-registry.json`), works out which patches the installed code
already contains, which ones `ece-patches apply` (magento/magento-cloud-patches) will apply, and
downloads the missing ones into `m2-hotfixes/`.

It exists because magento-cloud-patches stopped shipping monthly security patches to Magento Open
Source after 1.1.18. Adobe confirmed the registry is intended for public automated use and stays
compatible with its own checker, `vendor/bin/patch-status` (the Commerce Version Tool).

## Non-goals

- Patchline never applies patches to a store. `ece-patches apply` does that.
- It never modifies `vendor/`, with one exception: `rewind` reverses the queued patches in `vendor/` so that
  `ece-patches apply`, which runs right after, can reapply them. It is the only code that writes to `vendor/`.
- It never downloads at deploy time unless asked (`sync` is explicit).
- It does not work around Commerce entitlements.
- It does not track other patching mechanisms (composer-patches, deploy scripts, hand-applied diffs, custom
  tools). Its queue is exactly what `ece-patches apply` applies: cloud-patches required patches and
  `m2-hotfixes/*.patch`. A patch applied any other way is seen only through detection (`applied`); whether it
  survives a fresh `composer install` is the project's responsibility.

## Runtime constraints

- PHP >= 8.2 (8.1 is end of life), extensions `json`, `hash`, `openssl`.
- Supported stores: Magento 2.4.6 and newer (2.4.4/2.4.5 only run on PHP 7.4/8.1, which is end of life).
- Runtime dependencies are limited to libraries **every supported Magento line (2.4.6–2.4.9) already installs**,
  with constraints loose enough to accept whichever version the store has locked:
  `composer/semver` `^3.2` (the same constraint rules ece-patches uses), `guzzlehttp/guzzle` `^7.4.5`
  (proxies, TLS, strips `Authorization` on cross-origin redirects), `symfony/console` and `symfony/process`
  `^5.4 || ^6.4 || ^7.0`. Code must work with the lowest and highest of each (CI: `--prefer-lowest`).
  No other runtime dependency may be added. `composer-runtime-api` `^2.0` is a platform requirement, not a library:
  `PackageVersion` reads the installed version from `Composer\InstalledVersions`, and the PHAR build writes the
  release version into the bundled Composer metadata, so there is no version constant to keep in sync.
- Shipped as a Composer package and as a self-contained PHAR (`dist/patchline.phar`, built with Box) that runs
  before or without `composer install`.
- Needs a `patch(1)` binary for detection and `verify` (GNU patch on Linux, Apple's patch on macOS).
- Never interactive. No prompts, ever.

## Commands

```
patchline status [--root=.] [--format=text|json|github|junit] [--fail-on=missing,duplicate,misordered]
                 [--registry=<url|file>] [--cache-dir=<dir>] [--no-cache] [--include-entitled]
                 [--cross-check]
patchline sync   [same as status] [--dry-run] [--adopt] [--prune]
patchline verify [same as status]
patchline rewind [--root=.] [--dry-run]
patchline --help | --version
```

Exit codes: `0` ok, `1` findings matching `--fail-on` (for `rewind`: blocked, nothing changed), `2` usage error, `3` runtime error
(registry unreachable with no cache, no `patch` binary, unreadable store, I/O failure; for `rewind` also
`magento-cloud-patches` not installed).

`rewind` is offline: it ignores the registry, `extra.patchline` and `PATCHLINE_*`. It simulates the whole
rewind and replay on a temporary copy of the store and commits to `vendor/` only when the queue replays cleanly;
a blocked rewind changes nothing.

Configuration precedence: CLI flag > `PATCHLINE_*` environment variable > `extra.patchline` in the
store's `composer.json` > default.

## Data flow

```
 composer.lock / installed.json ─▶ Installation ──┐
 composer.json (replace, extra)                   │
                                                  ▼
 Registry (remote | cache | file) ──▶ ComponentResolver ──▶ applicable patches ──▶ DependencyGraph
                                                  │
                    ┌─────────────────────────────┴───────────────────────────┐
                    ▼                                                         ▼
          DryRunDetector (is it in the code?)                PatchQueue (will ece-patches apply it?)
            uses PatchDownloader, DiffParser, PatchBinary      CloudPatchesSource + HotfixDirSource
                    └─────────────────────────────┬───────────────────────────┘
                                                  ▼
                                    Analyzer ──▶ Report (state per patch, CVEs)
                                                  │
                  ┌───────────────────────────────┼─────────────────────────────┐
                  ▼                               ▼                             ▼
             status: Formatter               sync: Planner ─▶ Plan ─▶ Executor  verify: QueueReplayer

 rewind (no registry, no network): PatchQueue ─▶ QueueRewinder ─▶ simulate on temp copy ─▶ commit to vendor/
```

## Namespaces (`Patchline\` → `src/`)

| Namespace | Responsibility |
|---|---|
| `Cli` | Symfony Console `Application` + `Command\{Status,Sync,Verify}`, `Console\RewindCommand` (exit codes 0/1/2/3), `Config`/`ConfigResolver` (precedence), `Output\{Text,Json,Github,Junit}Formatter`, `Output\RewindTextRenderer` |
| `Infrastructure` | Side-effect seams: `Filesystem`, `HttpClient` (Guzzle adapter), `Clock`, `ProcessRunner` (Symfony Process adapter), each an interface with one real implementation. Test doubles live in `tests/Double/`. |
| `Installation` | `Installation` (root, installed packages, replaced packages, edition, base version) built from `composer.lock`, falling back to `vendor/composer/installed.json` |
| `Registry` | `RegistryPatch`, `Area`, `Registry` value objects; `RegistryParser` (validates untyped JSON into typed objects); `RegistryLoader` (remote/cache/file); `ComponentResolver`; `DependencyGraph` |
| `Download` | `Credentials`, `PatchDownloader` (sha256-verified, shared cache) |
| `Detection` | `DiffParser`, `PatchBinary`, `DryRunDetector`, `DetectionResult` |
| `Queue` | `PatchQueue`, `QueueEntry`, `Source\CloudPatchesSource`, `Source\HotfixDirSource` |
| `Analysis` | `Analyzer`, `PatchState` (enum), `PatchReport`, `Report`, `CveStatus` |
| `Sync` | `Planner` (pure), `Plan`, `Action\{Download,Rename,Remove,Warn}`, `Executor` |
| `Verify` | `QueueReplayer`, `VerifyResult` |
| `Rewind` | `QueueRewinder` (simulate on a temp copy, then commit), `RewindResult`, `RewindStep`, `RewindOutcome` |
| `CrossCheck` | `PatchStatusCrossCheck` (runs Adobe's `vendor/bin/patch-status --format=json` and compares) |

## Patch states

| State | Detection | Queue | Default exit effect |
|---|---|---|---|
| `applied` | applied | any | ok |
| `queued` | not applied or unknown | queued exactly once, after every patch it requires | ok (`verify` judges whether it applies) |
| `duplicate` | any | the same sha256 queued more than once (cloud-required + m2-hotfixes, or two m2-hotfixes files) | fails |
| `misordered` | any (the queue is replayed on a fresh `vendor/` at deploy) | queued before a patch it `requires` | fails |
| `missing` | not applied | not queued | fails |
| `entitled` | not applied | not queued, needs an entitlement the store lacks (`ee`, `b2b`) and `--include-entitled` not given | reported only |
| `unknown` | unknown (both dry-runs fail, or diff unavailable) | not queued | reported only |
| `not_applicable` | all hunks target replaced packages | – | ok |

Precedence when several apply: `not_applicable` > `duplicate` > `misordered` > `applied` > `queued` > `entitled` > `missing` > `unknown`.
The full decision procedure is in task 014 of the patchline plan.

## File naming in m2-hotfixes

`m2-hotfixes/<released>_<registry file_name without .diff>.patch`, e.g.
`2026-10-13_249-2026-10-001-CE.patch`. ece-patches applies `m2-hotfixes/*.patch` in `glob()` (byte)
order, so the date prefix keeps release order and the `requires` chain intact across areas.

## Shared state with Adobe's patch-status

- Registry cache: `<root>/var/patch_metadata/.patch_registry_cache.json`, TTL 3600 s.
- Diff cache: `<root>/var/patch_metadata/.patch_diffs/<file_name>`; content always re-verified by sha256.
- `--cache-dir` relocates both (for CI caches).

## Design rules

- Everything that touches the outside world goes through an `Infrastructure` interface. `Planner`,
  `Analyzer`, `DependencyGraph`, `Constraint`, `DiffParser` are pure.
- Untyped data (JSON, argv, env) is validated once at the boundary into typed value objects. No `mixed`
  travels past a parser.
- Domain errors are typed exceptions under `Patchline\Exception\` (`UsageException` → exit 2,
  `RuntimeFailure` → exit 3). Nothing calls `exit()` except `bin/patchline`.
- Credentials (repo.magento.com keys) are sent only to `repo.magento.com` over HTTPS and never printed
  or logged.
- Writes are atomic (`<file>.part` then rename) and never overwrite an existing file.
- The PHAR (`dist/patchline.phar`, Box) must behave identically to `bin/patchline`; CI runs the CLI test suite
  against both.
