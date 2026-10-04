# CheckboxButton and CheckboxCard zero-valued choices

CheckboxButton and CheckboxCard previously removed integer `0` and string `'0'`
from their initial selections with a truthy `array_filter()`. A stored zero choice
therefore rendered unchecked with an inactive label. Saving an untouched form
could remove zero from JSON-array or comma-separated storage. With a zero-valued
conditional field, initial visibility could disagree with the rendered selection.

Both views now use the same bounded selection filter as ordinary Checkbox:
retain integer `0` and string `'0'` in addition to previously truthy values.
Each option uses one result for its `checked` input and `active` label. The shipped
click handler and styles are unchanged.

## Compatibility

- Existing loose `array_search()` option matching is preserved. `false`, `null`,
  floating-point `0.0` and empty strings remain filtered out; this does not add
  support for boolean choice values or redefine ambiguous numeric aliases.
- Array/closure defaults, old-input precedence and the additive `checked()`
  fallback when the effective field value is null keep their existing behavior.
- Hidden clearing markers, server preparation, conditional operators and storage
  are unchanged. Normal browser controls submit strings, including `'0'`.
  An empty submission still normalizes NULL/empty storage according to the
  consumer's existing field/model contract; it does not preserve database NULL.
- Options remain flat in the Button/Card views. This does not add grouped-options
  support to them. Ordinary Checkbox's flat/grouped views are unchanged and run
  as regression controls.
- Applications overriding/publishing `admin::form.checkboxbutton` or
  `admin::form.checkboxcard` need to reconcile their own templates to receive
  this fix. Application-owned views are not overwritten, and no asset refresh
  is needed for this view-only correction.

## Regression coverage

`StyledCheckboxZeroTest.php` covers integer/string zero, CSV/array input, sparse
selections and option order; existing false/null/blank and loose comparisons;
`checked()`, array/closure defaults and old input; unchanged-edit, create, clear,
validation failure and corrected retries for SQLite JSON-array and CSV models.

`javascript/styled-checkbox-zero.cjs` parses production forms and the actual
ready-wrapped, deduplicated script output. It runs shipped Bootstrap and the
Button/Card label handlers with shipped jQuery 2.1.4 and jQuery 3.7.1 in offline
jsdom, including iCheck for the ordinary form footer. Initial and repeated-click
`checked`/`active` states must agree; Card body
clicks and one change event per click are checked. An independent styled field
must remain unchanged. Native FormData must match jQuery serialization; PHP
`parse_str()` decodes those successful controls for real HTTP-kernel requests,
web middleware, validation sessions and SQLite persistence.

`CollectionConditionalFieldsTest.php` also covers zero-valued defaults, stored
values, explicit-empty old input and failed-validation zero redisplay on both
styled widgets, using the existing conditional-field operators and scripts.

```sh
composer test -- --filter 'StyledCheckboxZeroTest|CheckboxZeroTest|CollectionConditionalFieldsTest'
```

Use the integration README's Laravel 12/13 and Node setup. This is offline DOM
and in-process HTTP/SQLite coverage, not live browser, visual layout, PJAX,
cookie/CSRF, relationship storage, nested identity or non-SQLite coverage.
