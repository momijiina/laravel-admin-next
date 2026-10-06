'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM(input.html, { url: input.url });
try {
    const { window } = dom;
    const forms = window.document.querySelectorAll('form');
    assert.equal(forms.length, 1, 'Use one actual production filter form.');
    const form = forms[0];
    assert.equal(form.method, 'get');
    const controls = Array.from(form.querySelectorAll('input[type="text"], select[multiple]'));
    assert.deepEqual(controls.map(control => control.name), Object.keys(input.values));
    for (const control of controls) {
        const value = input.values[control.name];
        if (Array.isArray(value)) {
            assert.equal(control.tagName, 'SELECT');
            assert.equal(control.multiple, true);
            for (const selected of value) {
                assert.ok(Array.from(control.options).some(option => option.value === selected), selected);
            }
            for (const option of control.options) {
                option.selected = value.includes(option.value);
            }
        } else {
            assert.equal(control.tagName, 'INPUT');
            assert.equal(typeof value, 'string');
            control.value = value;
        }
    }
    const entries = Array.from(new window.FormData(form).entries());
    const target = new window.URL(form.action);
    target.search = new window.URLSearchParams(entries).toString();
    process.stdout.write(JSON.stringify({
        names: controls.map(control => control.name), entries, uri: target.href,
    }));
} finally {
    dom.window.close();
}
