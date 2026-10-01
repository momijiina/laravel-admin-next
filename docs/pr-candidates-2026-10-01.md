# Local PR candidates 2026-10-01

Both candidates are based independently on
`819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`. Neither has been published.
GitHub CLI authentication is unavailable and the connected integration returned
HTTP 403 for the attempted tree write. Publishing is deferred until write access
is prepared; local work continues without modifying main.

## Candidate 1: Declare the Tree request path

- Branch: `fix/tree-path-php84-deprecation`
- Runtime-code commit: `14d8a27151b41d4bde0fba3daec372bf907fc787`
  (use the branch head to include subsequent documentation updates)
- Production change: declare the existing public, untyped `Tree::$path` property
- Added checks: standalone constructor/path regression and focused PHP 8.4 CI
- Documentation: ROADMAP.md, COMPATIBILITY.md, UPSTREAM.md, compatibility audit,
  and this candidate inventory

Verification: the focused regression fails on the original source and passes
after the declaration. It checks request-path capture, callback visibility,
public reassignment, independent instances, and runtime diagnostics. PHP 8.4
syntax checks pass for 360 files; the same 37 existing implicit-nullability
notices in 24 files remain. Limited Laravel 12.69.3 / 13.34.0 consumer smoke
checks also passed for the paths listed in the audit.

Impact: public access and PHP 7 syntax remain unchanged. The standalone test
uses small framework test doubles; it does not establish complete Laravel
compatibility. Generator failures, legacy suite blockers, and security findings
are documented and not mixed into this change.

## Candidate 2: Make CSV escape arguments explicit

- Branch: `fix/csv-escape-php84-deprecation`
- Commit: `42b8b5497a604c3a5d4b3ffa0b6b84c3ff2cc6f3`
- Production change: two fputcsv calls explicitly pass comma, double quote, and
  backslash, preserving the previous defaults
- Added checks: standalone subprocess/streaming regression and focused PHP 8.4 CI

Verification: the actual CsvExporter::export method and its streaming closure
run in subprocesses with explicit framework/data test doubles. The original
source emits three default-escape deprecations across both call sites and fails;
the candidate passes without diagnostics. Both produce the same expected
212-byte populated CSV and 3-byte empty BOM. Cases include comma, quotes,
backslash-before-quote, ordinary backslashes, newlines, Unicode, null/empty/zero,
two chunks with one header, response headers, and termination. Reverting either
call or changing the escape to an empty string is detected by mutation checks.

Impact: output bytes and the Composer/PHP minimum are unchanged. No new escaping
policy or broad exporter refactor is included. The test requires proc_open and
does not replace full Laravel/database/browser integration coverage.

## Run the focused tests

In each candidate's checkout, respectively:

```sh
php tests/compatibility/tree_path.php
php tests/compatibility/csv_exporter.php
```

The two branches are independently reviewable and have disjoint production
changes. They should remain separate PRs. Their GitHub workflows have not run
because the branches have not been published.

## Deferred work

- Decide the real minimum PHP version before replacing implicit nullable types
  with ?Type syntax, which would exclude the currently advertised PHP 7.0
- Migrate Doctrine schema introspection and the legacy factory/test harness in
  separate phases
- Address operation-log secret redaction and frontend HTML/advisory risks in
  separate security-focused changes with regression coverage

See [the audit](compatibility-audit-2026-10-01.md) and [roadmap](../ROADMAP.md).
