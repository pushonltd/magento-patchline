# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `patchline status`: reports which of Adobe's isolated security patches are applied, queued, missing or need an
  entitlement, in `text`, `json`, `github` and `junit` formats.
- `patchline sync`: downloads missing patches into `m2-hotfixes/` (sha256-verified), with `--dry-run`, `--adopt`
  (rename hand-copied files) and `--prune` (remove duplicates of cloud-patches files).
- `patchline verify`: replays the patch queue on a copy of the store to predict whether `ece-patches apply` succeeds.
- `--cross-check` against Adobe's `vendor/bin/patch-status`.
- Configuration through flags, `PATCHLINE_*` environment variables and `extra.patchline` in `composer.json`.
- Self-contained `patchline.phar` with a checksum, CI examples for GitHub Actions and GitLab, and `docs/ci.md`.
