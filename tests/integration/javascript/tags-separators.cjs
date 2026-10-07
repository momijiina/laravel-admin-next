'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
(async () => {
    let result;
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', url: 'https://offline.invalid/'});
        const window = dom.window;
        try {
            const errors = [];
            window.addEventListener('error', event => errors.push(String(event.error || event.message)));
            window.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
            for (const asset of ['bootstrap/js/bootstrap.min.js', 'plugins/iCheck/icheck.min.js', 'plugins/select2/select2.full.min.js']) {
                window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            const $ = window.jQuery;
            const scripts = window.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            for (const script of scripts.querySelectorAll('script')) window.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('ready timeout')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            assert.deepEqual(errors, [], 'production initialization must finish without errors');
            const select = window.document.querySelector('select[name="tags[]"]');
            const plugin = $(select).data('select2');
            assert.ok(plugin, 'The shipped Select2 plugin must initialize');
            const selected = () => Array.from(select.selectedOptions, option => option.value);
            const initial = selected();
            const createTag = plugin.options.get('createTag');
            const plain = createTag({term: 'berry'});
            const probes = (fixture.probes || []).map(term => createTag({term}));
            let delimited = null;
            if (fixture.clear) $(select).val([]).trigger('change');
            if (fixture.term !== undefined) {
                delimited = createTag({term: fixture.term});
                plugin.trigger('query', {term: fixture.term});
                const options = plugin.$results.find('[aria-selected="false"]');
                assert.equal(options.length, 1, 'the real Select2 query should offer exactly the new tag');
                options.trigger('mouseup');
                assert.equal(selected().length, 1, 'choosing the result updates the native select');
            }
            const query = new window.URLSearchParams(new window.FormData(select.form)).toString();
            assert.deepEqual([...new window.URLSearchParams($(select.form).serialize())],
                [...new window.URLSearchParams(query)], 'native and jQuery successful controls agree');
            assert.deepEqual(errors, [], 'production interaction must finish without errors');
            const current = JSON.parse(JSON.stringify({
                plain, delimited, probes, initial, selected: selected(), query,
                separators: plugin.options.get('tokenSeparators'),
            }));
            if (result) assert.deepEqual(current, result, 'both jQuery versions behave identically');
            result = current;
        } finally { window.close(); }
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => { console.error(error); process.exitCode = 1; });
