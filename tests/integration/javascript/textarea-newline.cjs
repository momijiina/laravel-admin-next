'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const { html } = JSON.parse(fs.readFileSync(0, 'utf8'));
// Parse the actual Blade output. Never compensate for or rewrite its content.
const dom = new JSDOM(html, { runScripts: 'outside-only' });
const { window } = dom;
window.eval(fs.readFileSync(path.join(__dirname, '../../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
const input = window.document.querySelector('textarea[name="body"]');
const form = input.closest('form');
process.stdout.write(JSON.stringify({
    count: window.document.querySelectorAll('textarea').length,
    escapedElement: window.document.querySelector('#escaped') !== null,
    value: input.value,
    defaultValue: input.defaultValue,
    native: Object.fromEntries(new window.FormData(form)),
    jquery: window.jQuery(form).serialize(),
    attributes: {
        name: input.name,
        rows: input.getAttribute('rows'),
        placeholder: input.getAttribute('placeholder'),
        purpose: input.getAttribute('data-purpose'),
    },
}));
window.close();
