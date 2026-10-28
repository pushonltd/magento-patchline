# Code standards

## Static analysis — PHPStan

- PHPStan 2.x at **level 10** (`level: 10`) with `phpstan/phpstan-strict-rules`,
  `phpstan/phpstan-deprecation-rules` and `phpstan/phpstan-phpunit`. Paths: `src`, `tests`, `build`, `bin`.
- `checkUninitializedProperties: true`, `treatPhpDocTypesAsCertain: false`,
  `reportUnmatchedIgnoredErrors: true`.
- **No baseline. No `@phpstan-ignore` without a one-line reason next to it.**
- Array shapes are spelled out (`array{id: string, sha256: string}`, `list<RegistryPatch>`). Data from JSON is
  turned into typed value objects by a parser that checks every key; nothing else handles raw arrays.

## Coding standard — PHPCS

- `squizlabs/php_codesniffer` with **PSR-12** plus `slevomat/coding-standard` rules (`phpcs.xml.dist`):
  - `declare(strict_types=1);` in every PHP file
  - parameter, property and return type hints required (native where possible)
  - no unused `use` statements; alphabetically sorted uses
  - `final` classes by default; value objects are `final readonly`-style (readonly properties or readonly classes)
  - early returns; no `else` after `return`/`throw`
  - trailing commas in multi-line arrays, calls and signatures
- `composer cs` checks, `composer cs-fix` runs `phpcbf`.

## PHP version

- Code must run on **PHP 8.2** (8.1 is end of life and not supported): no `#[\Override]`, no typed class
  constants, no `json_validate()` (all 8.3+). Enums, readonly properties and readonly classes are fine.
- No `composer.lock` is committed (library). CI resolves dependencies per PHP version and also runs once with
  `--prefer-lowest` on PHP 8.2, so the declared constraints are honest.

## Dependencies

- Only the four runtime libraries listed in `.claude/architecture.md` (all already installed by Magento).
  Use only API available in **every** allowed major (symfony/console + process 5.4 → 7.x, composer/semver 3.x,
  guzzle 7.4.5+): no `#[AsCommand]`-only registration, no newer-only methods. Library types stay at the
  edges (adapters in `Infrastructure`, commands in `Cli`); domain code never imports them.

## Naming

- Classes are nouns, methods are verbs. Value objects have no setters.
- Test classes mirror `src/` (`tests/Unit/Registry/DependencyGraphTest.php`). Test methods use the
  requirement text in snake case: `test_it_rejects_a_patch_without_sha256()`.

## Output and messages

- Messages written for people use plain language and say what to do next.
- Never print credentials, `Authorization` headers or auth.json contents.

## Before committing

`composer check` (validate, cs, analyse, test:coverage) must pass; run `composer mutation` for changes in
pure domain code. Commits follow Conventional Commits
(`feat(registry): …`). No `Co-Authored-By` or AI attribution trailers.

## GitHub Actions

Every workflow and `dependabot.yml` passes `zizmor --persona=pedantic` (CI blocks on any finding): actions
pinned to full commit SHAs with a version comment, `permissions: {}` at the top and commented per-job scopes,
`persist-credentials: false`, no `${{ }}` inside `run:`, no `pull_request_target` / `workflow_run`, no cache in
release workflows, Dependabot with a cooldown. Findings are fixed; the only exception is a line-scoped
`# zizmor: ignore[rule]` with a written reason.

## Package README

The README starts with a one-paragraph description and a quick-start, then: installation (Composer and
single file), commands, states, exit codes, configuration, CI usage, how detection works,
compatibility with Adobe's tools, development, and license.
