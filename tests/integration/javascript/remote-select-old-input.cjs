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
            window.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js')
                : require.resolve('jquery'), 'utf8'));
            for (const asset of [
                'AdminLTE/bootstrap/js/bootstrap.min.js',
                'AdminLTE/plugins/iCheck/icheck.min.js',
                'AdminLTE/plugins/select2/select2.full.min.js',
            ]) window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            const $ = window.jQuery;
            const requests = [];
            let pendingRequests = 0;
            const expectedRequests = fixture.requestCount === undefined ? 1 : fixture.requestCount;
            // Only the transport is replaced. Options came from the test's real HTTP route.
            $.ajax = options => {
                assert.ok(options.url === (fixture.url || '/remote-options?') || (fixture.dependentOptions && options.url === '/dependent-options'));
                requests.push({url: options.url, type: options.type || null, dataType: options.dataType || null});
                pendingRequests++;
                const deferred = $.Deferred();
                if (options.success) deferred.done(options.success);
                window.setTimeout(() => {
                    try {
                        deferred.resolve(options.url === '/dependent-options' ? fixture.dependentOptions : fixture.options);
                    } finally {
                        pendingRequests--;
                    }
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
            // A remote selection can trigger a second, dependent request. Wait
            // for that entire chain, rather than sampling after a fixed delay.
            const deadline = Date.now() + 5000;
            while (pendingRequests > 0 || requests.length < expectedRequests) {
                assert.ok(Date.now() < deadline, 'AJAX completion timeout');
                await new Promise(resolve => window.setTimeout(resolve, 1));
            }
            assert.deepEqual(errors, []);
            assert.equal(requests.length, expectedRequests);
            const select = window.document.querySelector('select[name="choice"], select[name="choice[]"]');
            assert.ok($(select).data('select2'));
            const selected = () => Array.from(select.selectedOptions).map(option => option.value);
            const before = selected();
            const clear = () => {
                const button = $(select).next().find('.select2-selection__clear');
                assert.equal(button.length, 1);
                button.trigger('mousedown');
            };
            if (fixture.action === 'choose') {
                if (before.some(value => value !== '')) clear();
                for (const value of [].concat(fixture.choice)) {
                    $(select).select2('open');
                    const option = $('.select2-results__option').filter(function () {
                        const data = $(this).data('data');
                        return data && String(data.id) === String(value);
                    });
                    assert.equal(option.length, 1, `Selectable option ${value}`);
                    option.trigger('mouseup');
                }
            } else if (fixture.action === 'clear') {
                clear();
            }
            const query = new window.URLSearchParams(new window.FormData(select.form)).toString();
            assert.equal(new window.URLSearchParams($(select.form).serialize()).toString(), query);
            results[jquery] = {
                dataValue: select.getAttribute('data-value'),
                remoteValue: select.getAttribute('data-remote-value'),
                before,
                after: selected(),
                displayed: Array.from($(select).select2('data'), option => String(option.id)).filter(value => value !== ''),
                query,
                requests,
                allowClear: $(select).data('select2').options.get('allowClear'),
                dependent: Array.from(window.document.querySelectorAll('select[name=target] option:checked'), option => option.value),
            };
            assert.deepEqual(errors, []);
        } finally {
            window.close();
        }
    }
    assert.deepEqual(results.shipped, results.modern);
    process.stdout.write(JSON.stringify(results.shipped));
})().catch(error => { console.error(error); process.exitCode = 1; });
