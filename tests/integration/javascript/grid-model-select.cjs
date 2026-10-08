'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    const results = {};
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(input.html, { runScripts: 'outside-only', url: input.url });
        const w = dom.window;
        try {
            const errors = [];
            w.addEventListener('error', event => errors.push(String(event.error || event.message)));
            w.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js')
                : require.resolve('jquery'), 'utf8'));
            w.eval(fs.readFileSync(path.join(assets, 'AdminLTE/plugins/select2/select2.full.min.js'), 'utf8'));
            const $ = w.jQuery;
            const scripts = w.document.createElement('div');
            scripts.innerHTML = input.scriptHtml;
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('ready timeout')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            const form = w.document.querySelector('form[pjax-container]');
            assert.equal(form.method, 'get');
            const select = form.elements.namedItem(input.name);
            assert.ok(select);
            const widget = $(select).data('select2');
            assert.ok(widget);
            assert.equal(widget.options.get('allowClear'), true);
            assert.equal(widget.options.get('width'), '100%');
            const capture = () => {
                const entries = Array.from(new w.FormData(form).entries());
                const url = new w.URL(form.action);
                url.search = new w.URLSearchParams(entries).toString();
                assert.equal($(form).serialize(), url.search.slice(1));
                return {
                    selected: Array.from(select.selectedOptions, option => option.value).filter(value => value !== ''),
                    labels: Array.from($(select).select2('data'), option => option.text).filter(value => value !== ''),
                    entries, uri: url.href,
                };
            };
            const initial = capture();
            $(select).next().find('.select2-selection__clear').trigger('mousedown');
            const cleared = capture();
            $(select).select2('open');
            const choice = $('.select2-results__option').filter(function () {
                const data = $(this).data('data');
                return data && String(data.id) === input.value;
            });
            assert.equal(choice.length, 1);
            choice.trigger('mouseup');
            const chosen = capture();
            $(select).select2('close');
            $(select).select2('open');
            assert.deepEqual(capture(), chosen, 'Opening again must retain the selected option.');
            $(select).select2('close');
            const reset = w.document.querySelector('a.btn-default, a.btn-facebook');
            assert.ok(reset);
            results[jquery] = { initial, cleared, chosen, reset: reset.href };
            assert.deepEqual(errors, []);
        } finally {
            w.close();
        }
    }
    assert.deepEqual(results.shipped, results.modern);
    process.stdout.write(JSON.stringify(results.shipped));
})().catch(error => { console.error(error); process.exitCode = 1; });
