# Number integer editing and persistence

The ordinary `number()` field uses the shipped
`resources/assets/number-input/bootstrap-number-input.js` widget. The generator
also maps BIGINT columns to this field. Previously, keyup compared string-valued
bounds lexically: typing `2` with `min(1)->max(10)` changed the value to `10`.
Blur and +/- converted values through JavaScript Number: merely focusing and
leaving `9007199254740993` changed it to `9007199254740992`, which could pass
server integer validation and be saved.

## Corrected behavior

- Whole-number input and whole-number bounds are compared by sign, digit count
  and digits, without floating-point rounding or lexical digit-length ordering.
- Blur preserves the exact integer value. +/- use exact decimal-string carry and
  borrow for the existing unit increments, including negative values and zero.
- The production helper remains ES5-compatible. No BigInt, browser baseline,
  dependency, PHP field API, generated controller, validation or storage change
  is introduced. The original bootstrap-spin v1.0 provenance and Apache 2.0
  license header are retained; this is a local repair to the imported asset.

## Upgrade and application cautions

Refresh the application's published `vendor/laravel-admin/number-input/bootstrap-number-input.js`
and any cached/minified copy after updating the package. The existing
`php artisan vendor:publish --tag=laravel-admin-assets --force` command republishes
all bundled admin assets; back up and reconcile customized published files before
using it. Custom widgets/overridden assets need their own review.

- Exactness starts with the text that reaches this widget. A PHP floating-point
  model cast, JSON Number, consumer script or earlier conversion may already
  have rounded it; use appropriate integer/string values end to end.
- Client-side `min()`/`max()` attributes are not server validation. Apply the
  application's validation rules and database constraints. Out-of-range input
  must still be rejected server-side. PHP's `integer` rule has platform-sized
  bounds: exact widget arithmetic does not expand PHP or database integer range.
- Initialization and untouched submission remain unchanged. After interaction,
  the existing parsing policy remains: keyup extracts the first signed-digit run, while
  blur/buttons normalize whole integers or use the old `parseInt` fallback.
  Empty/invalid text becomes zero on those events; fractions/exponents are still
  truncated as before. This is not decimal-number or nullable-empty support.
- Leading zeros/plus signs retain the existing event-specific behavior: keyup
  preserves matched zero-padded digits, blur/buttons normalize them, and negative
  zero normalizes on blur/buttons. Noninteger/malformed bounds keep their legacy
  comparison fallback; use canonical integer bounds for the corrected contract.
- Enabled buttons still move by one even with `step=2`. Enabled keyboard
  filtering, change-event emission, cloning, and cached-at-initialization bounds
  remain unchanged.
- Readonly/disabled controls now suppress widget-driven changes and normalization;
  see [the state contract and limitations](NUMBER_FIELD_STATES.md). Readonly input
  remains submitted and disabled input remains omitted by native FormData.

## Regression coverage

`NumberIntegerTest.php` renders the real Number field through the HTTP kernel,
executes its emitted initializer and actual shipped widget in offline jsdom,
and checks native FormData against both shipped jQuery 2.1.4 and jQuery 3.7.1
serialization. The resulting payload is sent through real `Form::update()` with
`nullable|integer` validation and SQLite BIGINT persistence.

The matrix covers untouched edits, keyup, real DOM focus/blur, repeated events,
+/-, signed carry/borrow and zero crossings, explicit unit steps, positive and
negative bounds across digit lengths, zero bounds, exact values above 2^53,
signed 64-bit boundaries, and readonly submission/disabled omission. Separate
cases retain legacy noninteger parsing/mixed-bound behavior. Overflow beyond the
signed 64-bit range and 40-digit arithmetic remain exact in the DOM but are
rejected by server integer validation without changing the stored value.

Run using the [isolated Laravel harness](README.md):

```sh
vendor/bin/phpunit --filter NumberIntegerTest
```

This is offline DOM plus in-process HTTP/SQLite coverage. It does not establish
real-browser layout/keyboard interaction, live PJAX, arbitrary custom widgets,
non-SQLite storage, unsigned BIGINT support, 32-bit PHP behavior, or full framework
compatibility. Node's test runtime is not the production browser baseline.
