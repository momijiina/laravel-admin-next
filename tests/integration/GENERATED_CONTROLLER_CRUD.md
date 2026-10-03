# Generated-controller HTTP CRUD regression

`GeneratedControllerCrudTest.php` creates a disposable SQLite table and invokes
both real Artisan entry points, `admin:make --model=...` and `admin:controller`.
It loads each generated PHP file without editing its contents and registers it
as a Laravel resource behind the normal `web` and `admin` middleware groups.
No controller, form, grid, model persistence or authorization implementation is
stubbed or replaced.

For each generated controller the test checks:

- Guest index and store requests redirect to login, with no inserted record
- Login through the real seeded admin guard
- Empty index and create-form HTML, including all four editable fields
- Store and redirect, with string, nullable-string, integer and decimal values
  verified from SQLite
- Populated index, show and edit HTML, including escaped string output
- Update and redirect, with numeric zero and a populated nullable field
- A second update clearing that nullable field through Laravel's normal
  empty-string-to-null middleware
- JSON delete response and absence of the record, then logout

The fixture uses timestamps and an auto-increment key, but does not customize the
generated controller or claim validation rules the generator does not add.
Temporary source files are removed at teardown. Distinct generated namespaces
avoid PHP class collisions when the test is repeated in one process.

## Run and local evidence

Use the normal [integration consumer setup](README.md), then:

```sh
vendor/bin/phpunit --filter GeneratedControllerCrudTest
composer test
```

On 2026-10-03, using source based on `77a86b7` plus this test patch and existing
resolved consumer dependencies (DBAL 3.10.6):

- PHP 8.4.25 and 8.5.11, Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56:
  focused test passes with **1 test, 94 assertions**
- PHP 8.4.25 and 8.5.11, Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37:
  focused test passes with **1 test, 94 assertions**
- Full integration suite on those four combinations: **57 tests, 35,396
  assertions, 2 service skips** each; existing invalid-input controller
  deprecations remain, as documented in [the controller regression](HANDLE_CONTROLLER_REQUEST.md)

This is in-process HTTP-kernel coverage, not browser JavaScript, cookie/CSRF
end-to-end coverage or package auto-discovery verification. Testbench bypasses
CSRF in the testing environment and explicitly registers the package provider.
The test uses only SQLite and representative scalar fields; it does not establish
all database drivers, field types, upload/relation workflows, or general framework
compatibility. No production failure was found in these CRUD paths.
