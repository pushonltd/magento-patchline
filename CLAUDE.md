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
