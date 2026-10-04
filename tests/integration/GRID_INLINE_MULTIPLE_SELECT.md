# Grid inline MultipleSelect integer IDs

An ordinary Grid column configured with `multipleSelect($options)` now selects
stored integer IDs when its inline popover opens. Previously, the closed cell
could show `One;Two` for an array-cast `[1, 2]`, while the editor opened with no
choices selected. Adding another choice then submitted only that new choice,
losing the existing IDs on save. Integer zero had the same problem.

## Contract and upgrade cautions

- The inline view converts the selected array's members to strings in a local
  comparison array. Option values are strings in HTML. Matching remains strict,
  so `"001"` stays distinct from `"1"`; there is no numeric coercion or loose
  equality. String IDs and mixed integer/string arrays retain their choices.
- Opening or cancelling does not change the trigger's original JSON metadata,
  displayed labels, or stored model data. The existing submit handler still
  sends selected option values as strings in option order, and updates its
  jQuery metadata only after a successful response.
- No PHP displayer, payload format, model cast, Form preparation, database
  schema, or other inline editor is changed. Existing string-array storage after
  submission is unchanged. No data migration or published asset refresh is
  required.
- Applications overriding `admin::grid.inline-edit.multiple-select` must update
  that overridden Blade view to receive the fix. Normal application view-cache
  deployment practices still apply; package updates do not overwrite overrides.
- This change targets arrays of scalar IDs. NULL/malformed values, nested arrays,
  relationship shapes, missing options, empty-selection submission semantics,
  and JavaScript's integer-precision limits are not redesigned. Applications
  needing exact IDs beyond JavaScript's safe-integer range should retain their
  existing string-ID handling.

## Regression coverage

`GridInlineMultipleSelectTest.php` uses real Grid rendering, the web HTTP kernel,
Form update, Eloquent array casts and disposable SQLite. The offline DOM fixture
runs emitted ready scripts with the shipped Bootstrap popover, jQuery 2.1.4 and
jQuery 3.7.1. It opens the editor by clicking its real trigger, changes native
options, clicks Cancel, reopens and submits with the actual AJAX handler.

Only AJAX transport is intercepted. The emitted jQuery query string is posted
through the real in-process HTTP route. A repeat of the same interaction then
receives that actual successful response and checks the success callback,
labels, metadata and reopening. A fresh server render independently verifies
persisted values. Cases cover integer IDs, zero, mixed arrays, numeric strings,
leading-zero strings, distinct `1`/`"001"` selections and deselection, ordinary
string IDs, and an initially empty array.

Run through the standard integration consumer for each framework family:

```sh
composer test -- --filter GridInlineMultipleSelectTest
```

Testbench bypasses CSRF and uses SQLite. This is offline DOM and in-process HTTP
coverage, not visual-browser, live network/PJAX, keyboard-accessibility,
relationship-editor, non-SQLite, or arbitrary application-override coverage.
No dependencies are added.
