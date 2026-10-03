# Nullable action Select choices

Action modal Select fields use `actions/form/select.blade.php`, independently of
ordinary model forms. An unset effective value, a NULL default, or explicit NULL
old input must leave the existing blank option selected. Previously PHP's loose
comparison marked numeric option `0` selected for NULL, so opening and submitting
an otherwise untouched action sent `choice=0`.

The template now resolves `old($column, $value)` once and excludes NULL from option
matching. Non-NULL comparisons remain loose for compatibility: integer and string
zero are valid choices, as are existing boolean/numeric-string matches. Old input
still overrides resolved value/default, including old NULL and empty strings.
The shared field API's `value(NULL)` is a getter, not a way to override a default;
`default(1)->value(NULL)` therefore continues to use default 1.

The existing leading blank option remains essential: without it a browser selects
the first real option even if no option has an explicit `selected` attribute.
Explicit empty options, Select2 placeholder configuration, option order and custom
attributes are preserved. Action Select historically renders flat options only;
`groups()` remains ignored here, unlike ordinary form Select. This change does not
add grouped action choices or change Radio, MultipleSelect, validation, action
handlers, or persistence middleware.

## Upgrade cautions

- Actions that unintentionally relied on NULL silently becoming zero should set an
  explicit `default(0)` or `value(0)` when zero is the intended initial selection.
- An untouched nullable action now submits an empty string from its blank option.
  Application middleware may normalize that to NULL; this regression verifies the
  native request payload, not application-specific normalization or persistence.
- Published/custom overrides of `admin::actions.form.select` need the same NULL
  guard and must keep a leading blank option. Updating only the ordinary form
  Select view does not fix action modals.

## Regression coverage

`ActionSelectNullTest` calls actual `Action::render()`, collects its modal and
emitted script, and runs shipped jQuery, Bootstrap and Select2 in offline jsdom.
It observes selection before and after initialization, intercepts the emitted AJAX
handler's native `FormData`, then cancels/reopens and submits again. Cases cover
unset/default/old NULL; integer/string zero and one; booleans; empty/unmatched
values; precedence; numeric-string loose matching; explicit empty options; empty
options; and existing ignored-group behavior. No network request is made.

Run using the integration suite's normal Composer/Node setup:

```sh
cd tests/integration
vendor/bin/phpunit -c phpunit.xml --filter ActionSelectNullTest
```

The offline DOM test does not claim full graphical-browser coverage, Select2 remote
loading, or application endpoint/database behavior.
