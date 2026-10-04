# Callback-bearing widget option serialization

`Text::inputmask()` and the inherited Mobile/Decimal/Currency/Ip render paths use
`json_encode_options()`. `File::render()` also uses it, including Image's inherited
render path. Two different callbacks under the same nested leaf name previously
received the same `%name%` marker: the first callback could replace both values.
For example, an Inputmask `XY` mask with letter-only `X.validator` and digit-only
`Y.validator` incorrectly accepted `AB` and rejected `A1`.

The helper now allocates a distinct marker per callback occurrence across the
entire nested array. Literal string values and keys are reserved before markers
are chosen, so marker-like ordinary data stays data. After one native JSON encoding, replacement targets only the prepared array
value positions. Object/JsonSerializable leaves are skipped as complete JSON
values, so their marker-like strings and keys remain data. Untouched JSON is
copied verbatim; an inserted callback's own string literals are not processed again.
Callback detection only examines strings, so ordinary NULL, false and zero values
no longer pass through `strpos()`'s scalar coercion. In particular, the supported
Inputmask option `onBeforeMask => null` no longer emits a PHP deprecation.

## Existing contract and boundaries

- The public `prepare_options(array $options)` and
  `json_encode_options(array $options)` signatures are unchanged. The preparation
  result still has `original`, `toReplace`, and `options` entries. Generated
  markers are internal/opaque and are no longer derived from option key names.
- Only array value strings starting **exactly** with `function(` use the existing
  callback convention. Leading whitespace, `function ()`, named functions and
  arrow-function text remain quoted strings. Array keys are always data.
- This is a narrow correctness repair for existing developer-supplied callback
  options. It adds no callback syntax, JavaScript parser, evaluation API or widget caller.
  Callback bodies are retained as supplied; this helper is not a general
  JavaScript serializer or validator.
- Ordinary nested arrays/scalars retain native `json_encode()` ordering, types,
  sparse/list shape and default escaping. Encoding still happens once, with no
  flags or throwing-error mode added. A failed encoding retains the helper's
  historical empty-string result and native `json_last_error()` code; callers
  needing a different failure policy must handle it themselves.
- Callback traversal is still array-only. Ordinary object and `JsonSerializable`
  leaves retain their native JSON representation; their function-looking string
  values remain data. Each serializer is called only by the single native JSON
  encoding, not by a preliminary inspection pass. Previously, ordinary objects
  could fail the coercive `strpos()` check. Stringable objects are now JSON data,
  not implicit callback strings; pass an actual string to request the existing
  callback convention. Cyclic objects retain native JSON failure behavior;
  recursive PHP arrays/reference-rich structures remain outside the traversal
  contract and are not newly supported.
- DateMultiple remains on its separate **plain JSON** path, as do Date, range
  fields, MultipleFile/MultipleImage and grid presenters. This patch does not
  enable function strings on any of those paths.

Only the PHP helper changes in production. No asset or published-view update is
required, and no dependency constraints change. Applications relying on the old
wrong callback duplication or on internal `%key%` markers must remove that
assumption. File transfer, validation, persistence and custom widget APIs are
otherwise unchanged.

## Regressions

Run the dependency-free helper matrix:

```sh
php -d error_reporting=-1 tests/compatibility/json_encode_options.php
```

It checks repeated string/numeric leaf keys, duplicate callback bodies, old and
new marker-like values/keys, callbacks containing marker-like literals,
independent calls, the public preparation shape, ordinary NULL/bool/zero/Unicode
and escaped strings, exact prefix recognition, invalid UTF-8 and non-finite
number/cyclic-object error parity, object/JsonSerializable literal preservation,
single serializer invocation, long quoted/escaped literals and mixed structural
shapes. The JSON position walker has no regular-expression engine limits. All production PHP diagnostics fail the check.

With the [integration prerequisites](README.md), run:

```sh
cd tests/integration
composer test -- WidgetOptionSerializationTest.php
```

The dedicated suite renders actual Text/Mobile fields under strict diagnostic
handling and runs their emitted scripts with the shipped Inputmask 3.2.8-36 in
offline jsdom, using both shipped jQuery 2.1.4 and jQuery 3.7.1. It verifies the
actual X/Y callbacks, valid/invalid/repeated setvalue behavior, native FormData,
ordinary nullable/scalar options and a direct same-widget JavaScript control.
The File test renders the real field and captures its fileinput initializer
argument, checking distinct `ajaxSettings.success` and
`ajaxDeleteSettings.success` callbacks, literal/scalar data and generated defaults.
It does **not** execute the File plugin or any upload/delete network request.

This establishes offline initialization/configuration and Inputmask setvalue
behavior, not physical keystrokes, native-browser layout, PJAX/transport/CSRF,
server-side validation/persistence, every Inputmask/File option or universal
application compatibility. Existing full integration and BrowserKit suites remain
separate checks; neither turns this test into live-browser E2E coverage.
