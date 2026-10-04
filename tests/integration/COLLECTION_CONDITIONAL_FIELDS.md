# Collection conditional fields

`when('has', value, closure)`, `when('oneIn', values, closure)` and
`when('oneNotIn', values, closure)` are existing source-supported cascade
operators. `oneNotIn` means **no intersection**, not “at least one is missing.”

Checkbox, MultipleSelect, CheckboxButton and CheckboxCard now initialize cascade
conditions from JSON arrays of string choice values, rather than passing arrays
to `addslashes()` or coercing them into a JavaScript string. Initial collection
visibility uses the same existing operator table as subsequent changes. Null
collections are empty for PHP membership/intersection checks; Select2 clearing
can return null with the shipped jQuery, which is also empty for JavaScript
intersection checks. Null old-input clearing markers are not choice values.

## Compatibility

- Scalar controls retain their escaped-string `getValueByJs()` hook and existing
  startup equality behavior. Collection initialization bypasses that scalar hook;
  custom subclasses that override it for collection values should review this path.
- Comparator definitions, scalar coercion, field preparation, persistence, hidden
  markers and submission/disabled behavior are unchanged. PHP's existing loose
  comparisons and the JavaScript string comparisons are not redesigned.
- Application overrides of the trait or field classes need reconciliation. No
  published views or assets are replaced by this change.
- CheckboxButton/Card's separate initial zero-selection view behavior is outside
  this fix. Their zero-valued user changes are covered; zero defaults/edits are
  covered on ordinary Checkbox and MultipleSelect. Checkbox `checked()` fallbacks,
  ambiguous loose-matching option keys, nested collection identity, relation
  storage, remote options and custom field implementations are not tested.

## Regression coverage

`CollectionConditionalFieldsTest.php` uses the real HTTP kernel, default web
middleware, session validation redirects and a disposable SQLite JSON-array-cast
model. Production Forms and scripts run in offline jsdom with shipped Select2,
iCheck and Button/Card handlers under shipped jQuery 2.1.4 and jQuery 3.7.1.

Coverage includes create, edit, array/closure defaults, NULL/empty values, numeric
and string choices, zero, escaped strings, independent fields, array equality and
inequality, actual select/clear/remove/toggle interactions, failed validation,
old-input precedence, corrected creates and updates, and scalar Select/Radio and
protected scalar-hook controls. Scalar startup behavior is intentionally retained.

```sh
composer test -- --filter CollectionConditionalFieldsTest
```

Use the integration README's Laravel 12/13 and Node setup. This is offline DOM
and HTTP-kernel coverage, not live-browser or non-SQLite coverage.
