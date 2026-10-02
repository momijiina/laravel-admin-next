# Compatibility Policy

- Preserve existing laravel-admin APIs where practical
- Avoid breaking changes unless necessary, and document them when required
- Treat Exment compatibility as an important target and verify specific releases
- Separate dependency declarations, focused checks, and full runtime support

## Current evidence (2026-10-01)

Baseline: `819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`.
See the [full audit](docs/compatibility-audit-2026-10-01.md) for evidence and limits.

| Area | Declared or historical state | Verified status |
| --- | --- | --- |
| PHP | `>=7.0.0` in Composer | PHP 8.4.25 parsed all 359 non-Blade PHP files; 37 implicit-nullability notices in 24 files remain |
| Laravel | `>=5.5` in Composer | No complete Laravel/PHP runtime combination has passed the existing suite in this audit |
| Laravel 12.69.3 / 13.34.0 + PHP 8.4.25 + SQLite | Temporary consumer with production dependencies | Install, login/dashboard/menu/user-list HTTP smoke and cache commands passed; both generator modes failed; full compatibility is not established |
| Laravel 11–13 scaffolding | Allowed by the runtime constraint | Uses Doctrine connection methods removed in Laravel 11 |
| Test dependencies | BrowserKit `^6.0` | Its Illuminate constraints stop at Laravel 10; cannot resolve an 11–13 test matrix unchanged |
| Test bootstrap | Legacy Eloquent factories | Requires migration for modern Laravel |
| Historical CI | Travis PHP 7.2–8.0 | Configuration exists; no successful current run is established |
| Exment | Important compatibility target | Exact application/version combinations not yet specified or tested |

A clean syntax check or a focused deprecation regression is not a Laravel support
claim. Full support requires installation, bootstrap, database-backed tests,
browser smoke checks, and supported dependency resolution for that combination.

## Focused follow-up: legacy column cast (2026-10-02)

The existing public, untyped `Grid\Column::$cast` property is now declared to
avoid PHP 8.2+ dynamic-property deprecations. On PHP 8.4.25, the standalone
`php tests/compatibility/grid_column_cast.php` regression failed on the preceding
source with two dynamic-property notices and passes after the declaration.
It checks fluent calls, public reads/writes, null resets, untyped values,
instance isolation, and unchanged `sortable($cast)` arguments using small
framework test doubles. CI runs this focused check on PHP 8.4.

The deprecated `cast()` API still only stores its value; callers should continue
using `sortable($cast)` to configure sorting. This patch does not change sorting
behavior, PHP requirements, implicit-nullability warnings, or the blocked legacy
Laravel suite. It does not establish full framework, database, or rendering
compatibility.

## Proposed verification matrix

These are candidates, not supported combinations:

- Laravel 12 with PHP 8.2, 8.3, 8.4, and 8.5
- Laravel 13 with PHP 8.3, 8.4, and 8.5
- Explicit legacy combinations required by downstream applications, tracked
  separately with their upstream end-of-life status

As of this audit date, Laravel 11's security support ended on 2026-03-12;
Laravel 12 receives security fixes until 2027-02-24, and Laravel 13 until
2028-03-17. PHP 8.2–8.5 remain under upstream security support; PHP 8.2 reaches
its scheduled end on 2026-12-31. Recheck these dates before each release.

Sources: [Laravel support policy](https://laravel.com/framework/docs/releases),
[PHP supported versions](https://www.php.net/supported-versions.php).

## Changes and releases

- Keep the existing `Encore\Admin` namespace and package identity unchanged in
  the initial maintenance patch; evaluate publication decisions separately
- Do not widen constraints merely to make Composer accept an untested version
- Do not raise the minimum PHP version incidentally: `?Type` syntax, for example,
  requires PHP 7.1 even though the current manifest still advertises PHP 7.0
- Record the exact framework, PHP, dependency set, commands, and result for each
  newly verified combination
- Distinguish passed, failed, blocked, and not-run checks in PRs and release notes

## Isolated integration coverage (2026-10-02)

The [real Laravel integration harness](tests/integration/README.md) boots
Laravel with Testbench and exercises the package provider, auth HTTP lifecycle,
SQLite migrations/seeding and persisted operation-log redaction. Its ten tests
and 54 assertions pass locally on PHP 8.4.25 with Laravel 12.69.3 and 13.34.0.
A negative control using the old middleware fails the three redaction tests.

This consumer has its own development dependencies; the root production and
legacy development requirements remain unchanged. The historical 73-test suite
is still blocked, and these focused passes do not establish full Laravel support.
See the harness README for exact dependency versions and untested areas.
