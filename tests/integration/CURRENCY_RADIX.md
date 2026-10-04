# Currency configured-radix preparation

`Currency::prepare()` now replaces an explicitly configured, nonempty string
`radixPoint` with `.` in string input before the existing PHP float cast. For
example:

```php
$form->currency('amount')->options(['radixPoint' => ',']);
```

With the shipped Inputmask and Currency's default `removeMaskOnSubmit: true`, a
stored `1234.56` is shown as `1.234,56` and submitted as `1234,56`. Inputmask removes
the grouping characters, prefix and suffix, but retains its configured radix.
Previously the PHP cast truncated that submission to `1234`; `-0,56` became zero.
Those unmasked strings now prepare as `1234.56` and `-0.56`. When comma radix
collides with Inputmask's inherited comma grouping delimiter, the shipped widget
itself changes grouping to a dot; no server-side grouping removal is needed.

## Compatibility and application responsibilities

- This is a preparation fix, **not a localized-validation fix**. Form validation
  still runs before submitted/saving hooks and preparation. Laravel's `numeric`
  rule rejects `1234,56`, preserves the stored value and flashes the localized
  old input. Applications must use validation appropriate for the values they
  actually submit. This patch does not move validation or normalize requests.
- Accepted submitted and saving hooks still see the localized string. Laravel's
  ordinary empty-string-to-NULL middleware still applies. A saving hook can
  continue supplying a canonical dot string or a native float; those values are
  not stripped of decimal points.
- The return value remains a PHP float, with its existing precision and rounding
  limitations. This does not provide exact decimal arithmetic, recover values
  already truncated in storage, change SQL column types or make SQLite evidence
  equivalent to another database engine.
- Default dot-radix Currency and the inherited nonstring/null/empty float-cast
  behavior are preserved. A blank Currency submission still prepares as `0.0`;
  this does not introduce nullable Currency storage semantics. Decimal and
  Percentage are unchanged.
- This does not parse arbitrary localized or masked strings. In particular,
  `removeMaskOnSubmit: false`, custom unmask/negation callbacks, callback-valued
  radix settings, malformed options and dynamically changed client-only radix
  settings and customized affixes containing grouping characters are outside
  the supported round-trip boundary. The server uses the
  field's declared literal string radix; it does not execute JavaScript options,
  remove generic grouping separators, strip currency symbols, or validate input.
- No shipped browser assets or views changed; this server-side fix does not
  require republishing assets. Custom Currency subclasses overriding `prepare()`
  must carry the relevant conversion themselves.

## Regression scope

Run with the [isolated Laravel 12/13 integration harness](README.md):

```sh
composer test -- --filter CurrencyRadixTest
```

`CurrencyRadixTest.php` renders real package fields and runs their emitted
initializers with the shipped Inputmask in offline jsdom, using both the shipped
jQuery 2.1.4 and jQuery 3.7.1. It exercises untouched values and real select-all
paste/blur/submit events, checks native FormData against jQuery serialization,
and sends those payloads through actual Form create/update HTTP requests to
SQLite. No widget, Form or database implementation is stubbed.

Coverage includes default dot and comma radix, inherited/explicit grouping,
space grouping, dot grouping with prefix/suffix, positive/negative fractions and zero, blank
initial values and clearing, ordinary readonly/disabled omission, default
Decimal controls, unchanged validation failure/old-input redisplay, raw-input
hooks and canonical saving-hook replacements. Strict direct preparation checks
cover literal custom radix, canonical dot strings, malformed masked input and
nonstring/null/scalar/array values and invalid radix types.

The harness uses Testbench's in-process HTTP kernel and disposable SQLite;
CSRF is bypassed by the framework's test environment. It does not establish
real-browser layout, browser-cookie/CSRF, live PJAX, custom widget overrides,
arbitrary locale formats, all mask callbacks, or non-SQLite behavior.
