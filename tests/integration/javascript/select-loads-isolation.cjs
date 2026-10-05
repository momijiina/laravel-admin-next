'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');
const regionActive = fixture.scenario !== 'category-only';
const categoryActive = fixture.scenario !== 'region-only';

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
            for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js', 'AdminLTE/plugins/select2/select2.full.min.js']) {
                window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            const $ = window.jQuery;
            const requests = [];
            const transports = [];
            // Only transport is replaced. Responses come from the real HTTP routes.
            $.ajax = options => {
                assert.ok(Object.hasOwn(fixture.responses, options.url), 'Unexpected request '+options.url);
                requests.push(options.url);
                const deferred = $.Deferred();
                transports.push(deferred.promise());
                window.setTimeout(() => deferred.resolve(JSON.parse(JSON.stringify(fixture.responses[options.url]))), 0);
                return deferred.promise();
            };
            const scripts = window.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            for (let i = 0; i < fixture.initializations; ++i) {
                for (const script of scripts.querySelectorAll('script')) window.eval(script.textContent);
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('ready timeout: '+JSON.stringify(errors))), 5000);
                    $(function () { clearTimeout(timer); resolve(); });
                });
            }
            assert.deepEqual(requests, [], 'Initialization must not send requests.');
            assert.equal(typeof window.selectLoadsSentinel, 'function', 'Adjacent consumer script remains intact.');
            const select = name => $('select[name='+name+']');
            const expected = {region: 'europe', country: 'DE', currency: 'EUR', category: 'furniture', product: 'chair', note: 'Keep this note'};
            const steps = [];
            const snapshot = name => {
                const actual = Object.fromEntries(Object.keys(expected).map(field => [field,
                    field === 'note' ? $('input[name=note]').val() : select(field).val()]));
                assert.deepEqual(actual, expected, jquery+' / '+name);
                const form = select('region')[0].form;
                const query = new window.URLSearchParams(new window.FormData(form)).toString();
                assert.equal(new window.URLSearchParams($(form).serialize()).toString(), query);
                const values = new window.URLSearchParams(query);
                for (const [field, value] of Object.entries(expected)) {
                    assert.equal(values.getAll(field).at(-1), value === null ? '' : value, field+' native submission');
                }
                steps.push({name, query, expected: {...expected}});
            };
            const choose = async (field, value, expectedRequests) => {
                const start = requests.length;
                select(field).select2('open');
                const choice = $('.select2-results__option').filter(function () { return $(this).data('data')?.id === value; });
                assert.equal(choice.length, 1, field+' choice '+value);
                choice.trigger('mouseup');
                await Promise.all(transports.slice(start).map(promise => new Promise(resolve => promise.then(() => resolve()))));
                assert.deepEqual(requests.slice(start), expectedRequests, field+' endpoint ownership / no duplicate handlers');
                expected[field] = value;
            };
            const checkLoaded = (field, id, text, allowClear) => {
                assert.deepEqual(Array.from(select(field)[0].options, option => ({id: option.value, text: option.textContent})), [{id, text}]);
                assert.equal(select(field).data('select2').options.get('allowClear'), allowClear, field+' clearability');
                assert.equal(select(field).next('.select2').find('.select2-selection__clear').length, allowClear ? 1 : 0, field+' clear control');
            };
            snapshot('unchanged');
            await choose('region', 'asia', regionActive ? ['/countries?q=asia', '/currencies?q=asia'] : []);
            if (regionActive) {
                expected.country = 'JP'; expected.currency = 'JPY';
                checkLoaded('country', 'JP', 'Japan', true);
                checkLoaded('currency', 'JPY', 'Yen', true);
            }
            snapshot('region changed');
            await choose('category', 'electronics', categoryActive ? ['/products?q=electronics'] : []);
            if (categoryActive) {
                expected.product = 'phone';
                checkLoaded('product', 'phone', 'Phone', false);
            }
            snapshot('category changed');
            if (regionActive) {
                const start = requests.length;
                select('country').next('.select2').find('.select2-selection__clear').trigger('mousedown');
                select('country').select2('close');
                expected.country = null;
                assert.equal(requests.length, start, 'Clearing a child must not invoke an unrelated loader.');
                snapshot('country cleared');
                await choose('country', 'JP', []);
                snapshot('country selected again');
            }
            await choose('region', 'europe', regionActive ? ['/countries?q=europe', '/currencies?q=europe'] : []);
            if (regionActive) {
                expected.country = 'DE'; expected.currency = 'EUR';
                checkLoaded('country', 'DE', 'Germany', true);
                checkLoaded('currency', 'EUR', 'Euro', true);
            }
            snapshot('region restored');
            await choose('category', 'furniture', categoryActive ? ['/products?q=furniture'] : []);
            if (categoryActive) {
                expected.product = 'chair';
                checkLoaded('product', 'chair', 'Chair', false);
            }
            snapshot('category restored');
            assert.deepEqual(errors, []);
            results[jquery] = steps;
        } finally {
            window.close();
        }
    }
    assert.deepEqual(results.shipped, results.modern);
    process.stdout.write(JSON.stringify(results));
})().catch(error => {console.error(error); process.exitCode = 1;});
