# Grid QuickCreate validation retries

## Behavior and compatibility

Ordinary Grid QuickCreate uses Bootstrap's submit-button loading state while
its AJAX request is pending. `Form::store()` returns HTTP 200 with `status: false`
and validation details when the submitted fields fail validation. The button
previously stayed disabled after this response, so correcting a field and
clicking Submit could not send another request. Cancel/reopen did not recover it.

An unsuccessful JSON success callback now resets the originating form's submit
button. Its label and enabled state are restored, and the entered values remain
available for correction and resubmission. The same reset applies to a consumer
response with a false status but no validation details; the existing no-notice
behavior for that response remains unchanged.

The existing successful-response early return still leaves the button loading
until `$.admin.reload()` replaces the form. It is not reset on that path, so a
native click on the disabled button does not duplicate a successful save while
navigation is pending. The HTTP-error callback's existing reset is unchanged.
This does not introduce a new synchronous double-click or keyboard-submit guard.

No backend validation, response shape, notification, field serialization,
escaping, keyboard handling, or persistence contract changes. Only PHP-emitted
JavaScript changes; no asset publication or view refresh is needed. A downstream
QuickCreate subclass that replaces `script()` needs its own equivalent update.

## Regression coverage

`GridQuickCreateTest.php` renders two real Grids with the same resource and two
existing SQLite rows. It executes all their emitted ready scripts in offline
jsdom with shipped Bootstrap/iCheck and both shipped jQuery 2.1.4 and modern
jQuery 3.7.1. Native clicks open, submit, cancel, reopen, repeat an unsuccessful
attempt, correct the value, and submit again. Bootstrap's own asynchronous
loading/reset behavior runs without replacing its button plugin.

The cases check ordinary `Form::store()` validation failure, a fixture HTTP 200
rejection without validation details, a fixture HTTP 500 response, and direct
success. One form remains pending throughout the other's retries to verify that
reset targets only the originating form. The tests check exact label restoration,
retained input, unchanged warning/error/no-notice semantics, success reloads,
and disabled native-click behavior before and after success.

Callback bodies come from real in-process HTTP requests. The AJAX transport is
intercepted; every actual serialized DOM request is then replayed through web
middleware and the HTTP kernel in response-completion order. Each replay must
produce the exact body delivered to the JavaScript callback. SQLite assertions
verify that failures insert nothing, both successful payloads persist exactly,
and both existing rows remain unchanged.

Run `composer test -- --filter GridQuickCreateTest` from `tests/integration`
after the normal PHP and JavaScript dependency setup in the [README](README.md).

## Limits

This is offline DOM plus in-process HTTP/SQLite coverage. It does not exercise
a live browser, real AJAX/PJAX transport, layout, cookie/CSRF enforcement,
keyboard input, arbitrary QuickCreate field widgets, distinct-resource grids,
or consumer script/view overrides. The two grids intentionally share a resource;
this change does not redesign QuickCreate's existing global open/cancel selectors
or handler registration. The HTTP 500 and non-validation rejection bodies are
explicit fixture responses, not a simulated network outage or a new server API.
