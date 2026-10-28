# Patchline

[![CI](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml/badge.svg)](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml)

A companion to Adobe's Quality Patches Tool for Magento Open Source.

Since `magento/magento-cloud-patches` 1.1.18, Adobe's monthly security patches no longer reach Open Source through
that package. Patchline downloads them into `m2-hotfixes/`. `ece-patches apply` still does the patching.

## Quick start

```bash
composer require --dev pushonltd/magento-patchline
vendor/bin/patchline status    # applied, queued or missing
vendor/bin/patchline sync      # download the missing ones into m2-hotfixes/
vendor/bin/patchline verify    # will ece-patches apply succeed?
```

Add the wiring below, commit `m2-hotfixes/` and deploy as usual.

## Recommended wiring

In the store's `composer.json`:

```json
"scripts": {
    "post-install-cmd": ["@apply-patches"],
    "post-update-cmd": ["@apply-patches"],
    "apply-patches": ["vendor/bin/patchline rewind", "vendor/bin/ece-patches apply"]
}
```

Every monthly patch rewrites `vendor/bin/patch-status`.
So stock `ece-patches apply` works once, on a freshly unpacked `vendor/`.
Run it again on the same `vendor/` and it fails on the older monthly patch.
That is every `composer install` on a local DDEV or a host that keeps `vendor/` between deploys.

**`patchline rewind` takes the queue back out first.** Then `ece-patches apply` starts clean every time.
On a fresh install there is nothing to rewind, and it exits 0.

## Where the patches come from

Straight from Adobe.

Patchline reads Adobe's registry at
[`repo.magento.com/patch/patch-registry.json`](https://repo.magento.com/patch/patch-registry.json)
and downloads each diff from `repo.magento.com/patch/`.
Every file is checked against the sha256 in the registry.
It is the same registry Adobe's own `vendor/bin/patch-status` reads.

**There is no third party in between.** No mirror, no hosted service, no patches of ours.
That is deliberate. A security patch should come from the vendor who wrote it, and you should be able to check that it did.

Adobe's Engineering Manager for Adobe Commerce Security and Compliance confirmed the registry is meant for public,
automated use. Commerce-only patches need repo.magento.com keys.

## Install

Magento 2.4.6+, PHP 8.2+ and a `patch` binary. Patchline only uses libraries Magento already installs.

```bash
composer require --dev pushonltd/magento-patchline
```

Or take `patchline.phar` from the [releases](https://github.com/pushonltd/magento-patchline/releases) and check it
with `sha256sum -c patchline.phar.sha256`. It runs without `composer install`.

## Commands

```text
patchline status [options]
patchline sync [options]
patchline verify [options]
patchline rewind [--root=ROOT] [--dry-run]
```

### `status`

Lists every registry patch for your Magento version and its state. Read-only.

```text
$ patchline status
Magento Open Source 2.4.9
Registry: remote
Components: CE 2.4.9

2026-09-08  CE  249-2026-09-001-CE  applied  1 CVE
2026-10-13  CE  249-2026-10-001-CE  queued   m2-hotfixes/2026-10-13_249-2026-10-001-CE.patch  2 CVEs
2026-10-20  CE  249-VULN-39341-CE   missing  1 CVE

Summary: 1 applied, 1 queued, 1 missing
Next: run patchline sync to download the missing patches.
```

### `sync`

Downloads missing patches as `m2-hotfixes/<released>_<id>.patch`.
The date prefix keeps release order, which `ece-patches apply` relies on.
It never overwrites a file and never touches `vendor/`.

### `verify`

Replays the queue on a temporary copy, the way a fresh deploy would, and names the first patch that fails.

### `rewind`

Reverses the queue in `vendor/`, newest first. It is the only command that writes to `vendor/`.

It replays the whole queue on a temporary copy before it touches anything.
If that replay fails, it changes nothing and exits 1.
It works offline and reads only `--root` and `--dry-run`.

A patch that is applied in `vendor/` but missing from the queue blocks it.
A deleted `m2-hotfixes/` file is the usual cause. Run `patchline sync` and try again.

Between `rewind` and `ece-patches apply`, `vendor/` is unpatched.
**Don't run the pair against a docroot that serves traffic.** Run it in the build step.

### Options

| Option | Commands | Meaning |
|---|---|---|
| `--root=ROOT` | all | Store root (default: current directory) |
| `--format=FORMAT` | all but `rewind` | `text`, `json`, `github` or `junit` (`github` by default on GitHub Actions) |
| `--fail-on=FAIL-ON` | all but `rewind` | States that exit with 1 (default `missing,duplicate,misordered`; also `cross-check`) |
| `--registry=REGISTRY` | all but `rewind` | Registry URL or file instead of repo.magento.com |
| `--cache-dir=CACHE-DIR` | all but `rewind` | Cache directory (default `<root>/var/patch_metadata`, shared with `patch-status`) |
| `--no-cache` | all but `rewind` | Don't use cached files |
| `--include-entitled` | all but `rewind` | Include Commerce-only patches (needs repo.magento.com keys) |
| `--cross-check` | all but `rewind` | Compare the result with `vendor/bin/patch-status` |
| `--dry-run` | `sync`, `rewind` | Show the plan, change nothing |
| `--adopt` | `sync` | Rename hand-copied hotfix files to the dated naming |
| `--prune` | `sync` | Remove hotfix files that duplicate cloud-patches files |

## Patch states

| State | Meaning |
|---|---|
| `applied` | Already in the code |
| `queued` | Will be applied by `ece-patches apply` |
| `missing` | Not in the code and not queued |
| `duplicate` | Queued more than once, which makes `ece-patches apply` fail |
| `misordered` | Queued before a patch it depends on |
| `entitled` | Commerce-only patch, skipped without `--include-entitled` |
| `unknown` | Could not be checked, e.g. locally modified files |
| `not_applicable` | Only touches packages your store has removed |

`missing`, `duplicate` and `misordered` fail the run by default.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | All good |
| 1 | Something needs action (see `--fail-on`), `sync` or `verify` failed, or `rewind` was blocked |
| 2 | Wrong usage |
| 3 | Could not run: no registry, no `patch` binary, unreadable store, no `magento-cloud-patches` for `rewind` |

## Configuration

Flags can also come from `PATCHLINE_*` environment variables or from `extra.patchline` in the store's
`composer.json`. Flags win over the environment, the environment over `composer.json`.

```json
{ "extra": { "patchline": { "fail-on": ["missing", "duplicate"] } } }
```

Repo.magento.com keys come from `COMPOSER_AUTH` or `auth.json` and are only ever sent to repo.magento.com.
Proxies come from `HTTPS_PROXY`.

## CI

Two jobs cover it.

- **A daily sync.** Run `patchline sync --adopt --prune` on a schedule and open a pull request when `m2-hotfixes/`
  changes. Daily, because Adobe ships hotfixes without a fixed date.
- **A check on every pull request.** Run `patchline status` and `patchline verify`. `--format=github` adds
  annotations and a job summary. `--format=junit` works with GitLab and Jenkins.

## How it works

Detection follows Adobe's `patch-status`.
Each patch is dry-run forwards and in reverse with `patch`, on temporary copies of the files it changes.
Patchline also reads the queue `ece-patches apply` will use: required cloud patches, then `m2-hotfixes/*.patch`.

## Development

```bash
composer check      # code style, PHPStan level 10, tests
composer build      # dist/patchline.phar
composer mutation   # mutation tests
```

## License

MIT, © 2026 [PushON Ltd](https://www.pushon.co.uk/).
