'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM(input.html, { url: input.url });
const w = dom.window;
try {
    const form = w.document.querySelector('form[pjax-container]');
    assert.ok(form);
    assert.equal(form.method, 'get');
    const field = form.elements.namedItem('code');
    assert.ok(field);
    const capture = () => {
        const entries = Array.from(new w.FormData(form).entries());
        const url = new w.URL(form.action);
        url.search = new w.URLSearchParams(entries).toString();
        return { entries, uri: url.href };
    };
    const initial = capture();
    field.value = '';
    const cleared = capture();
    field.value = '0';
    const reselected = capture();
    const reset = form.querySelector('a.column-filter-all').href;
    process.stdout.write(JSON.stringify({ initial, cleared, reselected, reset }));
} finally {
    w.close();
}
