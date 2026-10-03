# Checkbox zero-valued choices

Checkbox previously removed integer `0` and string `'0'` from the selected
values with an unqualified `array_filter()`. Both ordinary and grouped views
therefore unchecked stored zero choices. Submitting an untouched form could
silently remove zero from JSON-array or comma-separated storage.

The shared selection filter now retains exactly those two zero representations
in addition to the values the original truthy filter retained. Option matching
still uses the existing loose `array_search()` comparison. This is deliberately
not a broad change to false/null/blank semantics: `false`, floating-point `0.0`,
`null`, and the empty hidden marker remain filtered out. Explicit `checked()`
and array/closure `default()` configuration retain their existing behavior.
Old input still overrides model/default selections; the existing additive
`checked()` fallback when the effective field value is null is unchanged.

Server-side `MultipleSelect::prepare()` is unchanged. Normal browser successful
controls send strings and preserve zero; the trailing empty hidden input still
allows clearing. Storage is consumer-owned: the regression uses a native Eloquent
array cast and a conventional CSV setter. This does not redefine relationship,
boolean-cast, malformed old-input, or Radio behavior. An empty form submission
continues to normalize empty/NULL storage according to the existing field and
model contract rather than promising preservation of database NULL.

Applications overriding/publishing `admin::form.checkbox` need to update their
own template to receive the fix; application-owned views are not overwritten.

## Regression coverage

`CheckboxZeroTest.php` covers flat and grouped arrays/CSV, integer/string zero,
zero-only and mixed choices, legacy false/null/blank and nonempty comparisons,
explicit checked/default values, old-input precedence, create/update/clear, and
failed-validation redisplay followed by a successful corrected save.

`javascript/checkbox-zero.cjs` loads production-rendered HTML into the existing
jsdom dependency, initializes shipped jQuery 2.1.4 and iCheck, and compares native
`FormData` with jQuery serialization. The query is decoded with PHP `parse_str()`
before posting through the real Laravel HTTP kernel and saving to SQLite. This
preserves successful-control document order and normal contiguous `choices[]`
indexes, avoiding DomCrawler's sparse checkbox-index representation. No new
JavaScript dependency is needed.

Run using the standard integration consumer on Laravel 12 and 13:

```sh
npm ci --ignore-scripts --prefix javascript
composer test -- --filter CheckboxZeroTest
```

This is offline DOM plus in-process HTTP/SQLite coverage, not a real browser,
visual layout, cookie/CSRF, or live PJAX test.
