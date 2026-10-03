# Native date cast edit/save regression

`DateCastPresentationTest.php` boots the real package in a disposable Testbench
application and SQLite database, generates a model-backed controller with
`admin:make`, authenticates, requests its edit form and performs an ordinary PUT.
Node.js executes the **shipped Moment asset**, using datetimepicker's fixed-format,
non-strict parser, to reproduce the values submitted after picker initialization.
This is an HTTP-kernel plus JavaScript-asset test, **not a real browser test**.

## Failure and fix boundary

Laravel's default native `date`, `datetime`, `immutable_date` and
`immutable_datetime` casts serialize as UTC ISO strings. The picker previously
read those UTC calendar/clock components as application-local input. In Tokyo,
`2026-10-03` became `2026-10-02`; a normal edit/save persisted the changed date.

Only the built-in Date and Datetime fields backed by direct model attributes are
normalized. The field must have a native cast with no custom cast format and its
value must exactly equal that attribute's default `toJSON()` serialization. A
copy of the cast date is rendered in the application timezone, field locale and
Moment-style field format. The model, storage values and model serialization
remain unchanged. A custom `serializeDate()` result is preserved unless it is
identical to the default native ISO serialization.

Ordinary strings (including explicitly zoned ISO strings), custom-format casts,
custom formatter/render callbacks and explicit `parseInputDate`/nonempty
`timeZone` picker options retain their existing behavior. Laravel old input and
explicit input attributes still take precedence. Null values remain empty.

This Date/Datetime fix intentionally does not change Time/Month/Year, range fields,
custom Date subclasses, relation/dotted fields, model-less/widget forms or their parsing
contracts. Applications using those custom paths remain responsible for their
own presentation format and timezone. Native DateRange/DatetimeRange normalization
is covered separately by the [range regression guide](DATE_RANGE_CAST_PRESENTATION.md).
The existing separate generator issue
where a nullable date receives an empty default is not changed here.

## Coverage

- UTC, Asia/Tokyo and America/Los_Angeles, mutable and immutable native casts
- Early/late clock values spanning UTC calendar boundaries
- Los Angeles spring/fall DST transition dates, before and after the transition
- Actual generated-controller edit HTML, shipped Moment parsing and SQLite PUT
  round trips, with unchanged raw database and serialized model values
- Null, flashed old input (including empty input), explicit input attributes
- Field formats with literals and timezone tokens, localized month output,
  ordinary picker options and preserved parsing/timezone overrides
- Uncast date/custom-format/explicit-zone strings, standalone fields,
  custom-format casts, model-level storage date formats and custom
  `serializeDate()` output
- No model attribute/timezone mutation while formatting mutable/immutable casts

## Running

Resolve the normal isolated consumer as described in [README](README.md), with
PHP and **Node.js** on PATH, then run:

```sh
vendor/bin/phpunit --filter DateCastPresentationTest
```

Node is required, not silently skipped. The hosted integration workflow checks
its availability before running the suite. The official
[Ubuntu 24.04 runner manifest](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)
for image `20260927.320.1` lists Node 22.23.3; `ubuntu-latest` uses that image family
at verification time. Other PHP versions are covered by the hosted matrix, not
claimed as locally executed here.

## Local verification (2026-10-03)

PHP 8.5.11, Laravel 13.34.0/Testbench 11.3.0/PHPUnit 12.5.37, Node 24.19.0:
**12 tests, 282 assertions pass**. These tests use the surviving resolved Laravel
13 consumer with an isolated package PSR-4 mapping to this checkout; they do not
change its installed dependencies or consumer configuration. Testbench initially
wrote its normal compiled Blade view cache under the vendor application storage;
subsequent local source-mapped checks use a separate scratch view-cache directory. The original Date implementation from base `2dc0b8f`
fails 9 cases. In the Tokyo mutable and immutable cases the negative control
executes the PUT and observes the previous calendar day and shifted clock in
SQLite before failing. No browser automation is claimed.

The full Laravel 13 suite passes **69 tests, 35,678 assertions**, with two opt-in
external database service cases skipped. Existing `str_replace(null)`
deprecations in `HandleController::getCallMethod()` and its reference test remain
visible and are outside this fix. All nine standalone compatibility scripts pass,
including diagnostic-free lint of 328 production PHP files. These focused results
do not certify the absence of diagnostics in other application paths.

The same final focused and full suites also pass on the separately surviving
Laravel 12.69.3/Testbench 10.12.0/PHPUnit 11.5.56 installed dependency set, again
with an isolated package source mapping: **12/282** focused and **69/35,678** full,
with the same two service skips and pre-existing diagnostics. Both installed
sets pass actual-runtime Composer `check-platform-reqs`. Neither is claimed as a
fresh resolution: an attempted new Laravel 12 resolution was stopped during slow
metadata fetching after the existing consumer was located. Normal hosted CI
still resolves its own dependencies with platform and advisory checks enabled.
