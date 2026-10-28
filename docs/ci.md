# Running Patchline in a pipeline

Copy-ready examples live in `examples/`:

| File | What it does |
|---|---|
| `examples/github/patchline-sync.yml` | Daily GitHub workflow that runs `sync`, `verify` and opens a pull request with the new patches. |
| `examples/gitlab/.gitlab-ci.patchline.yml` | `status` job with a JUnit report, plus a scheduled `sync` job. |
| `examples/composer-scripts.json` | Composer `post-install-cmd` hook that runs the read-only `status`, then `@apply-patches` (`patchline rewind` before `ece-patches apply`). |

Merge `examples/composer-scripts.json` into your `composer.json`; adjust `apply-patches` to whatever your project
already calls. Keep `sync` out of deploy. It writes to `m2-hotfixes/` and needs the network. Run it in a scheduled pipeline,
review the resulting pull request and let the normal deploy only run `status`.

## Rewinding before `ece-patches apply`

`"apply-patches": ["patchline rewind", "ece-patches apply"]` makes `ece-patches apply` re-runnable on a `vendor/`
that already carries the patches (local development, Hypernode, any host that keeps `vendor/` between deploys). On a
fresh install rewind is a no-op. Rewind exits 1 when it is blocked (for example by a patch applied outside the
queue); it then changes nothing and the script list stops.

Caveat: between `patchline rewind` and `ece-patches apply`, `vendor/` is unpatched. A live store serving from that
`vendor/` may serve unpatched or half-reverted code. Run the pair in a build step or maintenance window, never
against the live docroot while it serves traffic. The deploy must fail if `ece-patches apply` fails; Composer stops
a script list at the first non-zero exit.

## Self-contained PHAR

Every release attaches `patchline.phar` and `patchline.phar.sha256`. The PHAR bundles its dependencies, runs with
plain `php patchline.phar` on PHP 8.2+ before or without `composer install`, and needs no `vendor/` directory.
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
| 1 | Findings matching `--fail-on` (default `missing,duplicate,misordered`), a failed `sync` or `verify`, or a blocked `rewind` |
| 2 | Usage error |
| 3 | Runtime error: registry unreachable without a cache, no `patch` program, unreadable store, I/O failure |

## Caching

Patchline caches the registry (one hour) and the downloaded diffs in `var/patch_metadata`. Cache that directory
between runs, or point `--cache-dir` (or `PATCHLINE_CACHE_DIR`) at a directory your CI already caches. Diffs
are always re-verified by sha256, so a stale cache cannot install a wrong patch. Do not cache in release
workflows: build from a clean checkout there.

## Scheduling

Adobe has no fixed release date. Monthly patches usually arrive around the second Tuesday, but out-of-band
hotfixes can be published on any day, so the GitHub example runs **daily** (`0 6 * * *`) and only opens or updates
a pull request when something changed; trigger it by hand (`workflow_dispatch`) right after an Adobe security
bulletin. If you schedule differently, do not combine day-of-month and day-of-week (e.g. `0 18 8-14 * 2`): cron
runs when either matches.

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
