# Remote Select validation retries

Ordinary `select('choice')->options('/options')` and
`multipleSelect('choice')->options('/options')` previously initialized their remote
choices from the stored/default value after a validation failure. Changing or
clearing a saved choice, failing an unrelated required field, and submitting the
corrected form could silently restore and save the original choice.

The URL-options initializer now uses the field's flashed old input when that key
is present, including explicit NULL, an empty selection, integer/string zero,
and flat arrays of IDs. A missing old-input key still uses the existing
stored/default selection. NULL hidden markers and invalid nested members are
not serialized as IDs. This is presentation handling, not validation or
permission to accept malformed input; applications must retain their rules.

## Compatibility and upgrade cautions

- The existing `data-value` attribute retains its stored/default, comma-separated
  contract. A separate escaped `data-remote-value` attribute is emitted only for
  URL-options fields with old input. Its presence matters even when it is empty.
  This metadata is internal to the shipped remote initializer.
- Without old input, explicit defaults (including closure defaults), remote
  `selected: true` options, Select2 `config('data', ...)` overrides, and explicit
  AJAX URL/options retain their existing behavior. On a validation retry, the
  attempted choice or explicit clear takes precedence over those initial
  selected-option fallbacks. This is the intentional behavior change.
- Ordinary options, callback/model preload arguments, `ajax()` search preload,
  Listbox, Checkbox and Timezone retain their existing contracts. There is no new
  public or protected API. Subclasses replacing `loadRemoteOptions()` without
  calling its parent are unchanged and must implement any retry behavior in
  their own initializer.
- Dependent `load()`/`loads()` implementations and their request sequencing are
  unchanged. In particular, `load()` still reads its target's stored/default
  `data-value`; this fix does not solve dependent-target validation retries or
  stale/dependent AJAX races.
- Custom views must keep rendering the field's normal attributes, and custom
  remote initialization scripts must account for old-input presence to obtain
  this behavior. No Blade view or published asset is changed or overwritten.
- Remote responses still need to contain the selected IDs. This does not add
  fetch-by-ID, paging, request cancellation, new ID encodings, or restoration of
  unavailable choices. The legacy comma-separated ID format is unchanged; IDs
  containing commas are outside this regression's scope.
- Laravel's empty-string middleware, validation, raw hooks, model casts and
  persistence rules remain authoritative. No stored data is migrated.

## Regression coverage

`RemoteSelectOldInputTest.php` runs real Form rendering, web-session validation
redirects, store/update and SQLite persistence. Offline jsdom executes the full
production Admin ready wrapper, shipped Select2, Bootstrap and iCheck with both
shipped jQuery 2.1.4 and jQuery 3.7.1. Only AJAX transport is replaced with deferred
responses obtained from real in-process HTTP option routes. Dropdown selections
and clear-button clicks produce native FormData, which is used for the failed
submission and corrected retry.

Coverage includes create/edit, unchanged stored values, scalar/closure defaults,
NULL and empty-array markers, numeric and string IDs, zero, HTML-sensitive IDs,
absent versus explicit old input, malformed nested-input redisplay, configured
and server-selected options, explicit AJAX options, dependent-load metadata,
ordinary/inherited controls, dotted columns and stale-marker removal on a repeat
render. The dotted-column test is not HasMany row-identity coverage.

Run with the standard integration consumer on each framework family:

```sh
composer test -- --filter RemoteSelectOldInputTest
```

Testbench bypasses CSRF and uses disposable SQLite. Live browser layout, keyboard
accessibility, real network/PJAX transport, arbitrary application overrides,
relationship modal widgets, HasMany validation identity, action forms and
non-SQLite persistence are not covered. No dependency changes are required.
