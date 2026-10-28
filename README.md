# Patchline

[![CI](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml/badge.svg)](https://github.com/pushonltd/magento-patchline/actions/workflows/ci.yml)

Keeps a Magento store's security patches up to date. Patchline reads Adobe's public patch registry, tells you which
patches your store has, downloads the missing ones into `m2-hotfixes/` and checks that `ece-patches apply` will
accept them.

Since `magento-cloud-patches` 1.1.18, Adobe's monthly isolated patches no longer reach Magento Open Source through
that package. Patchline fills that gap.

## Data source

Patchline reads Adobe's patch registry at
[`repo.magento.com/patch/patch-registry.json`](https://repo.magento.com/patch/patch-registry.json) and downloads the
diffs it lists from `repo.magento.com/patch/`, checking each one against the sha256 in the registry. It is the same
registry Adobe's Commerce Version Tool (`vendor/bin/patch-status`) uses.

Adobe's Engineering Manager for Adobe Commerce Security and Compliance confirmed that the registry is intended for
public, automated use and should remain compatible with the Commerce Version Tool. Which patches are available for
a version depends on its licensing and extended support; Commerce-only patches need repo.magento.com keys.

## Install

Requires Magento 2.4.6+, PHP 8.2+ and a `patch` binary. It only uses libraries Magento already installs.

```bash
composer require --dev pushonltd/magento-patchline
```

Or download `patchline.phar` from the [releases](https://github.com/pushonltd/magento-patchline/releases)
(check it with `sha256sum -c patchline.phar.sha256`); it runs without `composer install`.

## Usage

Run it from the store root:

```bash
vendor/bin/patchline status                  # what is applied, queued or missing
vendor/bin/patchline sync --dry-run          # what would change
vendor/bin/patchline sync --adopt --prune    # download into m2-hotfixes/
vendor/bin/patchline verify                  # will ece-patches apply succeed?
```

Commit `m2-hotfixes/` and deploy as usual; `ece-patches apply` does the patching.

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

## Commands

```text
patchline status [options]
patchline sync [options]
patchline verify [options]
```

### `status`

Lists every registry patch for your Magento version with its state. Read-only.

### `sync`

Downloads missing patches as `m2-hotfixes/<released>_<id>.patch` and checks each against the registry's sha256.
The date prefix keeps patches in release order, which `ece-patches apply` relies on. Existing files are not
overwritten and `vendor/` is not touched.

### `verify`

Replays the patch queue on a temporary copy, as a fresh deploy would, and reports the first patch that would fail.

### Options

| Option | Commands | Meaning |
|---|---|---|
| `--root=ROOT` | all | Store root (default: current directory) |
| `--format=FORMAT` | all | `text`, `json`, `github` or `junit` (`github` by default on GitHub Actions) |
| `--fail-on=FAIL-ON` | all | States that exit with 1 (default `missing,duplicate,misordered`; also `cross-check`) |
| `--registry=REGISTRY` | all | Registry URL or file instead of repo.magento.com |
| `--cache-dir=CACHE-DIR` | all | Cache directory (default `<root>/var/patch_metadata`, shared with `patch-status`) |
| `--no-cache` | all | Don't use cached files |
| `--include-entitled` | all | Include Commerce-only patches (needs repo.magento.com keys) |
| `--cross-check` | all | Compare the result with `vendor/bin/patch-status` |
| `--dry-run` | `sync` | Show the plan, change nothing |
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
| 1 | Something needs action (see `--fail-on`), or `sync`/`verify` failed |
| 2 | Wrong usage |
| 3 | Could not run: no registry, no `patch` binary, unreadable store |

## Configuration

Flags can also be set as `PATCHLINE_*` environment variables (`PATCHLINE_FORMAT`, `PATCHLINE_FAIL_ON`, …) or in the
store's `composer.json`; flags win over environment, environment over `composer.json`:

```json
{ "extra": { "patchline": { "fail-on": ["missing", "duplicate"] } } }
```

Repo.magento.com keys (only for Commerce patches) come from `COMPOSER_AUTH` or `auth.json` and are only sent to
repo.magento.com. Proxies are picked up from `HTTPS_PROXY`.

## CI

`--format=github` adds annotations and a job summary; `--format=junit` works with GitLab and Jenkins. A daily
GitHub workflow that opens a pull request with new patches, a GitLab job and a Composer hook are in
[`examples/`](examples/), explained in [`docs/ci.md`](docs/ci.md).

## How it works

Detection follows Adobe's `patch-status`: each patch is dry-run forwards and in reverse with `patch` on temporary
copies of the files it changes. Separately, Patchline reads the queue `ece-patches apply` will use (required
cloud-patches, then `m2-hotfixes/*.patch`) to see what will be applied on the next deploy.

Monthly patches all rewrite `vendor/bin/patch-status`. `verify` ignores that overlap and tells you when stock
`magento-cloud-patches` would trip over it.

## Development

```bash
composer check      # code style, PHPStan level 10, tests
composer build      # dist/patchline.phar
composer mutation   # mutation tests
```

## License

MIT, © 2026 [PushON Ltd](https://www.pushon.co.uk/).
