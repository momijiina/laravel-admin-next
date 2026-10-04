# DateMultiple native options

`DateMultiple` uses the shipped flatpickr widget. Its options were previously
calculated in PHP but never passed to the initializer, so every field used
`Y-m-d`, Chinese (`zh`), multiple selection and the built-in Clear button.
A stored value such as `03/10/2026, 04/10/2026` with a requested `d/m/Y` format
could therefore be reinterpreted and saved as two `2026-01-01` dates.

## Contract and precedence

```php
$form->DateMultiple('dates')->format('d/m/Y')->options([
    'locale' => 'en',
    'conjunction' => ' | ',
    'minDate' => '03/10/2026',
    'maxDate' => '31/10/2026',
    'disable' => ['05/10/2026'],
]);
```

- `format()` takes **flatpickr tokens**, for example `Y-m-d` or `d/m/Y`.
  Date and Datetime use Moment tokens such as `YYYY-MM-DD`; those tokens are
  not translated for DateMultiple. The default remains `Y-m-d`.
- An explicit `options(['dateFormat' => ...])` overrides `format()` regardless
  of setter order. Repeated `options()` calls retain the existing merge behavior:
  later values replace earlier values for the same option key.
- Explicit `locale` overrides the existing `zh` default. `config('app.locale')`
  does not change this field's default; set `options(['locale' => ...])` when
  needed. The supplied assets support `en`/`default` and `zh`; load the appropriate
  flatpickr locale asset before initialization for other names. Laravel locale
  names and flatpickr locale names may differ.
- JSON-data options such as `conjunction`, `minDate`, `maxDate`, date-array/range
  `disable`/`enable`, `allowInput`, `altInput` and `altFormat` reach flatpickr.
  Values still need to satisfy that widget's option types and date format.
- `mode` and `plugins` remain managed by the field: multiple selection and the
  built-in Clear plugin are always retained, even if those options are supplied.
  Clear empties the selection and closes the calendar; the existing server
  preparation converts an empty string to NULL.

## Upgrade cautions and boundaries

Previously ignored settings now take effect. Review stored strings and configured
formats/conjunctions together before upgrading: this change does not migrate old
values, validate invalid dates or convert between representations. Bounds and
availability rules can remove now-disallowed initial selections. They are UI
restrictions, not server authorization or validation; keep application-side rules.

`dateFormat` affects the submitted/stored string. For a different display format
while retaining ISO storage, use `altInput => true` with `altFormat => 'd/m/Y'`,
leaving `dateFormat` as `Y-m-d`.

This is deliberately a **JSON-data-only** options bridge. Function-valued hooks,
parsers, formatters, predicate rules, plugin factories, PHP closures and DOM
objects are not supported by `options()`. Function-looking strings are serialized
as strings, never evaluated as JavaScript; passing one where flatpickr requires
a callback may prevent widget initialization. This does not reuse or extend the
legacy `json_encode_options()` helper and does not claim full flatpickr API
coverage. Applications needing executable customization must maintain their own
initializer/field integration. No new rejection/exception policy is added for
invalid or non-JSON input.

The production change is in PHP initialization only; shipped JavaScript/CSS assets
are unchanged and do not require republishing solely for this fix. Check custom
DateMultiple subclasses/initializers and published or overridden views separately.
Retain the matching flatpickr, locale and shortcut-buttons assets, and remove any
application workaround that duplicates initialization or overwrites these options.

## Regression coverage

`DateMultipleOptionsTest.php` obtains HTML and the actual field initializer through
Laravel's web HTTP kernel and executes the shipped flatpickr 4.6.13, shortcut-button
and locale assets in offline jsdom with shipped jQuery 2.1.4 and modern jQuery 3.7.1.
It exercises unchanged edits and actual calendar-day clicks, native FormData and
jQuery serialization, then real Form create/update paths with SQLite persistence.

The cases cover native format and explicit-option precedence in both setter
orders, repeated-option merging, custom conjunctions, min/max boundaries,
disabled/enabled dates, separate alternate display, JSON null/boolean/numeric/
array/object/string data, unchanged default/explicit locale, default ISO/multiple
behavior and actual Clear-button clicks. A function-looking conjunction remains
literal data, guarding against accidental callback-string evaluation.

Run the focused tests with the existing integration dependencies:

```sh
cd tests/integration
npm ci --ignore-scripts --prefix javascript
composer test -- --filter DateMultipleOptionsTest
```

This is offline widget execution plus in-process HTTP/SQLite coverage. It does not
establish real-browser layout, mobile native pickers, live PJAX, CSRF/cookie flows,
all locale assets, arbitrary widgets, unsupported function-valued options,
non-SQLite databases, or all framework/PHP versions admitted by Composer.
