'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM(input.html, {url: input.url});

try {
    const {window} = dom;
    const form = window.document.querySelector('form[pjax-container]');
    assert.equal(form.method, 'get');
    form.querySelector('input.grid-quick-search').value = input.query;
    const entries = Array.from(new window.FormData(form).entries());
    const url = new window.URL(form.action);
    // Native GET replaces the action query. Unrelated action parameters are
    // verified separately; this does not simulate PJAX state preservation.
    url.search = new window.URLSearchParams(entries).toString();
    process.stdout.write(JSON.stringify({entries, uri: url.href}));
} finally {
    dom.window.close();
}
