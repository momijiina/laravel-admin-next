const fs = require('node:fs');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const jquery = fs.readFileSync(input.jquery === 'modern' ? require.resolve('jquery') : input.jquery, 'utf8');
const widget = fs.readFileSync(input.widget, 'utf8');
const results = input.fixtures.map(fixture => {
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only'});
    const window = dom.window;
    window.eval(jquery);
    window.eval(widget);
    const $ = window.jQuery;
    const original = window.document.querySelector('input[name=quantity]');
    const initial = original.value;
    window.eval(fixture.script);
    const field = window.document.querySelector('input[name=quantity]');
    const form = field.form;
    assert.notEqual(field, original, 'exercise the actual widget clone');
    assert.equal(field.value, initial, 'initialization must preserve integer text');
    assert.equal(field.type, 'text');
    assert.equal(form.querySelectorAll('input[name=quantity]').length, 1);
    assert.equal(form.querySelectorAll('.input-group button').length, 2);
    let changes = 0;
    $(field).on('change.numberRegression', () => changes++);
    if (fixture.typed !== undefined) field.value = fixture.typed;
    for (const action of fixture.actions) {
        if (action === 'blur') {
            field.focus();
            form.querySelector('button.save').focus();
        } else if (action === 'keyup') {
            field.dispatchEvent(new window.KeyboardEvent('keyup', {key: '2', keyCode: 50, which: 50, bubbles: true}));
        } else if (action === 'up' || action === 'down') {
            const buttons = form.querySelectorAll('.input-group button');
            buttons[action === 'up' ? 1 : 0].click();
        } else {
            throw new Error('Unknown action: ' + action);
        }
    }
    const native = new window.URLSearchParams(new window.FormData(form)).toString();
    assert.equal($(form).serialize(), native, 'native FormData and jQuery serialization agree');
    const result = {initial, value: field.value, query: native, changes, readonly: field.readOnly, disabled: field.disabled};
    window.close();
    return result;
});
process.stdout.write(JSON.stringify(results));
