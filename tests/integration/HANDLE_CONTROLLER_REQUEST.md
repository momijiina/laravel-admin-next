# HandleController request-input compatibility

Run `HandleControllerRequestTest.php` through the integration consumer's normal
`composer test` command, or select it using `--filter HandleControllerRequestTest`.

The six deprecated request reads (`_form_`, `_action`, `selectable`, `args`,
`renderable`, `key`) now use one private helper. It preserves the original literal
lookup: attributes, query, request/body, default. This includes the request-object
absent sentinel, present nulls, arrays, falsy values and the `args` empty-array
default. It intentionally does not use Laravel's body-first, dotted-key `input()`
or Symfony's scalar-only `InputBag::get()`.

The `_form_` and `_action` Laravel `has()` guards are unchanged. Those guards
inspect different sources from the legacy getter; attribute-only class selectors
still fail the guards. Bare JSON and JSON initialized through `createFromBase`
retain their different bag behavior.

Six new tests cover:

- 1,331 attribute/query/body combinations plus eight sentinel, literal/nested,
  GET and JSON cases for each of the six keys and an extra dotted literal key
- Differential form/action resolution against legacy reads, including missing
  guards, invalid class names, nulls, rejected arrays and classes without `handle`
- Differential selectable/renderable dispatch, positional `array_values(args)`,
  defaults, exact render keys and invalid input exception class/message parity
- Real container-injected form validation, rejection redirects, sanitization,
  request identity and successful handling
- Action authorization order, row/model arguments, validation failure,
  exception responses, non-Response returns and uncaught `TypeError` behavior

No package/framework class is replaced. Small consumer fixtures exercise the
production controller, form validation and action response code. These tests
invoke the controller directly; they do not establish browser-cookie or CSRF
coverage. Routing, middleware, CSRF, authorization and validation policy are not
changed by the production patch.

The reference call alone suppresses the intentional `Request::get()` deprecation.
Production execution throws on that notice even when Symfony uses `@trigger_error`.
Other diagnostics remain enabled. In particular, deliberately invalid null class
selectors still emit the pre-existing native `class_exists(null)` and
`str_replace(..., null)` deprecations in both reference and production execution.
This change does not silently normalize invalid input or claim all diagnostics
are gone.

## Local verification (2026-10-03)

Using the existing isolated consumer dependencies, with no dependency changes:

- PHP 8.4.25 and 8.5.11, each with Laravel 12.69.3 / Testbench 10.12.0 and
  Laravel 13.34.0 / Testbench 11.3.0: **39 tests / 35,014 assertions pass**
- The six new tests contribute 17,446 assertions; all 33 existing integration
  tests, including the merged RowAction/BatchAction regressions, remain included
- All nine standalone scripts pass on both PHP versions, including strict
  production-file lint
- All 73 historical BrowserKit methods pass on PHP 8.4.25 with Laravel 12
  (917 assertions) and Laravel 13 (914 assertions), including real GD image work;
  legacy random fixtures can vary those assertion totals
- Loading the unmodified controller from `2c718dd` on Laravel 12 makes all five
  consumer tests fail on the Symfony getter deprecation (the private-helper
  matrix is excluded because that helper does not exist in the original)

Laravel 13's inline legacy getter is quiet even before this change; it is useful
for behavioral parity, but is not evidence of the deprecation negative control.
PHP 8.2/8.3 and full PHP 8.5 BrowserKit were not run locally. The resolved runtime
vendors were reused through a process-local PSR-4 override, without modifying
shared vendors, their package symlinks or dependency constraints.
