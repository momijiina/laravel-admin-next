# Native date-range cast edit/save regression

`DateRangeCastPresentationTest.php` boots a real Testbench application and SQLite
database, authenticates an administrator, and requests an ordinary model-backed
form containing `dateRange`, `datetimeRange`, and a `timeRange` control. It extracts
both endpoint inputs and their actual picker options from the HTTP edit response,
runs the shipped Moment asset, submits the resulting values with HTTP PUT, and
asserts unchanged raw database and serialized model values.

This is an HTTP-kernel plus production-Moment regression, **not a live-browser or
full datetimepicker-widget test**. The Node helper reproduces the widget's
fixed-format, non-strict parsing and input rewriting; it does not execute picker
UI, change-event constraints, or `useCurrent` initialization behavior. The separate
[initial range-picker regression](DATE_RANGE_INITIALIZATION.md) executes the shipped
widget in offline DOM; neither test is real-browser E2E.

## Failure and narrow boundary

Default native Eloquent temporal casts serialize as UTC ISO strings. The range
picker previously read their UTC calendar/clock components as local input. In
Tokyo, an unchanged edit/save moved `2026-10-03` to `2026-10-02`, and a midnight
datetime to the previous day's `15:00:00`. Los Angeles could move a late endpoint
into the next calendar date.

Only the exact built-in `DateRange` and `DatetimeRange` classes normalize their
presentation. Each endpoint is checked independently:

- The field belongs to a model-backed Form and names a direct, non-dotted column.
- Its cast is exactly `date`, `datetime`, `immutable_date`, or
  `immutable_datetime`, without a custom serialization format.
- Its current string exactly matches **that endpoint's own attribute** `toJSON()`.
- The picker has a nonempty string `options['format']`, no custom format/render
  callback, no explicit `parseInputDate`, and no nonempty `timeZone` override.

A copy of the cast date is formatted in the application timezone using the
picker's configured Moment-style format and locale. Unlike single Date fields,
ranges configure their format through `options(['format' => ...])`. Explicit
locale takes precedence over `app.locale`. Rendering changes neither model
attributes, original/dirty state, date objects, locale/timezone, nor serialization.
Saving logic is unchanged.

Custom-format casts and different `serializeDate()` output remain untouched. A
`serializeDate()` override returning the same native ISO value remains eligible.
Ordinary strings, explicit values, standalone fields, dotted/relation columns,
custom range subclasses, and `TimeRange` retain their previous behavior. Defaults
remain defaults; null endpoints stay empty, and flashed old input (including
empty/null endpoints) still wins. A configured range `customFormat` continues to
opt out; this change does not introduce invocation into array-column `fill()`.
Non-string/empty picker formats retain their existing fallback behavior.

Applications using custom display or parsing contracts remain responsible for
matching their submitted representation to their own persistence handling. The
custom-format assertions here verify presentation, not arbitrary custom-format
save support. This change does not alter single Date/Datetime fields, generator
defaults, HasMany redisplay, or collection behavior.

## Coverage

- 48 HTTP edit/Moment/PUT cases across UTC, Tokyo, and Los Angeles: mutable and
  immutable native casts, plain strings, and matching custom-format controls
- Both populated endpoints, null start, and null end; midnight and late clocks;
  both sides of Los Angeles spring/fall DST transitions
- Both-null rendering, literal defaults, explicit endpoint values, and old-input
  precedence, including a single old endpoint with the other from the model
- Configured formats, literal/timezone tokens, explicit and inherited French
  locale, storage date formats, and no mutable/immutable model-state mutation
- Different and native-equivalent custom serialization; mixed endpoint casts;
  mismatched and swapped ISO endpoint values; custom callbacks and parsing options
- Standalone/dotted fields, custom subclasses, and TimeRange native-cast exclusion

## Running

Resolve the isolated consumer described in [README](README.md), with PHP and
Node.js on PATH, then run from `tests/integration`:

```sh
vendor/bin/phpunit --filter DateRangeCastPresentationTest
```

Node is required, never silently skipped. The parser loads the repository's
shipped Moment directly and needs no separate JavaScript dependency installation.
The broader integration suite has its own JavaScript setup documented in README.

Local verification on 2026-10-03 uses PHP 8.5.11 and Node 24.19.0 with the existing
separately resolved Laravel 12.69.3/Testbench 10.12.0/PHPUnit 11.5.56 and Laravel
13.34.0/Testbench 11.3.0/PHPUnit 12.5.37 dependency sets. Both run with an isolated
package source mapping and compiled-view directory, without changing installed
dependencies. These are local focused checks, not fresh dependency resolutions or
claims about other PHP versions.

Both framework families pass **56 tests, 1,011 assertions**, with warning, risky,
deprecation, and notice failure checks enabled. PHP lint, Node syntax checking,
and `git diff --check` also pass.
