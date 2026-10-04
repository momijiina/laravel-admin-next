'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {JSDOM, VirtualConsole} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const jquery = fs.readFileSync(input.jquery === 'modern' ? require.resolve('jquery') :
    path.join(input.assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');
const widget = fs.readFileSync(path.join(input.assets, 'AdminLTE/plugins/input-mask/jquery.inputmask.bundle.min.js'), 'utf8');
const logs = [];
const results = input.fixtures.map(fixture => {
    const console = new VirtualConsole();
    for (const event of ['jsdomError', 'error', 'warn']) {
        console.on(event, error => logs.push({event, message: String(error)}));
    }
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', virtualConsole: console});
    const window = dom.window;
    try {
        window.eval(jquery);
        window.eval(widget);
        window.eval(fixture.script);
        const element = window.document.querySelector('input[name="amount"]');
        assert.ok(element.inputmask, 'The emitted initializer must attach the shipped Inputmask');
        const initial = element.value;
        if (fixture.edit !== undefined) {
            element.focus();
            element.setSelectionRange(0, element.value.length);
            if (fixture.edit === '') {
                element.dispatchEvent(new window.KeyboardEvent('keydown', {
                    bubbles: true, cancelable: true, key: 'Backspace', keyCode: 8, which: 8,
                }));
            } else {
                const paste = new window.Event('paste', {bubbles: true, cancelable: true});
                Object.defineProperty(paste, 'clipboardData', {value: {getData: () => fixture.edit}});
                element.dispatchEvent(paste);
            }
            window.document.querySelector('button').focus();
        }
        const display = element.value;
        const unmasked = element.inputmask.unmaskedvalue();
        element.form.dispatchEvent(new window.Event('submit', {bubbles: true, cancelable: true}));
        return {
            initial, display, unmasked,
            native: Object.fromEntries(new window.FormData(element.form)),
            serialized: window.jQuery(element.form).serialize(),
            readonly: element.readOnly, disabled: element.disabled,
            options: {
                radixPoint: element.inputmask.opts.radixPoint,
                groupSeparator: element.inputmask.opts.groupSeparator,
                removeMaskOnSubmit: element.inputmask.opts.removeMaskOnSubmit,
            },
        };
    } finally {
        window.close();
    }
});
process.stdout.write(JSON.stringify({results, logs}));
