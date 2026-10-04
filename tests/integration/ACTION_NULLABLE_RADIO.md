# Nullable action Radio values

Action Radio has its own `admin::actions.form.radio` view. Its option comparison
previously treated an effective NULL as zero, checking and submitting the zero
option even when the user made no choice. The action view now excludes NULL
from that comparison. Ordinary Radio's separate view is unchanged.

Numeric and string zero still select zero. All non-NULL values retain the existing
loose comparison; this deliberately includes false/zero, true/one and numeric
strings. Selection still uses `old($column, $value)`, preserving value/default and
old-input precedence, including old NULL over an explicit value/default.
Calling `value(null)` is the existing getter, not a new explicit NULL setter.

The existing additive `checked()` fallback compares option labels, not keys, and
is gated by the original `$value === null`. It therefore still applies with old
NULL or empty input when the field value is NULL. This fix does not redefine that
fallback or add a clearing sentinel. Apps overriding/publishing
`admin::actions.form.radio` must update their own template to receive the fix.

## Submission and validation boundaries

An unchecked radio group is omitted from native FormData. It does not send a
NULL or empty-string value and does not establish an API for clearing an existing
choice. Action handlers must define how missing input affects their own state.
HTML `required()` makes an unchecked group invalid; selected zero remains valid.
The regression checks native validity separately and deliberately invokes the
emitted JavaScript submit handler even for invalid fixtures to inspect FormData.
HTML validity is not a server-side presence guarantee: applications must enforce
required input in their action/request validation as appropriate. This test does
not dispatch actions through HTTP, test server validation or middleware input
normalization, or claim anything about database persistence.

## Regression coverage

`ActionNullableRadioTest.php` creates actual Action modal output, then runs the
emitted initialization/open/submit handlers with shipped jQuery, Bootstrap and
iCheck in offline jsdom. Its 36 fixtures cover unset/NULL, scalar and closure
defaults, explicit zero/one, old-input precedence, non-NULL loose comparisons,
label-based checked fallback, empty option keys, escaped values/labels and HTML
required constraints. Initial selections, post-iCheck selections, submitted
native FormData and hide/reopen/resubmission are checked independently. AJAX is
intercepted before network I/O. There are no new runtime or test dependencies.

Run the standard integration consumer on Laravel 12 and 13:

```sh
npm ci --ignore-scripts --prefix javascript
composer test -- --filter ActionNullableRadioTest
```

This is offline DOM coverage, not a live browser, transport encoding, action
security/authorization audit, middleware or persistence test. Action Select,
Checkbox and ordinary Radio are outside this change.
