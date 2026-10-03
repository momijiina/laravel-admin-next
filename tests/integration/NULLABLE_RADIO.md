# Ordinary nullable Radio values

An ordinary Radio with `[0 => 'Draft', 1 => 'Published']` previously checked
zero for a null effective value because PHP considers `0 == null` true.
Submitting an untouched nullable record could therefore replace SQL NULL with
zero. The view now excludes null from option-key comparisons and keeps all
non-null loose comparisons, including false/zero and true/one.

The effective selection still uses `old($column, $value)`. Explicit scalar and
closure defaults retain their meaning: a null model value with a default of
zero intentionally selects zero. The existing label-based `checked()` fallback
remains additive when the field value is null, including with old input. Names,
attributes, escaping, inline/stacked markup and iCheck setup are unchanged.
Applications with a published/overridden `admin::form.radio` must update their
own template to receive this fix; no application-owned view is overwritten.

## Submission and validation boundaries

Radio has no hidden clearing input. An unchecked group is omitted from native
successful controls, so an untouched blank create stores NULL in the nullable
fixture and an untouched NULL edit retains NULL. Unchecking an existing choice
also omits the field and preserves its existing database value on update; it is
not a new clearing API. A present empty value still becomes null through normal
Laravel middleware and existing form persistence. After failed validation,
explicit old null can render unchecked over a stored value/default, but a later
untouched save omits that field and preserves storage.

`required()` sets HTML required attributes. Native constraint validation rejects
an unchecked required group and accepts a selected zero. Existing server field
validation skips absent columns, even when configured with `rules('required')`;
this patch does not change that contract. The tests separately confirm that a
present null fails the configured server required rule. Applications needing
server-side presence enforcement must enforce it in their own request rules.

## Regression coverage

`NullableRadioTest.php` covers both layouts, null/zero/one, native boolean casts,
legacy non-null comparison parity, default and closure-default behavior,
label-based checked fallback, old-input precedence, escaping, untouched
create/edit saves, explicit zero/one selections, omission versus explicit null,
required constraints, and validation redisplay followed by corrected saves.

`javascript/radio-null.cjs` runs production-rendered HTML with shipped jQuery
2.1.4 and iCheck in existing jsdom. It checks that initialization preserves
successful controls, compares native FormData to jQuery serialization, and
returns native validity plus actual checked inputs. PHP decodes this query and
posts through the real Laravel HTTP kernel and Form store/update into SQLite.
No new dependency is added.

Run the standard integration consumer on Laravel 12 and 13:

```sh
npm ci --ignore-scripts --prefix javascript
composer test -- --filter NullableRadioTest
```

This is offline DOM and in-process HTTP/SQLite coverage, not real-browser layout,
cookie/CSRF, live PJAX, nested HasMany identity, or relationship-widget coverage.
