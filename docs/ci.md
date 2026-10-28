# Running Patchline in a pipeline

Copy-ready examples live in `examples/`:

| File | What it does |
|---|---|
| `examples/github/patchline-sync.yml` | Weekly GitHub workflow that runs `sync`, `verify` and opens a pull request with the new patches. |
| `examples/gitlab/.gitlab-ci.patchline.yml` | `status` job with a JUnit report, plus a scheduled `sync` job. |
| `examples/composer-scripts.json` | Composer `post-install-cmd` hook that runs the read-only `status` before `@apply-patches`. |

Merge `examples/composer-scripts.json` into your `composer.json`; adjust `apply-patches` to whatever your project
already calls. Keep `sync` out of deploy. It writes to `m2-hotfixes/` and needs the network. Run it in a scheduled pipeline,
review the resulting pull request and let the normal deploy only run `status`.

## Self-contained PHAR

Every release attaches `patchline.phar` and `patchline.phar.sha256`. The PHAR bundles its dependencies, runs with
plain `php patchline.phar` on PHP 8.1+ before or without `composer install`, and needs no `vendor/` directory.
Verify it with `sha256sum --check patchline.phar.sha256` or `gh attestation verify patchline.phar --repo pushonltd/magento-patchline`.
To build it yourself: `composer update --working-dir=tools/box && composer build` (output: `dist/`).

## Output formats

| Format | Use it for |
|---|---|
| `text` | Terminals (default). |
| `json` | Scripts. |
| `github` | GitHub Actions: annotations plus a job summary appended to `$GITHUB_STEP_SUMMARY` (the default inside Actions). |
| `junit` | GitLab, Jenkins and other CI systems that show test reports: `patchline status --format=junit > patchline.xml`. |

## Exit codes

| Code | Meaning |
|---|---|
| 0 | OK |
| 1 | Findings matching `--fail-on` (default `missing,duplicate,misordered`), or a failed `sync` or `verify` |
| 2 | Usage error |
| 3 | Runtime error: registry unreachable without a cache, no `patch` program, unreadable store, I/O failure |

## Caching

Patchline caches the registry (one hour) and the downloaded diffs in `var/patch_metadata`. Cache that directory
between runs, or point `--cache-dir` (or `PATCHLINE_CACHE_DIR`) at a directory your CI already caches. Diffs
are always re-verified by sha256, so a stale cache cannot install a wrong patch. Do not cache in release
workflows: build from a clean checkout there.

## Scheduling

Adobe publishes isolated patches on the second Tuesday of the month. Do not write `0 18 8-14 * 2`: when both
day-of-month and day-of-week are restricted, cron runs when either matches. The GitHub example runs every
Tuesday (`0 18 * * 2`) and only opens a pull request when something changed.

## Security notes

- The GitHub example follows the repository's zizmor rules: actions pinned to commit SHAs, `permissions: {}`
  at the top with write scopes on the one job that needs them, `persist-credentials: false`, and the write
  token passed to the pull request step only.
- Pass `COMPOSER_AUTH` from secrets only if you want entitled patches (`--include-entitled`).
- The CI zizmor job must scan the examples too: add `examples/**.yml` to its path filters and to the action's
  inputs (task 025).

## Repository CI notes

- The `--prefer-lowest` job uses `phpunit.prefer-lowest.xml.dist`: the main `phpunit.xml.dist` plus
  `restrictDeprecations="true"`, so deprecations raised inside old vendor libraries do not fail the run.
  One file cannot do this on every supported PHPUnit line (10.5 and 11.5/12 name the option differently).
- The `mutation` job runs Infection (`composer mutation`, PHP 8.5 with pcov; gates: MSI 85 %, covered MSI 90 %)
  and uploads `build/infection/infection.log` as an artifact.
- Dependabot also watches `tools/box` and `tools/infection`.
