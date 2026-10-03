# Grid request-input compatibility

`GridRequestInputTest.php` exercises the production quick-search trait and column
sorter against real Laravel requests. Run it through this consumer's normal
`composer test` command, or select it with PHPUnit's `--filter GridRequestInputTest`.

The replacement for deprecated `Request::get()` preserves its exact lookup:
request attributes, then the query bag, then the request/body bag, then null.
Attributes equal to the request object retain the original absent-sentinel
behavior. Values are not coerced: present nulls, arrays and falsy values retain
their meaning, and configurable dotted keys remain literal keys. In particular,
this deliberately does not switch to Laravel's body-first, dotted-key `input()`
or Symfony's scalar-only `InputBag::get()`.

The tests cover:

- 1,331 attribute/query/body combinations per input key, with absent, null,
  empty, zero, boolean, string and array values
- Eight additional requests per key covering request-object sentinels, nested
  keys, GET, bare JSON, JSON initialized through `createFromBase`, and distinct
  JSON/body bags
- Default and dotted custom keys for both consumers: 5,356 total request cases
- Quick-search closure arguments and falsy short-circuit behavior, plus actual
  Eloquent conditions and bindings for named-column and parsed searches
- Exact sorter input and selected-column detection, both sort-direction toggles,
  cast propagation and preservation of unrelated URL query parameters

Only the intentional legacy reference call suppresses its own deprecation. A
separate handler around production execution throws on `Request::get()` notices,
even when Symfony emits them with `@trigger_error`. Other diagnostic handling is
unchanged. No package or framework class is replaced with a test double.

## Local verification (2026-10-03)

Using existing resolved consumer dependencies, without changing constraints:

- PHP 8.4.25 and 8.5.11, each with Laravel 12.69.3 / Testbench 10.12.0 and
  Laravel 13.34.0 / Testbench 11.3.0: 28 integration tests / 10,579 assertions pass
- The four new tests contribute 9,116 assertions; the existing 24 tests remain
  included
- All nine standalone compatibility scripts pass on both PHP versions, including
  strict lint of all 326 production PHP files
- All 73 historical BrowserKit methods pass on PHP 8.4.25 with both framework
  families, using real GD-backed image operations
- Loading the original two production files from `2d52b89` on Laravel 12 makes
  all four new tests fail on the deprecated getter. The original BrowserKit
  run emits 186 getter notices; the fixed run emits zero

Laravel 13 implements the legacy getter directly, without invoking Symfony's
warning-producing implementation. Its quiet baseline alone is therefore not a
valid negative control. It still participates in the behavioral parity checks.

The BrowserKit runs retain unrelated Faker and Intervention Image diagnostics on
this base revision. This change does not claim all package paths are free of
warnings: action-request handlers and other consumers are outside its scope.
Full BrowserKit coverage was not rerun on PHP 8.5, which lacks GD in the local
runtime; PHP 8.5 results above cover integration and standalone tests only.
