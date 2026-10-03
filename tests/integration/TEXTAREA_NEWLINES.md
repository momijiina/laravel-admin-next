# Ordinary Textarea newlines

The ordinary `admin::form.textarea` view includes one literal LF immediately
after its opening tag. The HTML parser consumes that sacrificial LF, preserving
all leading LFs in the escaped field value. Keep the value interpolation directly
after this newline, without indentation. The field's `old($column, $value)`
selection and escaping are unchanged.

`TextareaNewlineTest` parses real rendered Blade output with the existing jsdom
dependency and submits native `FormData` entries and shipped jQuery 2.1.4
`serialize()` output through actual HTTP PUT requests to the package Form update
path, then checks freshly read SQLite text. It covers one/two leading LFs,
newline-only values, internal/trailing LFs, empty strings, zero, spaces, Unicode,
and escaped markup. Separate controls check value/default/custom formatting,
flashed old input (including explicit empty and zero), field attributes, existing
array rendering, and a consumer subclass explicitly using this view.

## Boundaries

- The fixture explicitly disables Laravel `TrimStrings` and
  `ConvertEmptyStringsToNull` to isolate the renderer. Applications retain their
  middleware policy: unchanged saves can still trim whitespace or convert empty
  strings to NULL when those middleware are enabled. This fix changes neither.
- Native textarea DOM values and `FormData` entries use LF. The HTML parser
  normalizes input CR and CRLF to LF; an explicit control verifies that behavior.
  This fix does not promise byte-preserving CR/CRLF source representation.
- Shipped jQuery `serialize()` normalizes LF to CRLF. The HTTP/SQLite assertion
  expects that CRLF result separately. Native browser form wire encoding can
  likewise normalize line endings; direct FormData-entry submission here is not
  a live-browser wire-encoding test. There is no new server newline conversion.
- The runner uses offline jsdom, not a live browser, CSRF/cookie transport or
  non-SQLite database coverage. It does not load external assets.
- Editor and action-form textarea use separate views. No built-in field currently
  extends the ordinary Textarea class. Their distinct views, arbitrary custom
  views, JSON auto-parsing, and third-party editors are outside this fix.

Run using the normal integration setup in [README](README.md), optionally with
`vendor/bin/phpunit --filter TextareaNewlineTest`. No new dependencies are needed.
