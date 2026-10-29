# CLAUDE.md

Patchline (`pushonltd/magento-patchline`) — a standalone PHP 8.2+ CLI that checks Magento stores against
Adobe's isolated security patch registry and syncs missing patches into `m2-hotfixes/`.

Read before changing code:
- `.claude/architecture.md` — what it does, namespaces, patch states, design rules
- `.claude/code-standards.md` — PHPStan level 10 + strict rules, PHPCS PSR-12 + slevomat, PHP 8.2 floor, allowed dependencies
- `.claude/testing.md` — suites, fixtures, test doubles

`composer check` must pass before every commit. Commits use Conventional Commits and never carry
`Co-Authored-By` or AI attribution trailers.

## Feature development

For any feature or change beyond a simple fix, use the `hcf:plan-create` skill; run plans with
`hcf:plan-orchestrate`.

## Releasing

A pushed `vX.Y.Z` tag is the release. `.github/workflows/release.yml` builds `dist/patchline.phar` on PHP 8.2 with
the tag as its version, runs the CLI suite against the PHAR, attests its build provenance and creates the GitHub
release with `patchline.phar`, `patchline.phar.sha256` and `install.sh`. Packagist picks up the tag. No file holds
the version (`PackageVersion` reads it from Composer metadata), so a release only touches `CHANGELOG.md`.

1. Start from an up-to-date `main` with green CI. Pick the version by SemVer from the `[Unreleased]` entries.
2. Run the real-world smoke test from `.claude/testing.md`.
3. In `CHANGELOG.md`, add `## [X.Y.Z] - YYYY-MM-DD` under an emptied `## [Unreleased]`. At the bottom, point
   `[Unreleased]` at `vX.Y.Z...HEAD` and add `[X.Y.Z]: …/compare/vPREVIOUS...vX.Y.Z`.
4. Commit `chore: release X.Y.Z` on `main`, then tag and push both:
   `git tag -a vX.Y.Z -m "Patchline X.Y.Z" && git push origin main vX.Y.Z`.
5. Check that the Release workflow passed, that the release has all three assets, and that
   `PATCHLINE_VERSION=vX.Y.Z` with `install.sh` installs it.

Never move or delete a published tag. Fix a bad release with a new patch release.
