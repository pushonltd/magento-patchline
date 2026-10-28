# Patchline

[![CI](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml/badge.svg)](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml)

Patchline checks and syncs Adobe's isolated security patches for a Magento store. It reads Adobe's public patch
registry, works out which patches your installed code already contains and which ones `ece-patches apply`
(`magento/magento-cloud-patches`) will apply, and downloads the missing ones into `m2-hotfixes/`. It never applies
patches itself and never writes to `vendor/`.

It exists because `magento-cloud-patches` stopped shipping Adobe's monthly isolated security patches for Magento
Open Source after version 1.1.18. The registry Patchline reads (`https://repo.magento.com/patch/patch-registry.json`)
is, according to Adobe, intended for public, automated use, and Patchline stays compatible with Adobe's own checker,
`vendor/bin/patch-status`.

## Quick start

```bash
composer require --dev pushonltd/magento-patchline

vendor/bin/patchline status            # what is applied, queued or missing?
vendor/bin/patchline sync --dry-run    # what would be downloaded?
vendor/bin/patchline sync              # download into m2-hotfixes/
vendor/bin/patchline verify            # will ece-patches apply succeed on a fresh install?
```

Example on a store where nothing is patched yet (a synthetic test store, run with `--registry` pointing at a fixture
registry):

```text
$ patchline status
Magento Open Source 2.4.9
Registry: file
Components: CE 2.4.9

2026-01-13  CE  249-2026-01-001-CE  missing   1 CVE
2026-02-10  CE  249-2026-02-001-CE  missing   1 CVE
2026-03-10  CE  249-2026-03-001-CE  entitled  1 CVE
2026-04-14  CE  249-2026-04-001-CE  missing   1 CVE

Warnings
  - 249-2026-03-001-CE: The diff of 249-2026-03-001-CE could not be downloaded (needs repo.magento.com credentials); its state is unknown.

Summary: 3 missing, 1 entitled
Next: run patchline sync to download the missing patches.
```

```text
$ patchline sync --dry-run
...
Sync (dry run, nothing changed)
  [planned] download 249-2026-01-001-CE to m2-hotfixes/2026-01-13_249-2026-01-001-CE.patch
  [planned] download 249-2026-02-001-CE to m2-hotfixes/2026-02-10_249-2026-02-001-CE.patch
  [planned] download 249-2026-04-001-CE to m2-hotfixes/2026-04-14_249-2026-04-001-CE.patch
  [warning] 249-2026-03-001-CE needs an entitlement this store may not have, so it was not downloaded. Run sync with --include-entitled if you are entitled to it.
```

## Installation

### Requirements

- PHP 8.1 or newer with `ext-json`, `ext-hash` and `ext-openssl`.
- A `patch` program on the `PATH` (GNU patch on Linux, the system `patch` on macOS). Detection and `verify` need it.
- Runtime dependencies are only libraries that every Magento 2.4.4 to 2.4.9 store already installs:
  `composer/semver`, `guzzlehttp/guzzle`, `symfony/console` and `symfony/process` (constraints in `composer.json`).
- Behind a proxy, set `HTTPS_PROXY` (and `NO_PROXY` if needed).

### With Composer

```bash
composer require --dev pushonltd/magento-patchline
```

### As a single file

Download `patchline.phar` and `patchline.phar.sha256` from the
[GitHub releases](https://github.com/pushonltd/magento-patchline/releases), verify, and run:

```bash
sha256sum -c patchline.phar.sha256
php patchline.phar status --root=/path/to/store
```

The PHAR bundles its dependencies and runs before or without `composer install`.

## Commands

```text
patchline status [options]
patchline sync [options]
patchline verify [options]
patchline --help
patchline --version
```

### `status`

Shows which registry patches are applied, queued or missing for the installed Magento version. Read-only.

### `sync`

Downloads missing patches into `m2-hotfixes/`, checking each diff against the sha256 in the registry. Files are
written atomically and an existing file is never overwritten.

### `verify`

Replays the patch queue (cloud-patches required patches, then `m2-hotfixes/*.patch`) on a fresh copy of the files it
touches, to predict whether `ece-patches apply` will succeed. It also notes when re-running the queue on the
already patched `vendor/` would fail, which a fresh `composer install` does not do.

### Options

| Option | Commands | Meaning |
|---|---|---|
| `--root=ROOT` | all | Store root (default: current directory). |
| `--format=FORMAT` | all | `text`, `json`, `github` or `junit` (default `text`, `github` inside GitHub Actions). |
| `--fail-on=FAIL-ON` | all | Comma-separated states that make the exit code 1 (default `missing,duplicate,misordered`; `cross-check` is also accepted). |
| `--registry=REGISTRY` | all | Use this registry URL or file instead of repo.magento.com. |
| `--cache-dir=CACHE-DIR` | all | Cache for the registry and diffs (default `<root>/var/patch_metadata`). |
| `--no-cache` | all | Ignore cached registry and diffs. |
| `--include-entitled` | all | Also handle patches that need an entitlement (`ee`, `b2b`). |
| `--cross-check` | all | Compare with `vendor/bin/patch-status`. |
| `--dry-run` | `sync` | Show what would change without writing anything. |
| `--adopt` | `sync` | Rename existing hotfix files to the registry naming. |
| `--prune` | `sync` | Remove hotfix files that are no longer needed (duplicates of cloud-patches files). |

### File naming

Synced files are named `m2-hotfixes/<released>_<id>.patch`, for example
`m2-hotfixes/2026-10-13_249-2026-10-001-CE.patch`. `ece-patches apply` applies `m2-hotfixes/*.patch` in byte order,
so the date prefix keeps the release order, and with it each patch's `requires` chain, intact. `--adopt` renames
files you copied by hand to this naming.

## Patch states

| State | Meaning |
|---|---|
| `applied` | The installed code already contains the patch. |
| `queued` | Not in the code yet, but `ece-patches apply` will apply it exactly once, after every patch it requires. |
| `duplicate` | The same diff is queued more than once (cloud-patches and `m2-hotfixes/`, or two hotfix files). Fails by default. |
| `misordered` | Queued before a patch it requires. Fails by default. |
| `missing` | Not in the code and not queued. Fails by default. |
| `entitled` | Missing, and needs an entitlement (`ee`, `b2b`) the store lacks; skipped unless `--include-entitled`. Reported only. |
| `unknown` | Detection could not tell (both dry runs fail or the diff is unavailable). Reported only. |
| `not_applicable` | Every file it changes belongs to a package the store replaces. |

## Exit codes

| Code | Meaning |
|---|---|
| 0 | OK |
| 1 | Findings matching `--fail-on`, or a failed `sync` or `verify` |
| 2 | Usage error |
| 3 | Runtime error: registry unreachable without a cache, no `patch` program, unreadable store, I/O failure |

## Configuration

Precedence: command-line flag, then `PATCHLINE_*` environment variable, then `extra.patchline` in the store's
`composer.json`, then the default.

| Setting | Flag | Environment variable | `extra.patchline` key |
|---|---|---|---|
| Output format | `--format` | `PATCHLINE_FORMAT` | `format` |
| Failing states | `--fail-on` | `PATCHLINE_FAIL_ON` | `fail-on` (list or string) |
| Registry URL or file | `--registry` | `PATCHLINE_REGISTRY` | `registry` |
| Cache directory | `--cache-dir` | `PATCHLINE_CACHE_DIR` | `cache-dir` |
| Ignore caches | `--no-cache` | `PATCHLINE_NO_CACHE` (`1`, `0`, `true`, `false`) | `no-cache` |
| Entitled patches | `--include-entitled` | `PATCHLINE_INCLUDE_ENTITLED` | `include-entitled` |
| Cross-check | `--cross-check` | - | `cross-check` |

```json
{
    "extra": {
        "patchline": {
            "fail-on": ["missing", "duplicate", "misordered"],
            "include-entitled": false
        }
    }
}
```

## Credentials and security

Credentials are needed only for entitled (Commerce) patches. Patchline reads `COMPOSER_AUTH` or the
`http-basic.repo.magento.com` entry of `auth.json`, sends them only to `repo.magento.com` over HTTPS, and never
prints them. Every downloaded diff is checked against the sha256 in the registry.

## CI usage

Patchline prints GitHub annotations and a job summary (`--format=github`) and JUnit XML for GitLab, Jenkins and
similar systems (`--format=junit`). Ready-made pipelines are in [`examples/`](examples/) and described in
[`docs/ci.md`](docs/ci.md): a weekly GitHub workflow that opens a pull request with new patches, a GitLab job, and a
Composer hook that runs `status` before patches are applied. Keep `sync` out of deploy; run it on a schedule and
review the result.

## How detection works

Patchline ports the algorithm of Adobe's `patch-status`. For each patch it runs `patch` twice on temporary copies of
the files the diff touches: a forward dry run (can it be applied?) and a reverse dry run (is it already there?).
Both GNU and BSD `patch` are supported. Nothing is written to `vendor/`.

Whether a patch will be applied at deploy is a separate question, answered from the queue: the required patches of
`magento/magento-cloud-patches` and `m2-hotfixes/*.patch`. Patchline tracks only what `ece-patches apply` applies.
A patch applied some other way is seen as `applied` through detection only.

## Compatibility with Adobe's tools

- The registry and diff caches live in `var/patch_metadata`, as with `patch-status`, so the two share downloads.
- `--cross-check` runs `vendor/bin/patch-status --format=json` and lists every patch where the two tools disagree.
- Every monthly patch rewrites `vendor/bin/patch-status`, so consecutive patches overlap on that file. When replaying
  the queue, `verify` ignores that overlap and says so; stock `magento-cloud-patches` does not, so `ece-patches
  apply` may fail on it.

## Development

```bash
composer install
composer check      # validate, code style, PHPStan level 10, tests with coverage
composer build      # build dist/patchline.phar with Box
composer mutation   # mutation tests (Infection)
```

The gates are at least 95 % line coverage and a mutation score of at least 85 %; see [`docs/ci.md`](docs/ci.md). Before
a release the tool is also run read-only (`status`, `verify`, with a temporary `--cache-dir`) against a real Magento
checkout, and against Adobe's `vendor/bin/patch-status` with `--cross-check`.

Issues and source: https://github.com/pushonltd/magento-patchline.

## License

MIT. Copyright (c) 2026 [PushON Ltd](https://www.pushon.co.uk/). See [`LICENSE`](LICENSE).
