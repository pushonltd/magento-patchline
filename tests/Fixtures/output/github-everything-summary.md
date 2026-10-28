## Patchline

Summary: 1 applied, 1 queued, 1 duplicate, 1 misordered, 1 missing, 1 entitled, 1 unknown, 1 not_applicable

| Patch | Area | State | CVEs |
| --- | --- | --- | --- |
| 249-APPLIED-CE | CE | applied | CVE-2026-0001 |
| 249-QUEUED-CE | CE | queued | CVE-2026-0002 |
| 249-DUPLICATE-CE | CE | duplicate | CVE-2026-0003 |
| 249-MISORDERED-CE | CE | misordered | CVE-2026-0004 |
| 249-MISSING-CE | CE | missing | CVE-2026-0005 |
| 249-ENTITLED-CE | CE | entitled | CVE-2026-0006 |
| 249-UNKNOWN-CE | CE | unknown | CVE-2026-0007 |
| 249-NOTAPPLICABLE-CE | CE | not_applicable | CVE-2026-0008 |

### Sync

- **done** download 249-2026-10-001-CE to m2-hotfixes/a.patch
- **failed** download 249-2026-10-001-CE to m2-hotfixes/b.patch
- **skipped** rename m2-hotfixes/c.patch to m2-hotfixes/d.patch
- **done** remove m2-hotfixes/e.patch
- **warning** Check the order.
- **warning** Check, again \| now.

### Verify

ece-patches apply would stop at `m2-hotfixes/e.patch`.
### Cross-check

- 249-X-CE: Patchline detects applied, Adobe patch-status reports unknown.
- 249-Y-CE: Patchline detects not_applied, Adobe patch-status reports not_listed.
