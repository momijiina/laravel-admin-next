# Action request-input compatibility

`ActionRequestInputTest.php` exercises the production row and batch action model
resolvers with real Laravel request bags and SQLite-backed Eloquent models. Run
it with this consumer's `composer test`, or select `--filter ActionRequestInputTest`.

The replacement of the four deprecated `Request::get()` calls retains attribute,
query, then request/body precedence. A present null blocks fallback; arrays and
other non-scalar values are not coerced. The request object remains the original
attribute-missing sentinel. Keys remain literal, and Laravel's separate JSON bag
is not silently substituted for the request/body bag.

The five tests cover:

- 1,728 attribute/query/body combinations per key and action, plus nine sentinel,
  dotted/nested-impostor, GET and JSON cases: 6,948 differential request cases
- Exact resolved model classes, attributes and collections, or exception types
  and messages, against the previous lookup/resolution algorithm
- Falsy key rejection before model-class lookup, invalid model shapes, unknown
  classes, missing IDs and prevention of fallback to lower-priority body values
- Underscore decoding of namespaced model classes, batch comma splitting, and
  resolution of soft-deleted records through the actual `withTrashed()` path
- Production controller dispatch and authorization rejection for row and batch
  actions: the authorized model comes from the winning request source, and the
  handler is never called when authorization denies it

A strict error handler turns every production diagnostic, including suppressed
Symfony deprecations, into an exception. Only the intentional legacy oracle's
`Request::get()` notice is suppressed. Invalid-input diagnostics become observable
failure outcomes and must match the legacy outcome; they are not silently ignored.
No package or framework class is replaced. The authorization test supplies a
fixture action by overriding only action-instance resolution, avoiding the
unrelated `_action` getter in `HandleController` while exercising its actual
model retrieval, authorization and dispatch sequence. This is not an HTTP/CSRF
or complete action-dispatch security audit.

## Local verification (2026-10-03)

Existing resolved consumers were reused without dependency changes:

- PHP 8.4.25 and 8.5.11, each with Laravel 12.69.3 / Testbench 10.12.0 and
  Laravel 13.34.0 / Testbench 11.3.0: 33 tests / 17,568 assertions pass
- The five focused tests contribute 6,989 assertions
- All nine standalone compatibility scripts pass on both PHP versions, including
  strict lint of all 326 production PHP files
- All 73 historical BrowserKit methods pass on PHP 8.4.25 with both frameworks,
  including GD-backed image operations; randomized seed 18057 also passes on both
- Loading the original two production files from `9d80305` on Laravel 12 makes
  all five focused tests fail on the deprecated getter (three failures, two errors)

Laravel 13's quiet baseline is not a valid deprecation negative control: it
implements the legacy getter inline instead of calling Symfony's deprecated
implementation. It still participates in the behavioral differential checks.
BrowserKit retains unrelated Intervention Image 2 dependency deprecations.
BrowserKit was not run on PHP 8.5 because that isolated runtime lacks GD.
This change does not modify controller dispatch, guards, authorization, database
requirements, or unrelated deprecated request consumers.
