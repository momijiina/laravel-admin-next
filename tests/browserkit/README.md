# Historical BrowserKit suite

This isolated runner executes all 73 historical methods in `tests/*Test.php`
against supported Laravel 12/13 releases. It does not use the obsolete root
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

No methods or assertions are removed. Four historical test files are updated:

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

The original `tests/TestCase.php` and root PHPUnit configuration are left as
historical references. Run this suite through its own configuration; recursively
collecting the entire `tests/` tree mixes incompatible harnesses.

## Diagnostics and local evidence

All 73 methods pass locally on PHP 8.4.25 with both Laravel 12 and 13, including
real GD-backed rotation/flip requests. The suite also passes in randomized order
(seed 7301). Assertion totals vary because some historical tests intentionally
choose random fixture counts.

This is a **behavioral pass, not a deprecation-free pass**. E_ALL and Laravel's
`LOG_DEPRECATIONS_WHILE_TESTING` remain enabled, with deprecations sent to stderr.
The revived coverage exposes existing warnings from Faker's legacy property
API, Symfony's deprecated `Request::get()` and nullable values passed to
`strlen()` in `MultipleSelect`. Laravel-handled deprecations are logged and do
not necessarily fail PHPUnit, even with `failOnDeprecation` enabled. These
production/dependency cleanups belong in separate changes; no warnings are
suppressed by this runner.

PHP 8.2/8.3/8.5 jobs are configured in CI but are not claimed as locally verified
by these PHP 8.4 runs. Neither SQLite success nor in-process HTML assertions
establishes complete framework, MySQL or JavaScript-browser compatibility.
