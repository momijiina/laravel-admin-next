# Real Laravel integration tests

This is an isolated Composer **consumer** of the repository via a local path
repository. It uses [Orchestra Testbench](https://github.com/orchestral/testbench)
to boot real Laravel and SQLite without changing the root legacy development
dependencies. Both the shipped package and this harness require PHP `^8.2`;
Laravel 13 / Testbench 11 requires PHP 8.3+.

Run from this directory, using a PHP CLI with the required extensions and Node.js
24.15+ (24.x, for shipped Moment and collection DOM regressions):

```sh
npm ci --ignore-scripts --prefix javascript

# Laravel 12 / Testbench 10, PHP 8.2+
composer update --with 'orchestra/testbench:^10.0' --with 'phpunit/phpunit:^11.5'
composer check-platform-reqs
composer test

# Laravel 13 / Testbench 11, PHP 8.3+
composer update --with 'orchestra/testbench:^11.0' --with 'phpunit/phpunit:^12.0'
composer check-platform-reqs
composer test
```

Composer's normal security-advisory and platform checks remain enabled. Locks
and vendor files are local only so CI resolves each selected framework family.
Testbench's [compatibility table](https://github.com/orchestral/testbench-core)
explains the framework/version mapping.

## Current evidence

See the [current maintenance summary](../../COMPATIBILITY.md#current-maintenance-summary)
for recent changes, upgrade cautions and scoped regression guides. The separate
[PR #25 snapshot](../../COMPATIBILITY.md#current-verified-coverage-2026-10-03-after-pr-25)
retains its exact local and hosted results.
The dated results below are historical snapshots, not the current suite totals.

## What this covers

- Real package service provider, published-default configuration, middleware
  aliases/groups, session guard, migrations and seed data
- Login view, guest redirect, valid/invalid login, authenticated request, logout
- Remember-input parity with the legacy getter across 1,334 real request shapes,
  including source precedence, null/array values, JSON and absent input; real
  remember cookies/tokens for accepted and rejected credentials
- Real Artisan blank-controller generation and rejection of model-less `--output`
- Generated model-backed controllers executing index/create/store/show/edit/update/delete
  through the real HTTP kernel; [CRUD regression scope](GENERATED_CONTROLLER_CRUD.md)
- Native mutable/immutable Date/Datetime edit/save round trips with shipped Moment;
  [date-cast presentation scope](DATE_CAST_PRESENTATION.md) and boundaries
- Native mutable/immutable DateRange/DatetimeRange endpoint edit/save round trips;
  [range-cast presentation scope](DATE_RANGE_CAST_PRESENTATION.md) and boundaries
- Initial DateRange/DatetimeRange/TimeRange bounds with the shipped picker in offline DOM;
  [initialization contract and exclusions](DATE_RANGE_INITIALIZATION.md)
- Nullable temporal generated create/edit/save NULL preservation;
- Canonical temporal database literal defaults in generated source and HTTP persistence;
  [generator default scope](NULLABLE_TEMPORAL_DEFAULTS.md) and existing-controller caveats
- Model-backed Artisan generation and independent DBAL metadata/output parity;
  [generator regressions](RESOURCE_GENERATOR.md) cover SQLite and opt-in services
- Real request-input parity for grid, row/batch actions and controller dispatch;
  DomCrawler compatibility and multiple-select null handling
- Named grid pagination with configured sizes, independent links and explicit
  Eloquent arguments; [pagination regression scope](GRID_PAGINATION.md)
- Explicit per-grid sort keys configured before sortable columns, with applied
  named filters and follow-up rendered links; [sort regression scope](GRID_EXPLICIT_SORT.md)
- Ordinary nullable Select zero/NULL rendering and serialized HTTP persistence;
  [selection contract, defaults, and upgrade notes](NULLABLE_SELECT.md)
- Ordinary nullable Radio NULL/zero rendering, native omission, iCheck and HTTP saves;
  [defaults, validation and clearing boundaries](NULLABLE_RADIO.md)
- Checkbox zero-valued choices in flat/grouped views, native DOM successful controls,
  JSON/CSV saves and validation redisplay; [selection contract](CHECKBOX_ZERO.md)
- Ordinary Textarea leading-LF preservation through native HTML parsing and HTTP saves;
  [textarea rendering contract and normalization boundaries](TEXTAREA_NEWLINES.md)
- Ordinary Tags hidden-input create/update/clear with exact storage parity;
  [Tags regressions](TAGS.md)
- Default NULL export-driver resolution under strict PHP 8.5 diagnostics, driver
  registry/cache compatibility, and actual unconfigured HTTP export scopes
- Headers for empty actual CSV exports and cross-chunk header-once controls;
  [CSV schema and compatibility notes](CSV_HEADERS.md)
- Explicit native-object grid display callbacks and actual streamed CSV round trips;
  [scalar/null result contract and boundaries](OBJECT_DISPLAY.md)
- Native object/array casts in configured embedded forms;
  [original-metadata regression and replacement boundary](EMBEDDED_OBJECT_ORIGINALS.md)
- Explicit ListField bounds, scoped input/error names, and ListField/KeyValue
  empty-marker submission; [behavior and upgrade cautions](LIST_FIELD.md)
- Readonly collection controls and unchanged submission;
  [UI behavior and cautions](LIST_FIELD.md#readonly-collections)
- Production-rendered List/KeyValue collection scripts with shipped and modern jQuery;
  [offline DOM scope, setup, and limits](COLLECTION_SCRIPT_SCOPING.md)
- Ready-wrapped HasMany table-parent reinitialization, unique child identities, and
  actual serialized HTTP/SQLite saves; [regression scope](HASMANY_TABLE_REINITIALIZATION.md)
- Ready-wrapped HasMany default/tab reinitialization, consumer handlers, safe child
  identities, real Bootstrap tab behavior and HTTP/SQLite saves;
  [regression scope](HASMANY_MODES_REINITIALIZATION.md)
- Main-file upload failure preservation and successful replacement
- Actual HTTP-kernel middleware lifecycle, including consumer bootstrap execution
- SQLite-persisted operation logs, recursive redaction, preserved controller input,
  validation failures, custom redaction fields, logging disablement and exclusions

No package/framework classes are replaced with stubs. Small fixture routes return
JSON or validate input to observe the real middleware before/after a controller.
Tests use a fresh application with disposable SQLite databases; the opt-in
generator fixtures additionally use dedicated database services. Testbench registers
the provider explicitly and Laravel's testing environment bypasses CSRF validation;
this is not package-discovery or browser-cookie/CSRF end-to-end coverage.

## Limits

The root PHPUnit configuration excludes this consumer directory, keeping its
classes and vendor tree out of legacy test discovery. This suite does not replace
or claim to pass the 73 legacy BrowserKit tests; use the separate
[historical BrowserKit runner](../browserkit/README.md) for that coverage.
Main-file upload failures and successful replacement are covered by the
[upload failure regressions](FILE_UPLOAD_FAILURE.md), including their non-atomic
filesystem/database limitations. Other upload behavior,
CRUD beyond the [generated scalar-field lifecycle](GENERATED_CONTROLLER_CRUD.md)
and browser end-to-end JavaScript remain outside this suite. The collection
regression executes real emitted scripts in jsdom; it does not cover browser
layout, live PJAX transport, or arbitrary third-party widgets. Non-SQLite
coverage is limited to the dedicated generator service matrix; the ordinary
lifecycle suite uses SQLite. The PHP baseline migration explicitly
declares all 37 previously implicit nullable parameters; these integration runs
now emit no nullable notices. The PHPUnit configuration keeps E_ALL enabled and
routes Laravel/Testbench deprecation logs to stderr. The intentional legacy-getter
reference call suppresses only its own Symfony deprecation; the controller
regression turns that same notice into a failure. These focused passes do not
certify the absence of all deprecations in untested paths or full framework support.

## Historical local verification (2026-10-02)

PHP 8.4.25, SQLite 3.53.4, DBAL 3.10.6 and DomCrawler 5.4.52:

- Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56: **14 tests, 1,411 assertions pass**
- Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37: **14 tests, 1,411 assertions pass**
- Normal Composer resolution and `check-platform-reqs` pass for both; neither
  lockfile reports known Composer security advisories at verification time
- Negative control on Laravel 13: loading the actual pre-redaction middleware
  from `b79f56d` causes the three persisted-redaction assertions to fail with
  the synthetic inputs visible in SQLite. Restoring the current middleware
  passes the original ten lifecycle tests. No production data is used.

The remember-input negative control loads the pre-fix `AuthController` from
`0a45463`; on Laravel 12 the regression fails immediately on the Symfony
`Request::get()` deprecation. The fixed controller passes on both frameworks.
The value matrix stops at the real guard's `Attempting` event to compare the exact
argument without authenticating; separate HTTP tests check cookies, tokens and
login outcomes with the real guard.

The workflow additionally requests PHP 8.2/8.3 with Laravel 12 and PHP 8.3 with Laravel 13.
Those PHP runtimes were not executed locally; their results must come from CI.

After the PHP baseline migration, both 14-test / 1,411-assertion suites pass again
on PHP 8.4.25 without diagnostics. The existing resolved dependency sets were
reused with the package PSR-4 source mapped to the migration worktree; dependency
resolution on PHP 8.2/8.3 remains a CI check. The standalone source regression
separately lints 326 production PHP files with no compile-time diagnostics.


### Historical PHP 8.5 verification (2026-10-02)

[PHP 8.5.11](https://www.php.net/downloads.php?source=Y) was built from the
official source archive and checked against its published SHA-256. Fresh normal
Composer resolutions on that runtime retained DBAL 3.10.6 and DomCrawler 5.4.52:

- Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56: **14 tests, 1,411 assertions pass**
- Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37: **14 tests, 1,411 assertions pass**
- Both actual-runtime `check-platform-reqs` checks pass, and Composer reports no
  known security advisories at verification time
- All eight standalone scripts pass, including strict lint of 326 production PHP
  files; the lifecycle runs emit no diagnostics with the existing E_ALL settings

The standalone column test no longer calls
[`ReflectionProperty::setAccessible()`](https://www.php.net/manual/en/reflectionproperty.setaccessible.php),
which has no effect since PHP 8.1 and is deprecated in 8.5. This fixture-only change
preserves the reflected sorter-value assertions under the package's PHP 8.2 floor.
No production code, warning handling or dependency constraints changed.

The source/standalone workflow now includes PHP 8.5, and the real lifecycle
workflow includes PHP 8.5 with both framework families. These are focused results;
the limitations of this focused suite above still apply; historical coverage is
tracked by the separate BrowserKit runner.
