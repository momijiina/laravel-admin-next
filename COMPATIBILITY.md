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
| Laravel 11–13 scaffolding | Allowed by the runtime constraint | Uses Doctrine connection methods removed in Laravel 11 |
| Test dependencies | BrowserKit `^6.0` | Its Illuminate constraints stop at Laravel 10; cannot resolve an 11–13 test matrix unchanged |
| Test bootstrap | Legacy Eloquent factories | Requires migration for modern Laravel |
| Historical CI | Travis PHP 7.2–8.0 | Configuration exists; no successful current run is established |
| Exment | Important compatibility target | Exact application/version combinations not yet specified or tested |

A clean syntax check or a focused deprecation regression is not a Laravel support
claim. Full support requires installation, bootstrap, database-backed tests,
browser smoke checks, and supported dependency resolution for that combination.

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
