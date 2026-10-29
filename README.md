<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/logo-dark.svg">
  <img src="docs/logo-light.svg" alt="pushonltd/patchline" width="560">
</picture>

**Adobe's isolated security patches for Magento Open Source.<br>Checked, downloaded and applied on every deploy.**

[![CI](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml/badge.svg)](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/pushonltd/magento-patchline)](https://packagist.org/packages/pushonltd/magento-patchline)
[![PHP](https://img.shields.io/packagist/dependency-v/pushonltd/magento-patchline/php)](composer.json)
[![License](https://img.shields.io/packagist/l/pushonltd/magento-patchline)](LICENSE)

[Quick start](#quick-start) ·
[Recommended wiring](#recommended-wiring) ·
[Where the patches come from](#where-the-patches-come-from) ·
[Commands](#commands)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/status-dark.svg">
  <img src="docs/status-light.svg" alt="patchline status: one patch applied, one queued in m2-hotfixes/, one missing" width="760">
</picture>

</div>

---

Since `magento/magento-cloud-patches` 1.1.18, Adobe's security patches no longer reach Open Source.
**Patchline finds the missing ones, drops them into `m2-hotfixes/`, and `ece-patches apply` does the rest.**
A companion to [`magento/quality-patches`](https://github.com/magento/quality-patches).

## Quick start

```bash
curl -fsSL https://github.com/pushonltd/magento-patchline/releases/latest/download/install.sh | bash
php patchline.phar status    # applied, queued or missing
php patchline.phar sync      # download the missing ones into m2-hotfixes/
php patchline.phar verify    # will ece-patches apply succeed?
```

Commit `m2-hotfixes/`. To apply the patches on every `composer install`, add the [wiring](#recommended-wiring).

## Recommended wiring

```bash
composer require pushonltd/magento-patchline   # not --dev: the scripts also run on install --no-dev
```

In the store's `composer.json`:

```json
"scripts": {
    "post-install-cmd": ["@apply-patches"],
    "post-update-cmd": ["@apply-patches"],
    "apply-patches": ["vendor/bin/patchline rewind", "vendor/bin/ece-patches apply"]
}
```

- **`rewind` first**: stock `ece-patches apply` fails on an already patched `vendor/` (DDEV, hosts that keep
  `vendor/`). On a fresh install `rewind` is a no-op.
- **Not `status`**: it goes online and exits 1 on every new Adobe patch, breaking `composer install`. Use [CI](#ci).

## Where the patches come from

**Straight from Adobe. There is no third party in between:** no mirror, no hosted service, no patches of ours.

- Registry: [`repo.magento.com/patch/patch-registry.json`](https://repo.magento.com/patch/patch-registry.json),
  the one Adobe's own `patch-status` reads. Confirmed by Adobe as meant for public, automated use.
- Diffs: `repo.magento.com/patch/`, each checked against the registry's sha256.
- Commerce-only patches need repo.magento.com keys.

A security patch should come from the vendor who wrote it, and you should be able to check that it did.
Thanks to [@s-renz](https://github.com/s-renz) for finding the endpoint (and a great chat at
[Meet Magento Germany 2026](https://de.meet-magento.com/)).

## Install

Magento 2.4.6+, PHP 8.2+, a `patch` binary. No dependencies beyond what Magento already installs.

- **PHAR** (check and sync): the [Quick start](#quick-start) one-liner. Verifies the sha256, plus build provenance
  if `gh` is logged in. Pin with `PATCHLINE_VERSION=v0.0.3`, relocate with `bash -s -- --path=/usr/local/bin/patchline`.
- **Composer** (apply on install): see [Recommended wiring](#recommended-wiring).
- Update notice at most once a day; off with `--no-update-check` or `PATCHLINE_NO_UPDATE_CHECK=1`.

## Commands

```text
patchline status [options]
patchline sync [options]
patchline verify [options]
patchline rewind [--root=ROOT] [--dry-run]
```

### `status`

Every registry patch for your Magento version and its [state](#patch-states) (screenshot above). Read-only.

### `sync`

Downloads missing patches as `m2-hotfixes/<released>_<id>.patch`; the date prefix keeps the release order
`ece-patches apply` needs. Never overwrites, never touches `vendor/`.

### `verify`

Replays the queue on a temporary copy, like a fresh deploy, and names the first patch that fails.

### `rewind`

Reverses the queue in `vendor/`, newest first. **It is the only command that writes to `vendor/`.**

- Rehearses on a temporary copy first; if that fails, changes nothing and exits 1. Offline.
- Blocked by a patch applied in `vendor/` but missing from the queue (usually a deleted hotfix file): run `sync`.
- `vendor/` is unpatched until `ece-patches apply` runs. **Build step only, never a live docroot.**

### Options

| Option | Commands | Meaning |
|---|---|---|
| `--root=ROOT` | all | Store root (default: current directory) |
| `--format=FORMAT` | all but `rewind` | `text`, `json`, `github` or `junit` (default `github` on GitHub Actions) |
| `--fail-on=FAIL-ON` | all but `rewind` | States that exit 1 (default `missing,duplicate,misordered`; also `cross-check`) |
| `--registry=REGISTRY` | all but `rewind` | Registry URL or file instead of repo.magento.com |
| `--cache-dir=CACHE-DIR` | all but `rewind` | Cache directory (default `<root>/var/patch_metadata`, shared with `patch-status`) |
| `--no-cache` | all but `rewind` | Don't use cached files |
| `--include-entitled` | all but `rewind` | Include Commerce-only patches (needs repo.magento.com keys) |
| `--cross-check` | all but `rewind` | Compare the result with `vendor/bin/patch-status` |
| `--no-update-check` | all but `rewind` | Don't check for a newer Patchline release |
| `--dry-run` | `sync`, `rewind` | Show the plan, change nothing |
| `--adopt` | `sync` | Rename hand-copied hotfix files to the dated naming |
| `--prune` | `sync` | Remove hotfix files that duplicate cloud-patches files |

## Patch states

| State | Meaning |
|---|---|
| `applied` | 🟢 Already in the code |
| `queued` | 🟢 Will be applied by `ece-patches apply` |
| `missing` | 🔴 Not in the code and not queued |
| `duplicate` | 🔴 Queued more than once, which makes `ece-patches apply` fail |
| `misordered` | 🔴 Queued before a patch it depends on |
| `entitled` | 🟡 Commerce-only, skipped without `--include-entitled` |
| `unknown` | 🟡 Could not be checked, e.g. locally modified files |
| `not_applicable` | ⚪ Only touches packages your store has removed |

🔴 states fail the run by default (see `--fail-on`).

## Exit codes

| Code | Meaning |
|---|---|
| 0 | All good |
| 1 | Needs action (see `--fail-on`), `sync` or `verify` failed, or `rewind` was blocked |
| 2 | Wrong usage |
| 3 | Could not run: no registry, no `patch` binary, unreadable store, or no `magento-cloud-patches` for `rewind` |

## Configuration

Any flag also works as a `PATCHLINE_*` variable or under `extra.patchline` in `composer.json`.
Precedence: **flag › environment › `composer.json`**.

```json
{ "extra": { "patchline": { "fail-on": ["missing", "duplicate"] } } }
```

Keys come from `COMPOSER_AUTH` or `auth.json` and go only to repo.magento.com. Proxy: `HTTPS_PROXY`.

## CI

- **Daily:** `sync --adopt --prune`, open a PR if `m2-hotfixes/` changed. Adobe has no fixed release day.
- **Every PR:** `status` and `verify`. `--format=github` adds annotations and a job summary; `junit` suits GitLab and Jenkins.

## How it works

Like Adobe's `patch-status`: each patch is dry-run forwards and in reverse with `patch` on temporary copies,
then matched against the queue `ece-patches apply` will use.

## Development

```bash
composer check      # code style, PHPStan level 10, tests
composer build      # dist/patchline.phar
composer mutation   # mutation tests
```

## Authors

- [PushON Ltd](https://www.pushon.co.uk/)
- Jiří Brada ([@jbrada](https://github.com/jbrada))

## License

MIT, © 2026 [PushON Ltd](https://www.pushon.co.uk/).
