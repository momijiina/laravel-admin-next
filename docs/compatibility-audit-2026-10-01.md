# Compatibility and maintenance audit — 2026-10-01

## Scope and baseline

Repository: [momijiina/laravel-admin-next](https://github.com/momijiina/laravel-admin-next).
Audited commit: `819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`.
The import commit is `f83accfeb615b2be280b19e62f4b4bd29f786ac7`.

The audit covered the Composer manifest, PHP source, configuration, migrations,
tests, documentation, CI configuration, and vendored frontend assets. There were
886 tracked files, 301 source PHP files, 15 integration-test classes with 73 test
methods, and 286 bundled asset files (including 167 JS/CSS files). No repository
AGENTS.md or local skill instructions were present.

File/line references below describe the audited baseline, before the small Tree
property declaration shifts its later line numbers. This is a code/dependency
audit and a focused PHP execution check, not a penetration test or a full
production certification. No production application, database, or credentials
were used.

## Executive conclusion

The package advertises PHP `>=7.0.0` and Laravel `>=5.5`, but those open-ended
constraints exceed the compatibility supported by the code and test harness.
No complete Laravel/PHP combination has been demonstrated to pass the legacy
suite in this audit. Current Laravel support must be established incrementally.

The first maintenance patch is deliberately limited to declaring the existing
public `Tree::$path` property and testing it on PHP 8.4. Broader DBAL, factory,
dependency, and security changes remain separate work.

## Executed checks

PHP 8.4.25 was built from the official php.net source distribution, with its
published SHA-256 verified. Composer 2.10.3 was obtained from getcomposer.org
and its published SHA-256 verified. The initial minimal CLI was extended with
verified official libxml2 and SQLite builds for actual disposable-application
checks. The historical test suite remains blocked by its dependency graph.

| Check | Result | Meaning |
| --- | --- | --- |
| PHP 8.4.25 lint, all 359 PHP files under src/config/database/tests/resources/lang | PASS for syntax | 37 implicit-nullability deprecations in 24 files; syntax success is not clean deprecation status |
| Composer strict manifest validation | WARNING / exit 1 | Valid schema; unbounded `laravel/framework >=5.5` constraint is warned about |
| Composer update --dry-run, unchanged root after adding XML/SQLite extensions | FAIL / exit 2 | Old Laravel candidates blocked by security advisories; BrowserKit 6 conflicts with the remaining modern candidates |
| Composer resolution diagnostic ignoring only extension requirements | FAIL / exit 2 | Older Laravel candidates blocked by security advisories; newer candidates conflict with BrowserKit 6 |
| Same diagnostic with Laravel 12 / 13 explicitly requested | FAIL / exit 2 for both | BrowserKit 6 Illuminate constraints conflict with the requested framework |
| Tree regression against unmodified source | FAIL as expected | Undeclared property plus two PHP 8.2+ dynamic-property diagnostics |
| Tree regression after public property declaration | PASS | Constructor path capture, callback access, public reassignment, instance isolation, and no runtime diagnostics |
| Tree/test syntax checks and whitespace checks | PASS | The two existing constructor implicit-nullability notices remain explicitly identified |
| Final non-Blade PHP syntax check | PASS, 360 files | Includes the new regression script; the same 37 baseline notices in 24 files remain |
| composer test / full BrowserKit suite | BLOCKED / exit 127; tests not started | vendor/bin/phpunit is absent because dependencies cannot be installed unchanged |
| Full Laravel/PHP end-to-end matrix | NOT RUN | Limited consumer-application checks below are not full support |
| Browser-executed frontend exploit tests | NOT RUN | Static flows inspected; no deployed exploit verified |

Run the focused regression in a fresh process:

```sh
php tests/compatibility/tree_path.php
```

It supplies minimal interface/request test doubles and executes the real Tree
constructor. It deliberately reports the two known PHP 8.4 implicit-nullability
warnings as baseline and fails on unexpected load/runtime diagnostics. It does
not boot Laravel, connect to a database, or validate rendering.

The extension-ignoring commands were dependency-resolution dry runs only, not
installations or runtime passes. Composer's security-advisory blocking remained
enabled. The temporary consumer applications below isolate runtime dependencies
from this package's obsolete development/test dependencies without changing the
package manifest; their smoke results are still narrower than full support.

### Disposable Laravel 12 and 13 applications

Fresh official Laravel skeletons were used with small consumer manifests requiring
Laravel `^12.0` or `^13.0` and this checkout as a local path dependency:

- Laravel 12 skeleton: `e90c74ca717e9082d7463a2db50814fe565a3e44`
- Laravel 13 skeleton: `06d016a364a37430eec9cbc52209adce3d7667ce`
- Package checkout during execution: `ecb1552c565531ac90e73674c3aa321f0e7dca54`
  (Tree-only runtime fix plus audit documentation)

This excludes the package's obsolete require-dev graph; the package's own
composer.json was unchanged.

Resolved versions: PHP 8.4.25, Laravel 12.69.3 / 13.34.0, DBAL 3.10.6,
DomCrawler 5.4.52, and SQLite 3.53.4. Normal dependency installation and
check-platform-reqs passed for both consumers without ignoring platform requirements. Composer audit reported no known
advisories in these temporary PHP lockfiles; that does not cover bundled JS or
the application-level defects below.

The following results were obtained on both Laravel versions:

- PASS: automatic package discovery, vendor publishing, migrations, seeding,
  admin:install, config:cache, and route:cache
- PASS: repeat admin:install exits 0 and reports the existing admin directory;
  it does not reinstall the application
- PASS: in-process HTTP-kernel checks of login page (200), unauthenticated
  redirect (302), login submission (302 with authenticated guard), and
  authenticated dashboard/menu/user-list responses (200)
- FAIL as predicted: admin:make with a model calls the removed
  SQLiteConnection::isDoctrineAvailable method; without a model it throws
  Invalid model [] (both commands exit 1)
- REPRODUCED: an authenticated settings request with a synthetic, disposable
  password stores both password fields in plaintext in the operation log

An initial Laravel 12 run failed because the custom CLI lacked mb_split; adding
verified official Oniguruma/mbregex support resolved that environment issue. It
is not counted as a package compatibility defect.

These checks used APP_ENV=testing and disposable SQLite databases. They do not
verify browser JavaScript, session-cookie/CSRF round trips, other database
drivers, uploads, all CRUD operations, or the 73 historical integration tests.

## Prioritized findings

### 1. High: operation logs persist password inputs under shipped defaults

- `config/admin.php:59–65,212–226` enables the admin middleware and logging for
  POST/PUT/PATCH, excluding only the log-view routes
- `src/Admin.php:320,339` registers user-resource writes and PUT auth/setting
- `src/AdminServiceProvider.php:58–63` runs logging before the controller
- `src/Middleware/LogOperation.php:22–38` serializes all request input and writes
  the log before calling the next middleware/controller
- `src/Auth/Database/OperationLog.php:13` permits input assignment, with no scrubber
- `src/Controllers/AuthController.php:143–148` and `UserController.php:110,118–121`
  hash passwords only afterward; this cannot protect the already persisted input
- `src/Controllers/LogController.php:37–44` removes transport fields on display,
  but leaves password and password_confirmation visible

This verifies the default code path, including failed authenticated password
submissions. The successful-request path was also reproduced with a synthetic
password in both disposable Laravel 12/13 apps. Ordinary unauthenticated login
attempts fail the logger's authenticated-user condition. No production leak
was observed. Redact before
persistence, add success/failure and nested-input regressions, and assess any
existing logs separately. Do not solve this only by masking the display.

### 2. High compatibility blocker: removed Doctrine APIs on Laravel 11–13

`src/Console/ResourceGenerator.php:216,224` calls `isDoctrineAvailable()` and
`getDoctrineSchemaManager()`. Laravel 11 removed them; current Laravel 13
Connection source also lacks them. Model-based controller scaffolding fails
independently of whether DBAL is installed. Native schema APIs return a different
representation and need their own database/generated-controller tests.

Sources: [Laravel 11 upgrade guide](https://laravel.com/docs/11.x/upgrade#doctrine-dbal-removal),
[Laravel 13 Connection](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Connection.php).

Separately, `src/Console/MakeCommand.php:69` constructs ResourceGenerator even
without --model, although `:135–136,202` intend to permit a blank controller.
`ResourceGenerator.php:69–70` rejects that empty model. Both command modes need
regressions in the later generator phase.

### 3. High verification blocker: legacy dependencies and factories

- `composer.json:24` requires BrowserKit `^6.0`, whose Illuminate range ends at
  Laravel 10; modern Laravel test resolution is impossible unchanged
- `tests/TestCase.php:67` loads `tests/seeds/factory.php:4–8`, which resolves the
  removed `Illuminate\Database\Eloquent\Factory` implementation
- There are three legacy factory definitions and 12 factory() calls
- `phpunit.xml.dist:3,6–8,11` uses legacy runner attributes
- `tests/TestCase.php:20` boots the vendor/laravel/laravel skeleton directly;
  provider aliases and routes are manually registered, hiding discovery issues

BrowserKit 7.2.7 introduced Laravel 13 support; use a verified release floor
when redesigning the matrix. Update BrowserKit, DomCrawler constraints,
PHPUnit, Faker, factories, and the test app as a coordinated, separate phase.

Sources: [BrowserKit 6.x](https://raw.githubusercontent.com/laravel/browser-kit-testing/6.x/composer.json),
[BrowserKit releases](https://github.com/laravel/browser-kit-testing/releases),
[Laravel factory migration](https://laravel.com/docs/8.x/upgrade#model-factories).

### 4. PHP deprecations and reverse-compatibility mismatches

| Location | Finding | Impact |
| --- | --- | --- |
| src/Tree.php:88 | Undeclared public path | PHP 8.2+ dynamic-property notice; addressed by the first patch |
| src/Grid/Column.php:376 | Undeclared cast in legacy cast() | PHP 8.2+ notice when that API is used |
| src/Middleware/Pjax.php:142–144 | mb_convert_encoding with HTML-ENTITIES | PHP 8.2+ deprecation on matching decimal entities |
| 37 parameters / 24 files | Implicit nullable typed parameters | PHP 8.4+ compile-time deprecations; bulk nullable syntax requires deciding PHP minimum |
| src/Grid/Exporters/CsvExporter.php:176,181 | fputcsv default escape omitted | PHP 8.4+ deprecation per header/data row |
| src/Console/ExportSeedCommand.php:36 and its seed stub | database/seeders + Database\Seeders | Does not follow the Laravel 5.5–7 default seed layout despite the broad constraint |

CSV notices can pollute output if displayed; strict handlers may fail the request.
Preserving the current escape explicitly is a different change from adopting
RFC-oriented empty escaping. PJAX fixes must preserve decimal/entity behavior.
The first Tree change preserves its existing public read/write API and PHP 7
syntax; it does not silence other deprecations globally.

Sources: [PHP 8.2 deprecations](https://www.php.net/manual/en/migration82.deprecated.php),
[PHP 8.4 deprecations](https://www.php.net/manual/en/migration84.deprecated.php),
[fputcsv](https://www.php.net/manual/en/function.fputcsv.php),
[Laravel 8 seed layout](https://laravel.com/docs/8.x/upgrade#seeder-factory-namespaces).

### 5. Bundled frontend advisories and unsafe HTML paths

Default assets include jQuery 2.1.4, Bootstrap JS 3.3.4 / CSS 3.3.5, AdminLTE
2.3.2, Moment 2.10.6, Select2 4.0.3, and fileinput 4.5.2. See
`src/Traits/HasAssets.php:79–95` and `resources/views/login.blade.php:88–90`.
`src/Admin.php:375–394` / `src/Form/Concerns/HasFields.php:199–222` collect every
registered field's assets, even when a page does not use that field.

- jQuery 2.1.4 is within the affected ranges for CVE-2020-11022/11023; Bootstrap
  3.3.4 predates the tooltip/popover XSS fix in 3.4.1. Loading affected code does
  not by itself prove exploitation in a host application
- AJAX Select2 helpers return escapeMarkup unchanged after mapping d.text:
  `src/Form/Field/Select.php:344–358` and
  `src/Grid/Filter/Presenter/Select.php:224–238`. Untrusted option labels can reach
  an HTML sink; review consumer data trust and restore safe defaults separately
- File fields load fileinput without DOMPurify (`File.php:28–31`,
  `MultipleFile.php:28–32`). In the bundled fileinput, HTML preview is allowed
  and sanitization is skipped when DOMPurify is absent. Selecting malicious HTML
  can therefore render unsafe markup under defaults. No browser exploit was run
- Bundled DOMPurify 1.0.7 is not loaded by default and is itself obsolete; merely
  enabling that file is not a safe fix
- Moment 2.10.6 contains the old unbounded word regex related to CVE-2017-18214.
  No hostile parsing path was demonstrated. Do not misattribute CVE-2022-31129
  (its affected range begins at 2.18.0) or server-side locale traversal to this
  observed browser-only use

Sources: [jQuery advisory](https://github.com/jquery/jquery/security/advisories/GHSA-jpcq-cgw6-v4j6),
[Bootstrap 3.4.1](https://github.com/twbs/bootstrap/releases/tag/v3.4.1),
[Select2 escaping](https://select2.org/dropdown/#built-in-escaping),
[DOMPurify advisory](https://github.com/cure53/DOMPurify/security/advisories/GHSA-gx9m-whjm-85jf),
[fileinput changelog](https://github.com/kartik-v/bootstrap-fileinput/blob/master/CHANGE.md),
[Moment fix](https://github.com/moment/moment/commit/69ed9d4),
[Moment 2022 affected range](https://github.com/moment/moment/security/advisories/GHSA-wc69-rhjr-hc9g).

There is no tracked frontend package manifest, lockfile, or reproducible asset
build. Dependabot monitors Composer only. Upgraded assets must be republished
and optional minified bundles regenerated, with browser regressions.

### 6. Test/CI coverage does not establish current runtime support

At the baseline there are no GitHub Actions workflows. Travis lists only PHP
7.2–8.0, all now end-of-life. No Laravel matrix or coverage percentage exists.

- Tests replace shipped configuration wholesale (`tests/TestCase.php:38–47`),
  omitting DropdownActions and menu_bind_permission defaults
- Grid assertions count legacy action icons rather than current defaults
- `ImageUploadTest.php:72–79,206–227` tests named for removal only upload/count
- Permission checks primarily inspect models instead of forbidden HTTP requests
- Export coverage verifies a link, not CSV content
- BrowserKit cannot execute PJAX/widget/upload-preview JavaScript
- Several validation assertions use obsolete framework wording

The suite publishes files and installs on every test. Teardown drops tables
directly (`tests/TestCase.php:76–80`), with a default local MySQL/root configuration.
Use disposable app/database fixtures only; do not point it at a shared database.

The prepared first patch includes a focused PHP 8.4 regression workflow. Full application
CI remains a roadmap item; a green focused job must not be presented as full
Laravel compatibility.

### 7. Dependency age and distribution need explicit decisions

Faker's original repository is archived, and Intervention Image v2 is EOL.
However, the permitted Symfony 5.4 line still receives security fixes (through
February 2029); do not label every old allowed dependency as unsupported.
No resolved application lockfile exists in the repository, so no consuming
production application's PHP dependency CVE inventory can be inferred from
constraints alone. The temporary consumer lockfile audit has narrower scope.

Sources: [Faker](https://github.com/fzaninotto/Faker),
[Intervention v2](https://image.intervention.io/v2),
[Symfony 5.4](https://symfony.com/releases/5.4).

The package name and README install command still target encore/laravel-admin;
documentation includes historical upstream paths/versions/badges. Decide the
fork distribution identity before publishing installation instructions. Preserve
attribution and review fixture configuration without copying credential-like
values into reports or new changes.

## Next steps

1. Review the small Tree deprecation fix and its focused PHP 8.4 results
2. Address sensitive operation-log inputs in a separate, regression-tested change
3. Restore supported test dependency resolution and factories, then establish
   fresh Laravel 12/13 application smoke tests and a version matrix
4. Migrate schema introspection and fix generator modes independently
5. Tackle remaining PHP deprecations and frontend advisory/HTML risks in small PRs
6. Publish support/install/upgrade claims only after the corresponding tests pass

The detailed sequencing and policy live in [ROADMAP.md](../ROADMAP.md),
[COMPATIBILITY.md](../COMPATIBILITY.md), and [UPSTREAM.md](../UPSTREAM.md).
