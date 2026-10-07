'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');
(async () => {
    const results = {};
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', url: 'https://offline.invalid/'});
        const window = dom.window;
        try {
            const errors = [];
            window.addEventListener('error', event => errors.push(String(event.error || event.message)));
            window.eval(fs.readFileSync(jquery === 'shipped' ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
            for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js', 'bootstrap-duallistbox/dist/jquery.bootstrap-duallistbox.min.js']) window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            const $ = window.jQuery;
            const requests = [];
            let pending = 0;
            $.ajax = options => {
                requests.push({url: options.url, type: options.type || null, dataType: options.dataType || null});
                pending++;
                const deferred = $.Deferred();
                window.setTimeout(() => {
                    try { deferred.resolve(fixture.options); } finally { pending--; }
                }, 0);
                return deferred.promise();
            };
            const scripts = window.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            for (const script of scripts.querySelectorAll('script')) window.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('ready timeout')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            const deadline = Date.now() + 5000;
            while (pending > 0 || requests.length < (fixture.static ? 0 : 1)) {
                assert.ok(Date.now() < deadline, 'AJAX completion timeout');
                await new Promise(resolve => window.setTimeout(resolve, 1));
            }
            assert.deepEqual(errors, []);
            assert.equal(requests.length, fixture.static ? 0 : 1);
            const select = window.document.querySelector('select[name="choice[]"]');
            const plugin = $(select).data('plugin_bootstrapDualListbox');
            assert.ok(plugin, 'The shipped dual-listbox plugin initialized');
            const selected = () => Array.from(select.selectedOptions, option => option.value);
            const before = selected();
            const initialOptions = Array.from(select.options, option => ({value: option.value, text: option.text, selected: option.selected, defaultSelected: option.defaultSelected}));
            for (const action of fixture.actions || []) {
                if (action.type === 'reset') {
                    select.form.reset();
                } else if (action.type === 'refresh') {
                    $(select).bootstrapDualListbox('refresh', true);
                } else if (action.type === 'clear') {
                    plugin.elements.removeAllButton.trigger('click');
                } else {
                    assert.ok(['choose', 'remove'].includes(action.type));
                    const helper = action.type === 'choose' ? plugin.elements.select1 : plugin.elements.select2;
                    const matches = Array.from(helper[0].options).filter(option => action.values.includes(option.value));
                    assert.equal(matches.length, action.values.length, 'Every requested ID is available in the real widget');
                    helper.val(action.values);
                    if (plugin.settings.moveOnSelect) helper.trigger('change');
                    else plugin.elements[action.type === 'choose' ? 'moveButton' : 'removeButton'].trigger('click');
                }
            }
            const query = new window.URLSearchParams(new window.FormData(select.form)).toString();
            assert.equal(new window.URLSearchParams($(select.form).serialize()).toString(), query);
            results[jquery] = {
                options: initialOptions,
                after: selected(),
                availableLabels: Array.from(plugin.elements.select1[0].options, option => option.text),
                height: plugin.settings.selectorMinimalHeight,
                moveOnSelect: plugin.settings.moveOnSelect,
                dataValue: select.getAttribute('data-value'),
                unexpectedChildren: select.querySelectorAll('option *').length,
                before,
                selectedLabels: Array.from(plugin.elements.select2[0].options, option => option.text),
                query,
                requests,
            };
            assert.deepEqual(errors, []);
        } finally { window.close(); }
    }
    assert.deepEqual(results.shipped, results.modern);
    process.stdout.write(JSON.stringify(results.shipped));
})().catch(error => { console.error(error); process.exitCode = 1; });
