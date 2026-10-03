# Ordinary nullable Select values

An ordinary Select with options such as `[0 => 'Draft', 1 => 'Published']`
previously marked zero selected for an effective `null` value: PHP's loose
comparison considers `0 == null` true. Rendering and submitting an untouched
nullable record could consequently change SQL NULL to zero.

The shipped Select view now excludes `null` from option-key comparisons. Flat
options retain their existing empty placeholder. Grouped options get an empty
choice only when the effective value is `null`; otherwise the native select
would implicitly submit its first option even without a `selected` attribute.
Non-null option matching keeps the existing loose-comparison contract, including
`0`, `'0'`, and `false` selecting zero, and `1`, `'1'`, and `true` selecting one.
This is not a strict-typing change to Select or other field APIs.

## Defaults and upgrade notes

- The effective value still comes from `old($column, $value)`. Submitted null or
  zero after validation takes precedence over the stored value and defaults.
- `default()` and closure defaults retain their existing meaning. In particular,
  filling a null model value still uses an explicit default. A configured default
  of zero intentionally selects zero; this change does not remove generated
  controller defaults or redefine the field's `value()` method.
- Select2 placeholder/configuration, hidden clearing input, names, groups, and
  escaping are unchanged. Non-null grouped selects keep their previous options.
- Applications overriding/publishing `admin::form.select` must update their own
  view to receive this fix. No application-owned view is overwritten.
- Empty submissions become null through Laravel's normal empty-string middleware
  in these tests. Consumers using different middleware, validation, casts, or
  saving callbacks remain responsible for their own persistence behavior.

## Regression coverage

`NullableSelectTest.php` executes production Blade and uses DomCrawler's actual
rendered form controls to obtain submission values, then sends them through the
Laravel HTTP kernel and real `Form::store()`/`update()` into disposable SQLite.
It covers flat/grouped options, blank create/store, unchanged null/zero/one
edit/save, native boolean model casts, selecting zero, failed validation and
corrected redisplay submissions, old-null precedence over defaults, scalar and
closure defaults, custom placeholder configuration, and a legacy non-null
string/numeric/boolean key matrix.

Run it with the standard integration consumer on each supported framework family:

```sh
composer test -- --filter NullableSelectTest
```

Original-template and mutation checks additionally verify that the assertions
reject a broad strict comparison, truthiness guard, missing grouped blank choice,
and removal of old-input precedence. These are test-only controls, not shipped
code. Existing standalone and BrowserKit suites remain applicable.

This coverage is ordinary Select only. Remote/AJAX options, multiple-select,
relationship widgets, nested HasMany identity, live Select2 browser interaction,
and browser-cookie/CSRF end-to-end behavior are outside this regression's scope.
No new dependencies are required.
