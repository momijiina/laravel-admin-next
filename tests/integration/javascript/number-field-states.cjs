const fs = require('node:fs');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const jquery = fs.readFileSync(input.jquery === 'modern' ? require.resolve('jquery') : input.jquery, 'utf8');
const widget = fs.readFileSync(input.widget, 'utf8');
let assertions = 0;
let jqueryVersion;
function equal(actual, expected, message) {
    assert.deepEqual(actual, expected, message);
    assertions++;
}
const fixtures = input.fixtures.map(fixture => {
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only'});
    const window = dom.window;
    window.eval(jquery);
    window.eval(widget);
    const $ = window.jQuery;
    jqueryVersion = $.fn.jquery;
    const original = window.document.querySelector('input[name=quantity]');
    equal(original.value, fixture.before, fixture.label + ': real Number::render value');
    window.eval(fixture.script);
    const field = window.document.querySelector('input[name=quantity]');
    const form = field.form;
    const buttons = [...form.querySelectorAll('.input-group button')];
    equal(field === original, false, fixture.label + ': exercise the shipped widget clone');
    equal(field.type, 'text', fixture.label + ': clone input type');
    equal(form.querySelectorAll('input[name=quantity]').length, 1, fixture.label + ': one successful-control candidate');
    equal(buttons.length, 2, fixture.label + ': generated step buttons');
    let changes = 0;
    $(field).on('change.numberStateRegression', () => changes++);
    let expectedState = {...fixture.state};
    const snapshots = [];

    function snapshot(action, value) {
        const label = fixture.label + ': ' + action;
        equal(field.value, value, label + ': exact value');
        equal(field.readOnly, expectedState.readonly, label + ': live readonly');
        equal(field.disabled, expectedState.disabled, label + ': own disabled');
        equal(buttons.map(button => button.disabled), [false, false], label + ': no stale button disabled property');
        equal(buttons.map(button => button.type), ['button', 'button'], label + ': step buttons do not submit');
        const native = new window.URLSearchParams(new window.FormData(form));
        equal(native.has('quantity'), !expectedState.effectiveDisabled, label + ': native successful-control presence');
        if (!expectedState.effectiveDisabled) {
            equal(native.get('quantity'), value, label + ': native submitted value');
        }
        equal(native.get('name'), 'after', label + ': enabled sibling remains submitted');
        const serialized = $(form).serialize();
        if ($.fn.jquery === '2.1.4' && expectedState.effectiveDisabled && !field.disabled) {
            // Shipped jQuery 2 checks the input's own disabled property only.
            // Keep this existing serializer limitation separate from widget state.
            const legacy = new window.URLSearchParams(serialized);
            equal(legacy.get('quantity'), value, label + ': known jQuery 2 fieldset serialization limitation');
            equal(legacy.get('name'), 'after', label + ': legacy enabled sibling');
        } else {
            equal(serialized, native.toString(), label + ': jQuery/native serialization parity');
        }
        snapshots.push({action, value, query: native.toString(), effectiveDisabled: expectedState.effectiveDisabled});
    }

    function key(type) {
        return new window.KeyboardEvent(type, {key: 'a', keyCode: 65, which: 65, bubbles: true, cancelable: true});
    }

    function blockedEvents(value) {
        equal(expectedState.readonly || expectedState.effectiveDisabled, true, fixture.label + ': locked test precondition');
        const probes = [
            ['focus/blur', () => { field.focus(); form.querySelector('.save').focus(); }],
            ['native down click', () => buttons[0].click()],
            ['native up click', () => buttons[1].click()],
            ['synthetic down handler', () => $(buttons[0]).triggerHandler('click')],
            ['synthetic up handler', () => $(buttons[1]).triggerHandler('click')],
            ['native keyup', () => field.dispatchEvent(key('keyup'))],
            ['synthetic keyup handler', () => $(field).triggerHandler('keyup')],
            ['synthetic blur handler', () => $(field).triggerHandler('blur')],
            ['native keydown', () => {
                const event = key('keydown');
                field.dispatchEvent(event);
                equal(event.defaultPrevented, false, fixture.label + ': locked keydown is a no-op');
            }],
            ['synthetic keydown handler', () => {
                const event = $.Event('keydown', {keyCode: 65, which: 65});
                $(field).triggerHandler(event);
                equal(event.isDefaultPrevented(), false, fixture.label + ': locked synthetic keydown is a no-op');
            }],
        ];
        for (const [name, probe] of probes) {
            const beforeChanges = changes;
            probe();
            equal(field.value, value, fixture.label + ': ' + name + ' preserves out-of-range/exact text');
            equal(changes, beforeChanges, fixture.label + ': ' + name + ' emits no change');
        }
        if (!expectedState.effectiveDisabled) {
            // Readonly fields keep their existing focusable buttons; the handlers are no-ops.
            for (const button of buttons) {
                button.focus();
                equal(window.document.activeElement, button, fixture.label + ': readonly button stays focusable');
            }
        }
    }

    snapshot('initialized', fixture.before);
    for (const step of fixture.steps) {
        const beforeChanges = changes;
        if (step.action === 'blocked-events') {
            blockedEvents(step.value);
        } else if (step.action === 'up' || step.action === 'down') {
            buttons[step.action === 'up' ? 1 : 0].click();
        } else if (step.action === 'keyup') {
            field.dispatchEvent(key('keyup'));
        } else if (step.action === 'keydown') {
            const event = key('keydown');
            field.dispatchEvent(event);
            equal(event.defaultPrevented, true, fixture.label + ': enabled invalid key remains filtered');
        } else if (step.action === 'blur') {
            field.focus();
            form.querySelector('.save').focus();
        } else if (/^(readonly|disabled)-(on|off)$/.test(step.action)) {
            const [property, state] = step.action.split('-');
            field[property === 'readonly' ? 'readOnly' : property] = state === 'on';
        } else if (/^(outer|inner)-(on|off)$/.test(step.action)) {
            const [ancestor, state] = step.action.split('-');
            form.querySelector('[data-state-fieldset="' + ancestor + '"]').disabled = state === 'on';
        } else {
            throw new Error('Unknown action: ' + step.action);
        }
        expectedState = {...expectedState, ...step.state};
        equal(changes - beforeChanges, ['up', 'down', 'keyup', 'blur'].includes(step.action) ? 1 : 0,
            fixture.label + ': ' + step.action + ' change count');
        snapshot(step.action, step.value);
    }
    // Replaying Number's emitted initializer must leave the same live widget in place.
    window.eval(fixture.script);
    equal(window.document.querySelector('input[name=quantity]'), field, fixture.label + ': initializer remains idempotent');
    equal(form.querySelectorAll('.input-group button').length, 2, fixture.label + ': no duplicate buttons');
    window.close();
    return snapshots;
});
process.stdout.write(JSON.stringify({jquery: jqueryVersion, assertions, fixtures}));
