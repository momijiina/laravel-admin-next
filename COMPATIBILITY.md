# Compatibility Policy

- Preserve existing laravel-admin APIs where practical
- Avoid breaking changes unless necessary, and document them when required
- Aim for compatibility and integration with other Laravel applications; verify
  specific application/version combinations before claiming support
- Separate dependency declarations, focused checks, and full runtime support

## Image-processing dependency change (after PR #25)

Transformations and thumbnails now require optional Intervention Image
`^3.11.9`; ordinary uploads without processing do not require it. The PHP floor
remains `^8.2`. This is a **breaking change** with ten supported legacy field
operations and a native v3 callback escape hatch, not a complete v2 shim.
See [IMAGE_MIGRATION.md](IMAGE_MIGRATION.md) for callback changes, separate
transform/thumbnail driver defaults, encoding, thumbnail behavior and
non-atomic storage failure boundaries.

Initial local v3 verification, before the GD-build test correction, on
PHP 8.5.11 / Intervention 3.11.9 / bundled GD: the focused
image suite passed 17 tests / 234 assertions / zero skips with EXIF enabled
and zero diagnostics. Complete BrowserKit runs completed 94 tests on Laravel
12.69.3 (1,231 assertions) and 13.34.0 (1,232 assertions), each without skips.
Separate integration runs completed 106 tests / 36,653 assertions / two expected
MySQL/PostgreSQL service skips per framework. All 330 production PHP files
passed lint; the optional-dependency check, Composer platform checks and audits
passed. These local results are separate from PR #25's hosted evidence below;
see the [detailed boundaries](IMAGE_MIGRATION.md#focused-verification).

The initial eight hosted GD lanes exposed a build-dependent 45-degree rotation
oracle failure: bundled GD produces 13×14, external libgd 2.3.3 produces 15×15
for the 12×8 fixture. Independent v2 comparison also found missing hidden-RGB
normalization in the v3 GD decode path. The adapter now uses v3's public clone
(native canvas copy) to preserve legacy transparent-RGB normalization; Imagick
is unchanged. The final test runs 31 independent cases against two actual v2
manifests, each containing 49 fixed PNG outputs. Exact GD-version/native-canary
fingerprints select the test oracle; unknown builds fail the test provider,
not production uploads. These checks do
not promise identical dimensions or pixels across different GD builds.
See the [correction and oracle scope](IMAGE_MIGRATION.md#gd-build-specific-rotation-and-the-corrected-oracle).

Corrected PHP 8.5.11 / Intervention 3.11.9 / EXIF-enabled focused runs passed
48 tests / 236 assertions on both characterized builds. Complete BrowserKit runs
passed 125 tests each: bundled GD with Laravel 12.69.3 / 1,236 assertions and
13.34.0 / 1,232 assertions; external libgd 2.3.3 with Laravel 12.69.3 / 1,228
assertions and 13.34.0 / 1,234 assertions. All had zero skips and diagnostics;
historical random fixture counts vary. The count increase reflects independent
oracle cases and a direct normalization regression. All four integration runs
completed 106 tests / 36,653 assertions / two expected database-service skips;
the optional-dependency boundary and 330-file lint passed on both builds.
Independent comparison found 53/53 decoded outputs exact against v2 on each
backend. These local results are separate from hosted CI and other GD builds.

Imagick and WebP/AVIF codecs remain unverified; see the migration guide for
focused EXIF/animation test scope and remaining parity limits. The
PR #25 snapshot below predates this migration, including its Image v2
BrowserKit dependency; its test counts, source-file count and green hosted jobs
must not be presented as validation of the changed v3 source. Historical
sections intentionally retain the dependencies and results of their revisions.

## Current verified coverage (2026-10-03, after PR #25)

This snapshot records checks for the code merged in
[PR #25](https://github.com/momijiina/laravel-admin-next/pull/25), head
[`5c161f51`](https://github.com/momijiina/laravel-admin-next/commit/5c161f51c5d144c4045099877737660fd1e8a0c1),
merged as `d2ee8f4`. Historical evidence below and in individual regression notes
records the suite sizes at those earlier revisions; it is not the current total.

- **Declared requirements:** PHP `^8.2`, Laravel `>=5.5`, Doctrine DBAL
  `^2.13.9 || ^3.10.6`. These declarations do not certify every admitted
  combination. Full modern Laravel consumers resolve DBAL 3; Symfony
  HttpFoundation conflicts prevent normal full-Laravel 12/13 resolution with
  DBAL 2. Independently resolved Illuminate components exercise DBAL 2 instead.
- **Source checks:** the strict source-lint runner now covers **328 production
  PHP files** without diagnostics on PHP 8.4.25 and 8.5.11, with all 37
  formerly implicit nullable parameters explicit.
- **Local integration:** PHP 8.4.25, Laravel 12.69.3 / Testbench 10.12.0 /
  PHPUnit 11.5.56 and Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37,
  DBAL 3.10.6: **56 tests, 35,302 assertions, 2 service skips** per framework.
  PHP 8.5.11 was checked locally for the focused generator suite only at this
  revision: **10 tests, 254 assertions, 2 service skips** per framework. Earlier
  PHP 8.5 full-suite results describe smaller, earlier suites. These passes
  retain existing invalid-input deprecations described in the
  [controller regression](tests/integration/HANDLE_CONTROLLER_REQUEST.md).
- **Historical BrowserKit:** **77 tests** comprise 73 historical methods and
  four harness-isolation regressions. Local PHP 8.4.25 runs pass on Laravel 12/13
  in default and randomized order. These are in-process SQLite HTTP tests with
  real GD image processing, not JavaScript browser tests or a deprecation-free
  certification. See the [runner and diagnostic limits](tests/browserkit/README.md).
- **Hosted evidence:** all **60 jobs across 13 workflows** passed for the exact
  PR #25 head above, including integration and BrowserKit on Laravel 12 with
  PHP 8.2–8.5 and Laravel 13 with PHP 8.3–8.5. See
  [the PR checks](https://github.com/momijiina/laravel-admin-next/pull/25/checks).
  These results are separate from the local results and from future CI runs.

### Database-service evidence and remaining limits

The [model-generation run](https://github.com/momijiina/laravel-admin-next/actions/runs/37093063540)
passed all ten jobs at that exact head:

- Six full-framework DBAL **3.10.6** cells: Laravel 12 / PHP 8.2 and
  Laravel 13 / PHP 8.3, each against **MySQL 8.4.11, MariaDB 10.11.19 and
  PostgreSQL 16.15**. These exercise production schema metadata/output parity
  against an independent same-version DBAL oracle and actual model-backed
  Artisan generation, including qualified tables and PostgreSQL search paths.
- Four DBAL **2.13.9** component cells: Illuminate Database/Events 12/13 on
  PHP 8.4, each against MySQL/MariaDB. They check real metadata/output parity,
  but do **not** boot a full modern Laravel application or run Artisan.
  PostgreSQL/DBAL 2 is not covered by this matrix.

Both MariaDB matrices exercise Laravel's `mysql` and `mariadb` connection
classes. PDO transaction, reconnect, connection-state and persistent-connection
lifecycle regressions use **SQLite only**. DBAL 3 supports persistent PDO in
those tests; DBAL 2 rejects it before mutating connection state. These lifecycle
results must not be generalized to every service driver. SQL Server has
structural checks only and remains unverified against a live server.

See the [integration harness](tests/integration/README.md) and
[generator details](tests/integration/RESOURCE_GENERATOR.md) for reproducible
commands, dependency-resolution boundaries and test scope. Full JavaScript
browser flows, every PDO option/third-party driver, universal downstream support
and specific downstream application releases remain unverified. Normal Composer
security and platform checks remain enabled; DBAL 2's abandoned `doctrine/cache`
dependency is reported. Prefer DBAL 3 for maintained dependencies and persistent PDO.

## Historical migration and regression evidence

The following dated sections preserve what was verified when each patch was
prepared. For current totals and hosted outcomes, use the snapshot above.

## PHP baseline migration (2026-10-02)

The package now requires **PHP `^8.2` (8.2 through 8.x)**. PHP 7.x, 8.0 and
8.1 are no longer supported; an unverified PHP 9 major is not accepted. PHP 8.3+
is recommended for deployments. PHP 8.2 remains security-supported only through
2026-12-31; PHP 8.3 through 2027-12-31. Recheck the
[upstream support table](https://www.php.net/supported-versions.php) before release.

All 37 previously implicit nullable parameters in 24 source files now declare
`?Type` explicitly. Parameter names, default values, accepted types, visibility,
and behavior are preserved. This is an intentional PHP minimum-version change,
not a Laravel dependency upgrade: the broad `>=5.5` Laravel constraint remains
for downstream resolution, and does not promise support for EOL frameworks.

`php tests/compatibility/php_source_lint.php` lints all 326 production PHP files
with `E_ALL` and rejects compile-time diagnostics as well as syntax errors.
On PHP 8.4.25 it passes without diagnostics; the pre-migration source fails with
exactly 37 implicit-nullability notices in 24 files. All standalone regressions
also pass locally. Both real Laravel 12.69.3 and 13.34.0 suites pass 14 tests /
1,411 assertions each on PHP 8.4.25 without diagnostics, using the existing
isolated dependency sets with the migration source. Reflection metadata for all
37 parameters matches the old source (types, nullability, defaults, optionality,
visibility and static flags). CI requests standalone/source checks on PHP 8.2, 8.3 and 8.4,
and real integration on Laravel 12/PHP 8.2–8.4 and Laravel 13/PHP 8.3–8.4.
PHP 8.2/8.3 CI results and PHP 8.5 runtime verification are not claimed locally.

Obsolete Travis PHP 7.2–8.0 configuration has been retired. The historical root
development dependencies remain unchanged. The separate [BrowserKit runner](tests/browserkit/README.md)
restores all 73 historical methods on supported frameworks with SQLite and real
GD image processing; the [integration harness](tests/integration/README.md)
continues its focused modern-framework checks. These targeted passes are not complete framework certification.

## Historical baseline evidence (2026-10-01)

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
| Other Laravel applications | Compatibility and integration goal | Exact application/version combinations not yet specified or tested |

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

## Verification matrix policy

The modern combinations below now have the targeted coverage recorded above,
not complete application or downstream certification:

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
- PHP minimum-version changes must be intentional and documented; the current
  minimum is PHP 8.2, with PHP 8.3+ recommended
- Record the exact framework, PHP, dependency set, commands, and result for each
  newly verified combination
- Distinguish passed, failed, blocked, and not-run checks in PRs and release notes

## Historical isolated integration coverage (2026-10-02)

The [real Laravel integration harness](tests/integration/README.md) boots
Laravel with Testbench and exercises the package provider, auth HTTP lifecycle,
SQLite migrations/seeding and persisted operation-log redaction. Its ten tests
and 54 assertions pass locally on PHP 8.4.25 with Laravel 12.69.3 and 13.34.0.
A negative control using the old middleware fails the three redaction tests.

This consumer has its own development dependencies; the root legacy development
requirements remain unchanged. The package PHP requirement is now `^8.2`. The historical 73-test suite now has a separate [BrowserKit runner](tests/browserkit/README.md);
these focused passes do not establish full Laravel support.
See the harness README for exact dependency versions and untested areas.
