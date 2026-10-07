'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

(async () => {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    const dom = new JSDOM(input.html, { url: input.url, runScripts: 'outside-only' });
    const w = dom.window;
    try {
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        const assets = path.resolve(__dirname, '../../../resources/assets');
        w.eval(fs.readFileSync(input.jquery === 'modern' ? require.resolve('jquery')
            : path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        if (input.mode === 'datetime') {
            for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js',
                'moment/min/moment-with-locales.min.js',
                'eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js']) {
                w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
        }
        const form = w.document.querySelector('form[pjax-container]');
        assert.ok(form, 'Use the actual rendered filter form.');
        assert.equal(form.method, 'get');
        const operation = form.querySelector('input[name="' + input.column + '_group"]');
        const value = form.querySelector('input[name="' + input.column + '"]');
        const label = form.querySelector('.' + input.column + '-filter-group-label');
        assert.ok(operation && value && label, 'Use the production Group controls.');
        assert.equal(operation.type, 'hidden');
        const capture = () => {
            const entries = Array.from(new w.FormData(form).entries());
            const target = new w.URL(form.action);
            target.search = new w.URLSearchParams(entries).toString();
            return { entries, uri: target.href, operator: operation.value,
                label: label.textContent.trim(), value: value.value };
        };
        const beforeReady = capture();
        const holder = w.document.createElement('div');
        holder.innerHTML = input.scriptHtml;
        const scripts = Array.from(holder.querySelectorAll('script[data-exec-on-popstate]'));
        assert.equal(scripts.length, 1, 'Run the actual emitted ready wrapper.');
        assert.ok(scripts[0].textContent.includes(input.column + '-filter-group li a'));
        w.eval(scripts[0].textContent);
        await new Promise(resolve => w.jQuery(resolve));
        assert.deepEqual(errors, [], 'The actual initializer must execute without errors.');
        if (input.mode === 'datetime') {
            assert.ok(w.jQuery(value).data('DateTimePicker'), 'Initialize the shipped datetime picker.');
        }
        const initial = capture();
        assert.deepEqual(initial, beforeReady, 'Initializing the real scripts leaves the controls unchanged.');
        const clicked = [];
        for (const index of input.clicks || []) {
            const link = form.querySelector('.' + input.column + '-filter-group a[data-index="' + index + '"]');
            assert.ok(link, 'Click an actual operator menu item.');
            link.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
            clicked.push(capture());
        }
        assert.deepEqual(errors, []);
        process.stdout.write(JSON.stringify({ jqueryVersion: w.jQuery.fn.jquery, initial, clicked }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
