# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The README shows two ways in: the released PHAR to check and sync, and a Composer install
  (`composer require pushonltd/magento-patchline`, not `--dev`) for the `composer install` wiring, which calls
  `vendor/bin/patchline rewind` again.
- CI runs only what a change needs: PHP checks for code, tests and tool configuration; the README tests for
  `README.md`; ShellCheck and the install tests for `install.sh`; nothing for other Markdown files.

## [0.0.3] - 2026-10-29

### Added

- `status`, `sync` and `verify` say when a newer release exists, with the command to update. Checked against
  Packagist at most once a day and silent offline. It never changes the exit code or the text, json and junit reports:
  the notice goes to stderr, or is a `::notice` annotation with `--format=github`. Turn it off with
  `--no-update-check`, `PATCHLINE_NO_UPDATE_CHECK=1` or `extra.patchline.no-update-check`. Older releases reject that
  `extra` key ("Unknown key", exit 2), so upgrade before adding it.
- `install.sh` release asset: installs the latest (or `PATCHLINE_VERSION`) `patchline.phar`, checks its sha256 and,
  with `gh` installed and logged in, its build provenance.
- CI runs ShellCheck on `install.sh`.

### Changed

- The README recommends the released PHAR (`install.sh`); Composer is the alternative. The recommended wiring calls
  `php patchline.phar rewind`.

## [0.0.2] - 2026-10-28

### Added

- `patchline` and `patchline list` open with a `pushonltd/` + `patchline` banner and the version.
- Jiří Brada listed as an author next to PushON Ltd.

### Changed

- The version comes from Composer's install metadata instead of a constant: the release tag without its `v`, or the
  branch plus the short commit for a development install. The PHAR reports the version it was built from the same way.
- Requires `composer-runtime-api` `^2.0` (Composer 2, which every supported Magento version needs).
- Accepts `guzzlehttp/guzzle` 8 as well as 7.4.5 and later.

## [0.0.1] - 2026-10-28

### Added

- `patchline status`: reports which of Adobe's isolated security patches are applied, queued, missing or need an
  entitlement, in `text`, `json`, `github` and `junit` formats.
- `patchline sync`: downloads missing patches into `m2-hotfixes/` (sha256-verified), with `--dry-run`, `--adopt`
  (rename hand-copied files) and `--prune` (remove duplicates of cloud-patches files).
- `patchline verify`: replays the patch queue on a copy of the store to predict whether `ece-patches apply` succeeds.
- `patchline rewind`: reverses the patch queue in `vendor/` (after a dry run on a temporary copy) so `ece-patches apply`
  can run again on a persistent `vendor/`; `--dry-run` shows the plan.
- `--cross-check` against Adobe's `vendor/bin/patch-status`.
- Configuration through flags, `PATCHLINE_*` environment variables and `extra.patchline` in `composer.json`.
- Self-contained `patchline.phar` with a checksum.

[Unreleased]: https://github.com/pushonltd/magento-patchline/compare/v0.0.3...HEAD
[0.0.3]: https://github.com/pushonltd/magento-patchline/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/pushonltd/magento-patchline/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/pushonltd/magento-patchline/releases/tag/v0.0.1
