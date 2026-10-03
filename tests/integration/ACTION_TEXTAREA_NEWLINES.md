# Action-form textarea leading newlines

The action-specific `admin::actions.form.textarea` view now emits exactly one
literal LF immediately after its opening `<textarea>` tag. HTML parsing discards
that sacrificial LF instead of the first LF in the field's value. The escaped
`old($column, $value)` expression remains unindented: indentation would become
field data, and adding a second LF would insert an unwanted blank line.

This is separate from the ordinary `admin::form.textarea` view. Actions use the
shared Textarea PHP field but select an action-specific view; fixing one template
does not fix the other. No field precedence, escaping, middleware, PHP conversion
or submission implementation is changed.

## Regression scope

`ActionTextareaLeadingNewlineTest` boots real Laravel with Testbench and invokes
an actual consumer `Action::render()` and its modal interactor. The existing locked
jsdom harness parses the resulting HTML, loads shipped jQuery 2.1.4 and Bootstrap,
and runs the exact emitted script. It clicks the action, submits the modal,
hides/reopens it, and submits again. Only AJAX is intercepted, at the outgoing
native `FormData`; no network request is performed. The modal `.in` class is an
activation assertion, not a claim about visual layout.

The 37 fixtures cover explicit values, defaults, and flashed old input (including
empty and zero overriding distinct fallbacks), one/two/only leading LFs, CRLF/CR,
internal/trailing newlines, spaces, Unicode, escaped closing tags/markup,
attributes, and the shared array-to-pretty-JSON conversion. DOM value,
defaultValue, first submission and reopened submission must match normalized
input. Existing Blade escaping/entity behavior is retained.

Run using the [integration harness](README.md):

```sh
vendor/bin/phpunit --filter ActionTextareaLeadingNewlineTest
```

## Boundaries and upgrade cautions

HTML parsing normalizes CRLF and CR to LF before textarea processing. This fix
preserves leading blank lines, not byte-exact source line endings. Native
FormData entries are checked before multipart wire encoding; browser wire line
ending rules are a separate layer. Action submissions use native FormData,
not the ordinary form's jQuery serialization.

Laravel `TrimStrings` can independently strip leading/trailing whitespace;
`ConvertEmptyStringsToNull` can convert an empty submission. Consumers requiring
whitespace preservation must configure their middleware separately. This test
does not change middleware policy or claim HTTP dispatch/database persistence,
live browser/PJAX, CSRF/cookies, RowAction/BatchAction runtime coverage, external
editors, or custom templates.

Consumers with a published/overridden action textarea view must apply the same
single-LF change there. Avoid adding an extra workaround LF to stored values or
indenting the interpolation. Custom views/editors have their own rendering rules.
