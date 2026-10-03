# Historical BrowserKit suite

This isolated runner executes all 73 historical methods in `tests/*Test.php`
and four harness-isolation regressions against supported Laravel 12/13 releases. It does not use the obsolete root
`require-dev` graph or an installed `laravel/laravel` application.

## Run

Use PHP 8.2+ for Laravel 12 or PHP 8.3+ for Laravel 13, with DOM, XML,
XMLWriter, mbstring, fileinfo, PDO SQLite and **GD with JPEG support**.
PHPUnit 11 is used on PHP 8.2; PHPUnit 12 requires PHP 8.3+.

```sh
cd tests/browserkit
composer update --with 'orchestra/testbench:^10.0' --with 'phpunit/phpunit:^11.5'
composer check-platform-reqs
composer audit --locked
composer test
```

For Laravel 13, select Testbench `^11.0` and PHPUnit `^12.0` instead.
Do not use `--ignore-platform-reqs`, disable advisory blocking, or skip the
image tests. Their upload, rotation and flip requests require real image processing.
The historical assertions check filenames, records, counts and deletion; they
do not verify transformed pixels/dimensions or cover crop/resize.
CI runs the full suite on the same PHP/framework combinations as the existing
real-Laravel lifecycle suite.

`BROWSERKIT_AUTOLOAD` optionally selects the autoloader of an independently
installed consumer containing this local package and the same dependencies.
It is useful for testing several framework versions without replacing vendors.

## Isolation and coverage boundaries

- BrowserKit sends requests through Laravel's in-process HTTP kernel. It does
  not launch a web server or execute JavaScript in a real browser.
- Each test gets a fresh SQLite `:memory:` database and disposable application
  files under a random process-specific temporary path. Cleanup deletes only
  that fixture tree. It never reads an application's `.env` or database config.
- The real `vendor:publish` and `admin:install` commands generate the app assets,
  migration and controllers. The runner supplies the consumer base controller,
  valid encryption key and a minimal root welcome response for `LaravelTest`.
- Current admin defaults are overlaid with the historical fixture settings.
  The grid fixture explicitly selects the supported legacy `Actions` renderer,
  which the original edit/delete/detail assertions were written to exercise.
  This suite does not claim coverage of the newer dropdown action renderer.
- SQLite coverage is **not MySQL equivalence**. Keep the dedicated native schema
  parity workflow for actual MySQL/MariaDB coverage.
- Legacy factories, FakerPHP, BrowserKit, Testbench, PHPUnit and Intervention
  Image 2 are isolated development dependencies. No production requirements
  change. Intervention 2 is used because these are historical image APIs.

## Necessary assertion corrections

No methods or assertions are removed. Five historical test files are updated:

1. `UserGridTest`: postcode, address, color and date values belong to the
   `profile` relation. Reading them from `User` returned null, making the old
   checks ineffective; the assertions now verify the actual profile values.
   The LIKE-filter fixture also guarantees two matches and 48 non-matches;
   random Faker names occasionally produced zero matches and an empty-state
   placeholder row, making the old row-count assertion flaky.
2. `UserFormTest`: `[multiple]` checks the boolean HTML attribute, including the
   current valid `multiple` serialization, rather than requiring the obsolete
   literal `multiple="multiple"` value. Option and selection counts remain.
3. Form/settings validation checks use Laravel 12/13's current English field
   labels and messages. Invalid submissions, redirects, and subsequent valid
   submissions remain exercised.

4. `ImageUploadTest`: the custom filename callback uses Symfony’s MIME-based
   extension guess (`jpg` on current versions), not a hardcoded `jpeg` suffix.
   The test now checks both the stored database path and the actual file.

5. `MenuTest`: the seeded home menu is `Dashboard`, not `Index`. Check each
   seeded label in its own tree node, plus the page heading and breadcrumb.
   Whole-response matching previously passed accidentally when an earlier file
   upload test left `IndexTest.php` in the process-wide `Admin::$script` buffer.
   Tree-scoped assertions cannot be satisfied by these scripts or sidebar labels.
   The harness now separately checks and restores the package state described
   below; tree-scoped assertions remain necessary within each test's requests.

The original `tests/TestCase.php` and root PHPUnit configuration are left as
historical references. Run this suite through its own configuration; recursively
collecting the entire `tests/` tree mixes incompatible harnesses.

## Package state between test methods

`PackageState.php` explicitly inventories package-owned mutable static values.
The harness captures their current values before application setup and restores
those values after teardown, including when application teardown or fixture-file
cleanup throws. Setup exceptions restore package state before being rethrown.
The original exception/assertion is not converted into a pass.

The inventory follows these sources:

- `Admin` / `HasAssets`: inline/deferred scripts, CSS/JS/style/HTML/header queues,
  title/favicon, base assets, minification settings/cache, extension registrations,
  and booting/booted callbacks. `baseCss()` also accumulates skin entries.
- `Form` / `HasFields` / `HasHooks`: collected assets, field registrations/aliases,
  and initialization callbacks. The installed bootstrap removes default fields.
- `Grid`, `Show`, and `Grid\Column`: initialization/extension registries, column
  displayers/definitions, row/HTML attributes, original models and model cache.
  The `ShouldSnakeAttributes` caches on Form, Grid and Show are restored too.
- `Action`, grid `Selector`, `BelongsToMany`, and `Exporter`: generated action
  selectors, parsed selections, relation keys, exporter registrations/instances.
- `ModelTree`: branch ordering for the actual menu model and `Tests\Models\Tree`.
  Admin's menu and navbar are instance properties; fresh Laravel applications
  clear them, and the regression verifies that lifecycle separately.

This uses the pre-test values, not empty arrays or class defaults: host assets,
plugin registrations and callbacks installed before the test remain intact.
Reflection is confined to this test helper; there is no production reset API or
PHPUnit-wide static backup. Values are shallow snapshots. Existing object and
closure identities are retained; mutation *inside* arbitrary plugin-owned objects
is not undone. Unlisted package/third-party globals are outside this boundary.
There is no per-request reset within a historical test, and this does not establish
long-running-worker isolation for production applications.

The dedicated `Historical harness isolation` suite runs two consecutive harness
lifecycles in one process, reproduces the real file-upload script contamination,
checks restored values and preserved host callbacks/defaults, and checks failure
paths. Run it alone with `composer test -- --testsuite 'Historical harness isolation'`.
The original 73-method suite remains independently selectable as
`Historical BrowserKit (SQLite)`; neither suite depends on test execution order.

## Diagnostics and local evidence

With the state boundary enabled, the combined 77-test suite passes on PHP 8.4.25
with Laravel 12 and 13, both in default order and randomized seeds 7301, 9843 and
18057. The separate real-Laravel integration suite also passes (39 tests).
Running each isolation regression against the pre-restoration harness fails on
both framework versions: leaked upload script, changed static value, or leaked
setup/teardown script, respectively. No historical assertion was relaxed.

All 73 methods pass locally on PHP 8.4.25 with both Laravel 12 and 13, including
real GD-backed rotation/flip requests. The suite also passes in randomized order
(seeds 7301, 9843 and 18057). Assertion totals vary because some historical tests intentionally
choose random fixture counts.

This is a **behavioral pass, not a deprecation-free pass**. E_ALL and Laravel's
`LOG_DEPRECATIONS_WHILE_TESTING` remain enabled, with deprecations sent to stderr.
The fixtures use Faker formatter methods and reserved `example.com` avatar
URLs, avoiding Faker's deprecated property API and remote image provider.
The revived coverage still exposes existing warnings from Symfony's deprecated
`Request::get()`. The `MultipleSelect::prepare` null-to-`strlen()` warning exposed
by this suite is now fixed, with a strict regression that preserves the original
filtering behavior. Laravel-handled deprecations are logged and do not necessarily
fail PHPUnit, even with `failOnDeprecation` enabled. These production/dependency
cleanups belong in separate changes; no warnings are suppressed by this runner.

PHP 8.2/8.3/8.5 jobs are configured in CI but are not claimed as locally verified
by these PHP 8.4 runs. Neither SQLite success nor in-process HTML assertions
establishes complete framework, MySQL or JavaScript-browser compatibility.
