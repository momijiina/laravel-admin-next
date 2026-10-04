# Ordinary MultipleSelect null entries after validation

Ordinary MultipleSelect includes an empty hidden `name[]` marker so a cleared
widget can submit an empty selection. Laravel's default web middleware turns
that empty string into null. After another field fails validation, `Form::store()`
flashes the array as old input: clearing yields `[null]`, and selecting one yields
`['1', null]`. The former loose `in_array` comparison matched the null marker to
option zero, adding an unintended zero on redisplay and on a corrected retry.

The ordinary view now removes only null members from the effective
`(array) old($column, $value)` before matching options. Explicit `0` and `'0'`
remain selectable. Non-null loose comparisons, explicit empty arrays, old-input
precedence, and value/default/closure-default behavior retain their contracts.
This is not a broad truthiness filter or strict-typing change.

## Upgrade cautions

- Applications overriding `admin::form.multipleselect` must reconcile their own
  view; this change does not overwrite published or application-owned views.
- The hidden clearing marker, Select2 configuration, field names, escaping,
  preparation, validation and persistence code are unchanged.
- Middleware, custom validation, casts, callbacks, remote options and relation
  persistence can affect application behavior. This regression uses default web
  middleware and a plain JSON-array-cast column in disposable SQLite.
- Action MultipleSelect and Checkbox use different views and are outside this
  fix. Nested HasMany old-input identity and relationship storage are not covered.

## Regression scope

`MultipleSelectNullOldInputTest.php` renders full ordinary Forms through Laravel's
HTTP kernel, executes emitted scripts with the shipped jQuery, Bootstrap, iCheck
and Select2 assets in offline jsdom, and reads native FormData. It checks both
clear-all and removal of the last selection, legitimate nonzero/zero/mixed
choices, the hidden marker, real failed validation redirect and session old
input, redisplay, reopening, and corrected retry through `Form::store()` to SQLite.
A PHP integer-zero HTTP input is separately labeled because browser controls
submit strings. Defaults and a non-null legacy comparison matrix are also tested.

```sh
composer test -- --filter MultipleSelectNullOldInputTest
```

Use the integration consumer's normal Node dependencies and supported Laravel
12/13 commands in the README. No new dependencies or remote assets are required.
This is offline DOM plus HTTP-kernel coverage, not a live-browser, network
wire-encoding, authentication/authorization, cookie or CSRF security audit.
