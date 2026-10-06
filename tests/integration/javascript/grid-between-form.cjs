'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM(input.html, { url: input.url });
try {
    const { window } = dom;
    const form = window.document.querySelector('form');
    assert.ok(form, 'Use the production filter form.');
    assert.equal(form.method, 'get');
    const controls = Array.from(form.querySelectorAll('input[type="text"]'));
    assert.equal(controls.length, 2);
    assert.equal(input.bounds.length, controls.length);
    controls.forEach((control, index) => { control.value = input.bounds[index]; });
    const entries = Array.from(new window.FormData(form).entries());
    const target = new window.URL(form.action);
    target.search = new window.URLSearchParams(entries).toString();
    process.stdout.write(JSON.stringify({
        names: controls.map(control => control.name), entries, uri: target.href,
    }));
} finally {
    dom.window.close();
}
