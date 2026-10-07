# Grid inline Checkbox integer IDs

A Grid column configured with `checkbox($options)` now checks stored integer
IDs when its inline popover opens. Previously, an array-cast `[1, 2]` could
show `One;Two` in the closed cell but no checked boxes in the editor. Adding
another choice then submitted only that choice, losing the existing IDs on save.
Integer zero and mixed integer/string arrays were affected too.

## Contract and upgrade cautions

- For actual arrays, convert selected members to strings in a local comparison array, because
  native HTML checkbox values are strings. Non-array values keep the existing
  lookup behavior, including an unchecked editor for NULL. Matching remains strict: `"001"`
  stays distinct from `"1"`. No loose or numeric-coercion matching is added.
- Opening and cancelling leave the trigger's JSON metadata, original scalar
  types, displayed labels and database unchanged. The existing submit handler
  continues to send strings in option order and updates cached metadata after
  its successful response.
- No PHP displayer, callback, payload format, Form preparation, model cast,
  schema or other editor changes. Existing string-array storage after a
  submission is unchanged. No dependency/minimum-version, JavaScript asset
  republish or data migration is required. Previously lost IDs cannot be restored.
- Reconcile published/custom `admin::grid.inline-edit.checkbox` Blade views,
  refresh compiled views through the application's normal deployment process,
  and reload open grids. Package updates do not overwrite application overrides.
- This is limited to arrays of scalar IDs. NULL/malformed values, nested arrays,
  relationships, missing options, empty-selection submission semantics and
  JavaScript's integer-precision boundary are not redesigned. Retain string IDs
  for integers outside JavaScript's safe-integer range. Verify integration with
  each consuming Laravel application.

## Regression coverage

`GridInlineCheckboxTest.php` renders the real Grid, boots the web HTTP kernel,
updates through Form, and checks Eloquent array casts and disposable SQLite.
The offline DOM fixture runs emitted scripts with shipped Bootstrap popovers,
iCheck assets, jQuery 2.1.4 and jQuery 3.7.1. It clicks the actual trigger,
changes native checkboxes, cancels, reopens and invokes the real submit handler.

Only AJAX transport is intercepted. The exact jQuery query string is replayed
through the in-process route, and a repeat interaction receives the actual
successful response to exercise metadata, labels and reopening. A fresh server
render checks persisted selections; an unrelated row remains unchanged. Cases
include integer IDs, zero, mixed arrays, numeric strings, leading-zero strings,
strictly distinct IDs, deselection, ordinary strings, an initially empty array
and unchanged NULL opening followed by an explicit selection.

```sh
composer test -- --filter GridInlineCheckboxTest
```

Testbench bypasses CSRF and uses SQLite. This is offline DOM and in-process
HTTP coverage, not a visual-browser, live-network/PJAX, keyboard-accessibility,
relationship-editor, non-SQLite or arbitrary application-override guarantee.
No dependencies are added.
