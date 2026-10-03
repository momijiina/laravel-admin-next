# Explicit display callbacks for native object values

A direct Eloquent `object` cast (a non-Stringable `stdClass`) can be passed to
an explicitly configured grid display callback. Previously `Column::fill()`
called `htmlentities()` on the object before invoking its callback, causing a
TypeError in both grids and the CSV exporter.

The callback chain must finish as a scalar or null. Returning an object (including
Stringable, Renderable, Htmlable or Jsonable), an array, or a resource throws
`UnexpectedValueException` before row output. Intermediate objects are allowed
when a later callback transforms them. The original value and bound row model,
callback ordering, and defined-column callback replacement are preserved.
Defined closures and existing AbstractDisplayer subclasses follow the same
scalar/null rule. This does not introduce a new renderable-object contract.

Explicit callbacks own their HTML escaping, as with existing display callbacks.
For example, return `htmlspecialchars($value->label, ENT_QUOTES, 'UTF-8')` to
render an object's text safely. Nested properties can be handled by the callback.
There is no default JSON serialization or additional escaping of callback output.

## Boundaries

- An object without a grid display callback still fails, even if only an exporter
  `column()` callback is configured.
- Arrays/list roots containing objects remain unsupported by the existing encoder.
- Strings, Stringable inputs (including stdClass subclasses), and existing arrays
  retain their original encoding and callback-result behavior.
- CSV formatting, quoting, escaping, original-value and export-column APIs are
  unchanged. Ordinary scalar columns still contain the grid's HTML entities.
- Exceptions thrown by callbacks propagate from Column unchanged. Grid's existing
  render-time exception handler continues to apply.

## Regression coverage

`ObjectDisplayTest.php` uses real Testbench, SQLite and native Eloquent casts.
It checks ordered model-bound callbacks, original object identity, nested values,
all scalar/null result types, identity/array/object/resource rejection, defined
closures/classes, Stringable coercion ordering and unsupported input roots.

A real grid renders explicit escaped synthetic markup; DOM parsing checks its
text and absence of markup child elements. A separate grid build verifies an
invalid output fails before row rendering. No browser executes the fixture.

The exporter fixture runs in a subprocess because the real `CsvExporter::export()`
sends a Symfony streamed response and exits. The test parses its actual BOM/CSV
bytes with the matching delimiter, enclosure and escape, checking exact Unicode,
quotes, ampersands, newline and nested JSON round trips, filtering, current-page
and selected-row scopes, custom titles/columns and scalar/null fields. Negative
exports fail before any CSV rows. No exporter, response or framework is mocked.

Run with the isolated consumer's normal `composer test`, or filter
`ObjectDisplayTest` with its PHPUnit runner. PHP 8.5.11 was used locally with the
existing Laravel 12.69.3 and 13.34.0 dependency sets; other runtimes remain CI work.
