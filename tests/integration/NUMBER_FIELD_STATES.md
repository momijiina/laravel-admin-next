# Number readonly and disabled widget states

The ordinary `number()` field's shipped bootstrapNumber widget now honors the
input's live `readOnly` and `disabled` properties. Previously, clicking +/- on a
readonly value of `42` could change it to `43` or `41` and persist that change.
Focusing a readonly value of `42` with `max(10)` and then clicking Save could
normalize it to `10` on blur. Disabled input was omitted by native form submission,
but its +/- buttons could still change the displayed value.

## Contract

- While readonly or disabled, +/- handlers do not change the value, move focus
  to the input or emit a widget `change` event. Keydown, keyup and blur handlers
  also return without filtering, parsing, normalization or widget change events.
  Merely focusing and leaving an out-of-range readonly value preserves it.
- HTML Boolean attribute presence determines the native state. Attributes such
  as `readonly="false"` and `disabled="0"` still lock the input. To unlock it,
  remove the attribute or set the live input property to `false`; passing false
  to the field's generic PHP `attribute()` method does not remove an attribute.
- Inherited disabled fieldsets are honored. The first direct-child legend's
  descendants are exempt from that fieldset only; another disabled ancestor may
  still disable the input. Each event reads the current input and fieldset
  properties, so programmatic lock/unlock after initialization takes effect
  immediately without a refresh API or MutationObserver.
- Readonly values remain successful native form controls. Disabled values are
  omitted by native FormData and retain their prior database value through the
  normal omitted-field update path. No hidden replacement field is added.
- Enabled integer arithmetic, unit steps, parsing, bounds caching and event
  behavior remain as documented in [the integer guide](NUMBER_INTEGERS.md).
  The source remains ES5-compatible and retains its imported Apache 2.0 license.

## Upgrade and limits

Refresh the application's published
`vendor/laravel-admin/number-input/bootstrap-number-input.js` and any
cached/minified copy. `php artisan vendor:publish --tag=laravel-admin-assets --force`
republishes all admin assets; back up and reconcile customized published files
before using it. Overridden or replacement widgets require their own review.

The +/- buttons keep their existing appearance and native focusability; their
handlers become inert while the input is locked. This intentionally avoids
cached button disabling that would prevent an input unlocked by consumer code
from working immediately. No new styling, public event, refresh API or dynamic
state observer is introduced. Update the displayed cloned input, not a detached
reference to the original element replaced during initialization.

The shipped jQuery 2.1.4 `serialize()` does not recognize an input disabled only
by its ancestor fieldset, although native FormData does. jQuery 3.7.1 follows
native omission here. This pre-existing serialization difference is not repaired
by the widget guard; consumers using inherited fieldset disabling with older
jQuery must account for it in their submission path. Direct input `disabled`
and readonly behavior agree between the tested jQuery versions and FormData.

These are UI behavior guarantees, not server validation, authorization or
concurrency protection. Applications must retain their own rules and permissions.
The fix does not alter other widgets, collection disabling, embedded replacement
semantics, or HasMany identities.

## Verification

`NumberFieldStateTest.php` renders the actual Number field and emitted initializer
through Laravel's HTTP kernel. `javascript/number-field-states.cjs` executes that
initializer and the shipped widget in offline jsdom against shipped jQuery 2.1.4
and modern jQuery 3.7.1. Native FormData is passed to actual `Form::update()`
requests and checked against SQLite persistence.

The matrix covers readonly and disabled input, present Boolean attributes,
out-of-range focus/blur and key events, +/- buttons, repeated events, live
lock/unlock, disabled ancestor fieldsets, first/second legends and nested
fieldset exemptions. Programmatic event dispatch tests the widget handlers even
when native disabled controls would suppress a physical event. Enabled controls
and the existing `NumberIntegerTest` preserve the integer/legacy parsing contract.

Run with the [isolated integration harness](README.md):

```sh
vendor/bin/phpunit --filter 'Number(FieldState|Integer)Test'
```

This is offline DOM plus in-process HTTP/SQLite coverage. It is not native-browser
layout, accessibility, physical keyboard, live PJAX, transport/CSRF, custom-widget
or non-SQLite verification. Node's test runtime is not the production browser
baseline.
