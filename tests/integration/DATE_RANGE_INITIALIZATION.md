# Initial range-picker bounds

DateRange, DatetimeRange, and TimeRange used to attach reciprocal change handlers
only after both widgets initialized. A populated 03 → 07 range therefore accepted
a first start edit to 09, although the same edit was rejected after an end edit
had established the maximum. Initial parsed endpoints now seed the peer widget's
bounds before change listeners are attached.

## Conservative initialization contract

- Seed only a fresh, unambiguous pair (exactly one matching input per endpoint).
  Re-running the ready partial does not reseed existing widget instances or undo
  application changes to their bounds. Partially initialized pairs are left alone.
- Read dates from the actual widget; do not reinterpret the input strings in PHP
  or introduce another format/parser. Null endpoints contribute no bound.
- Do not seed an already inverted populated pair. Existing values need application
  validation; initialization must not silently repair persisted values.
- Retain stricter existing minimum/maximum settings, and skip any candidate that
  would conflict with the peer's other bound. This includes per-endpoint data
  options as well as field options.
- Temporarily disable `useCurrent` only while applying each initial bound, restoring
  its exact boolean/string setting in `finally`. The shipped setter otherwise
  fills a blank endpoint from its internal current date. This is not a change to
  the package's default `useCurrent`, or to its behavior on subsequent edits.
- Custom `parseInputDate` and non-default `timeZone` configurations are deliberately
  excluded from initial seeding. The shipped plugin's default timezone is
  `Etc/UTC`; its setters can invoke a custom parser even for Moment arguments.
  These applications retain their existing initialization and change behavior.
- Existing reciprocal change behavior is unchanged, including the vendor's
  clamping and clearing semantics. Initial bound preservation is not a new
  permanent enforcement layer for custom application bounds. The plugin may
  itself transform values while processing its original options, before seeding.
- Owned change handlers use a namespace so repeated initialization does not add
  duplicates; ordinary and independently namespaced consumer handlers survive.

No HasMany selector/root scoping contract is introduced or changed.

## Regression coverage

`DateRangeInitializationTest` renders real fields and the production
`admin::partials.script` ready wrapper with Testbench, then executes the output
using shipped jQuery 2.1.4, Bootstrap, Moment 2.10, and datetimepicker in the existing
locked jsdom environment. It covers 39 fixtures across all three field types,
with two independent ranges in each fixture and more than 600 JavaScript checks:

- Populated, one-null, both-null, inverted, and explicit `useCurrent` inputs
- `keepInvalid`, custom limits (including stricter per-endpoint limits), custom
  parser execution, and timezone option preservation
- Initial bound getters and unchanged serialized values; repeated ready execution
  and preservation of deliberately cleared runtime bounds
- First invalid edits in both directions, valid edits, nullable edits, and clears
- Owned listener counts, consumer handler preservation, independent range values,
  and asynchronous error reporting

The clock is pinned through the jsdom window's Date constructor and asserted via
real Moment. Moment 2.10 does not implement the later `moment.now` override hook.

Run after installing the existing integration dependencies:

```sh
cd tests/integration
npm ci --ignore-scripts --prefix javascript
vendor/bin/phpunit --filter 'DateRangeInitializationTest|DateRangeCastPresentationTest'
```

The existing integration and DomCrawler workflows already install these Node
dependencies. The new test is included in the full integration suite. Native cast
presentation and HTTP/SQLite persistence coverage remain in
[the range-cast presentation regression](DATE_RANGE_CAST_PRESENTATION.md); this
regression does not replace that coverage.

This is offline DOM/widget execution, not browser E2E, popup/layout testing, live
PJAX, or a claim about server-side range ordering validation. No local HTTP server
is used.
