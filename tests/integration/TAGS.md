# Tags hidden-input preparation

The Tags view emits an empty hidden `tags[]` input after the selected options.
Laravel's `ConvertEmptyStringsToNull` middleware turns that final empty value
into null. Calling `strlen` on that null during `Tags::prepare()` emits a PHP
8.1+ deprecation, including on ordinary empty/create/update submissions.

The fix explicitly skips null elements before calling `strlen`. It does not
cast top-level input, reindex results, change comma storage, change UI token
separators, or alter plucked-value saving callbacks.

## Regressions

- `php tests/compatibility/tags_prepare.php`: 938 strict parity/error cases
  compare actual production preparation with the old filter/output contract.
  Only the intentional legacy oracle suppresses its known null deprecation.
  Tests cover numeric/sparse/associative keys, duplicates, null/empty/false,
  numeric and string zero, whitespace, Unicode, NUL bytes, stringable objects,
  invalid top-level input and invalid elements, plucked values and callbacks,
  plus unchanged storage with custom UI separators.
- `TagsTest.php`: real package field rendering, actual Laravel HTTP middleware,
  Form create/update/clear, and SQLite storage. Empty hidden input, zero and
  duplicate tags preserve exact database strings. Strict diagnostic handling
  makes the original deprecation fail rather than just log. Other tests cover
  real sparse/plucked preparation and unchanged nullable/comma-storage fill.

Run the integration tests using the [consumer instructions](README.md):

```sh
composer test -- --filter TagsTest
```

The existing source-compatibility workflow discovers the standalone script;
`phpunit.xml` includes the integration tests in the existing Laravel matrix.

## Scope

This is a server-side preparation fix, not JavaScript/Select2 browser coverage.
Ordinary persisted null, empty string and comma-separated strings already fill
without this deprecation. Arrays containing null passed to `fill()` are a
separate input shape and remain outside this ordinary hidden-input fix.
Sparse arrays retain their old array output rather than being silently joined.
Invalid top-level scalar/null input and nested/non-stringable elements retain
TypeError behavior. Other fields and general request deprecations are unchanged.
