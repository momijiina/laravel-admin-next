# Slider double-range restoration

The bundled Ion.RangeSlider 1.8.2 submits a double selection as a semicolon pair,
for example `20;80`. The Slider initializer now restores both endpoints from that
format instead of passing the whole pair as a nonnumeric `data-from`. Previously,
reopening a saved selection and saving an unrelated field silently replaced the
selection with configured `from`/`to`, or the slider's minimum/maximum.

## Contract and compatibility

- Restoration applies only in effective `double` mode, including the shipped
  widget's `data-type` override of `options.type`.
- Exactly two canonical decimal integer strings are accepted, including `0` and
  negative integers. They must round-trip through JavaScript Number's string
  representation. Whitespace, leading zeros, `-0`, plus signs, fractions,
  alternate separators and extra endpoints keep the existing fallback behavior.
- The existing view resolves `old($column, $value)` before initialization, so
  failed-validation pairs take precedence over saved/default values. Explicit
  empty or NULL old input retains the existing configured-endpoint fallback.
- Numeric `data-to` continues to override a restored endpoint. An explicitly
  present nonnumeric `data-to` retains the widget's configured/default `to`
  fallback. The existing first `data-from` attribute and jQuery data precedence
  are retained.
- Field defaults (including closures), saved values, scalar double values,
  single mode, configured bounds and the shipped bound-clamping behavior remain.
  Re-running initialization on an active widget does not reset its live selection.

The fix is in the generated Slider initializer. An overridden Slider view must
continue to provide the effective field value through `data-from`; a custom
initializer must carry forward the restoration logic. Do not populate
`input.value` with the saved pair before this plugin initializes: version 1.8.2
reads that value as `min;max`, which changes the configured domain.

The stored/posted representation and bundled plugin are unchanged. This is not a
new decimal, arbitrary-precision or alternate-separator contract. The old plugin
still submits integer endpoints. Applications remain responsible for server-side
validation and an appropriate database column type.

## Regression coverage

`SliderRangeTest.php` renders real complete package Forms and the production
ready wrapper. `javascript/slider-ranges.cjs` loads the shipped Bootstrap,
iCheck and Ion.RangeSlider assets offline under both jQuery 2.1.4 and 3.7.1.
Native FormData must agree with jQuery serialization and both jQuery versions
must produce identical results.

The tests use real HTTP-kernel requests/default web middleware, `Form::store` and
`Form::update`, and disposable SQLite:

- Create using the widget's public update API, save its actual generated pair,
  reopen, change only the title, save again, and reopen again
- Persisted positive/zero/negative pairs, configured starts, bounds clamping,
  single/scalar controls and malformed input fallback
- Literal/closure defaults, model precedence, explicit empty/NULL old input,
  real failed validation and a corrected retry
- Effective `data-type`, numeric/zero/nonnumeric `data-to` (including false/NULL), existing `data-from`
  precedence, another independent Slider, and repeated initialization

Run with the integration setup in [README.md](README.md), then:

```sh
composer test -- --filter SliderRangeTest
```

This is offline DOM and in-process HTTP/SQLite coverage. It does not cover pointer
geometry, live browser/PJAX transport, cookies/CSRF, nested collection identities,
non-SQLite databases, or untested third-party Slider overrides.
