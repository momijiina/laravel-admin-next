# Ordinary Switch localized labels

The ordinary Switch field now serializes its label, color and size strings into
JavaScript literals. An ordinary apostrophe such as `C'est actif` previously
broke the complete ready handler. A native checkbox click then changed the
checkbox while leaving its hidden submitted state unchanged, so saving could
silently retain the old value. Literal backslashes and line breaks also failed
to reach the widget unchanged.

## Contract and upgrade cautions

- `states()` label text reaches the bundled bootstrap-switch as its original
  string, including quotes, backslashes, line breaks and localized Unicode text.
- Scalar option values retain the existing PHP string conversion. Numeric labels
  remain strings; `true` becomes `'1'`, while `false` and NULL become `''`.
  This does not introduce typed options or function-valued labels.
- The plugin still treats labels as HTML. Ordinary markup such as `<b>Oui</b>`
  keeps its existing rendering; this change is not a label-sanitization feature.
- Supported sizes/colors, defaults, old-input precedence, state-to-value mapping,
  the hidden `on`/`off` submission contract and the change callback are unchanged.
  Custom state values are still applied by `SwitchField::prepare()` on save.
- Existing readonly/disabled plugin behavior and hidden-control submission remain.
  In particular, disabling the visible checkbox does not disable its separate
  hidden input. Continue to enforce authorization and validation on the server.

No bundled asset or Blade view changes are required. Consumers overriding
`SwitchField::render()` or replacing its generated initializer should carry
forward the literal serialization. Remove manual JavaScript quote/backslash
escaping from configured label strings: labels are now literal data, so previous
workaround backslashes can appear in the label. Recheck custom HTML labels against
the plugin's existing HTML parsing behavior. This fix does not repair values
already saved incorrectly.

## Regression coverage

`SwitchLabelsTest.php` renders complete real package Forms and the production
ready wrapper. `javascript/switch-labels.cjs` executes the shipped Bootstrap,
iCheck and bootstrap-switch assets offline with both shipped jQuery 2.1.4 and
jQuery 3.7.1. Both versions must agree, and native FormData must match jQuery
serialization. Scripts before/after the Switch initializer must execute.

Real HTTP-kernel requests, `Form::store()`/`Form::update()` and disposable SQLite
cover:

- Native checkbox and actual plugin-handle clicks, create, reopen, untouched
  update, toggle off, save and reopen again
- Ordinary quotes, literal backslashes, LF/CR/CRLF, tabs, Unicode separators and
  localized text, plus existing HTML labels
- Scalar label string conversion, supported sizes/colors, custom state mappings,
  literal/closure defaults and independent fields
- Failed create and update validation, old-input redisplay and corrected retries
- Repeated full ready initialization preserving live state and exactly one hidden
  change event per state transition; existing readonly/disabled plugin controls

Run with the integration setup in [README.md](README.md), then:

```sh
composer test -- --filter SwitchLabelsTest
```

This is offline DOM and in-process HTTP/SQLite coverage. It does not cover pointer
geometry, live browser/PJAX transport, cookie/CSRF behavior, nested collection
identities, non-SQLite storage, malformed encodings, arbitrary non-scalar label
objects, or third-party Switch overrides.
