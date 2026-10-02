# Real Laravel integration tests

This is an isolated Composer **consumer** of the repository via a local path
repository. It uses [Orchestra Testbench](https://github.com/orchestral/testbench)
to boot real Laravel and SQLite, while leaving the package's production and
legacy development constraints unchanged. The PHP requirement in this directory
applies only to this harness, not to the shipped package.

Run from this directory, using a PHP CLI with the required extensions:

```sh
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

## What this covers

- Real package service provider, published-default configuration, middleware
  aliases/groups, session guard, migrations and seed data
- Login view, guest redirect, valid/invalid login, authenticated request, logout
- Real Artisan blank-controller generation and rejection of model-less `--output`
- Actual HTTP-kernel middleware lifecycle, including consumer bootstrap execution
- SQLite-persisted operation logs, recursive redaction, preserved controller input,
  validation failures, custom redaction fields, logging disablement and exclusions

No package/framework classes are replaced with stubs. Small fixture routes return
JSON or validate input to observe the real middleware before/after a controller.
Each test uses a fresh application and an in-memory database. Testbench registers
the provider explicitly and Laravel's testing environment bypasses CSRF validation;
this is not package-discovery or browser-cookie/CSRF end-to-end coverage.

## Limits

The root PHPUnit configuration excludes this consumer directory, keeping its
classes and vendor tree out of legacy test discovery. This suite does not replace
or claim to pass the 73 legacy BrowserKit tests.
Uploads, browser JavaScript, complete CRUD, non-SQLite drivers and model-based
generator compatibility remain separate work. Known PHP 8.4 implicit-nullability
deprecations remain in the package; this is not a zero-deprecation certification. The PHPUnit configuration enables E_ALL
and routes Laravel/Testbench deprecation logs to stderr so those notices remain
visible (14 implicit-nullability notices on both frameworks, plus two Symfony
`Request::get()` notices on Laravel 12). Successful focused integration tests are not a full framework support claim.

## Local verification (2026-10-02)

PHP 8.4.25, SQLite 3.53.4, DBAL 3.10.6 and DomCrawler 5.4.52:

- Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56: **10 tests, 54 assertions pass**
- Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37: **10 tests, 54 assertions pass**
- Normal Composer resolution and `check-platform-reqs` pass for both; neither
  lockfile reports known Composer security advisories at verification time
- Negative control on Laravel 13: loading the actual pre-redaction middleware
  from `b79f56d` causes the three persisted-redaction assertions to fail with
  the synthetic inputs visible in SQLite. Restoring the current middleware
  passes all ten tests. No production data is used.

The workflow additionally requests PHP 8.2/Laravel 12 and PHP 8.3/Laravel 13.
Those PHP runtimes were not executed locally; their results must come from CI.
