'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    const dom = new JSDOM(input.html, { url: input.url, runScripts: 'outside-only' });
    const w = dom.window;
    try {
        w.eval(fs.readFileSync(input.jquery === 'shipped'
            ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
        w.eval(fs.readFileSync(path.join(assets, 'AdminLTE/plugins/iCheck/icheck.min.js'), 'utf8'));
        const $ = w.jQuery;
        const scripts = w.document.createElement('div');
        scripts.innerHTML = input.scriptHtml;
        for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });
        const form = w.document.querySelector('form[pjax-container]');
        assert.ok(form);
        assert.equal(form.method, 'get');
        const fields = Array.from(form.querySelectorAll('input[type="checkbox"]'));
        assert.equal(fields.length, 3);
        for (const field of fields) {
            assert.equal(field.name, input.name + '[]');
            assert.ok(field.parentElement.classList.contains('icheckbox_minimal-blue'));
        }
        const capture = () => {
            const checked = fields.filter(field => field.checked).map(field => field.value);
            assert.deepEqual(fields.filter(field => field.parentElement.classList.contains('checked')).map(field => field.value), checked);
            const entries = Array.from(new w.FormData(form).entries());
            const url = new w.URL(form.action);
            url.search = new w.URLSearchParams(entries).toString();
            return { checked, entries, uri: url.href };
        };
        const initial = capture();
        $(fields).iCheck('uncheck');
        const cleared = capture();
        for (const field of fields.filter(field => ['0', 'alpha'].includes(field.value))) $(field).iCheck('check');
        const chosen = capture();
        process.stdout.write(JSON.stringify({ initial, cleared, chosen }));
    } finally {
        w.close();
    }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
