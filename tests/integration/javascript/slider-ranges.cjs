'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');

(async () => {
    const results = {};
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
        const w = dom.window;
        try {
            const errors = [];
            w.addEventListener('error', event => errors.push(String(event.error || event.message)));
            w.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
            for (const asset of ['bootstrap/js/bootstrap.min.js', 'plugins/iCheck/icheck.min.js', 'plugins/ionslider/ion.rangeSlider.min.js']) {
                w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            const $ = w.jQuery;
            const form = w.document.querySelector('form');
            const input = form.querySelector('input[name=band]');
            const peer = form.querySelector('input[name=other_band]');
            const before = { from: input.getAttribute('data-from'), to: input.getAttribute('data-to'), value: input.value };
            const scripts = w.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            const initialize = async () => {
                for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                    $(function () { clearTimeout(timer); resolve(); });
                });
                assert.deepEqual(errors, [], 'production ready scripts must not throw');
                assert.equal($(input).data('isActive'), true);
                assert.equal($(peer).data('isActive'), true);
                assert.equal(peer.value, '42', 'another Slider must remain unchanged');
                assert.equal(form.querySelectorAll('span.irs[id]').length, 2, 'one widget per input');
            };
            await initialize();
            const initialized = input.value;
            // The real shipped API changes selected endpoints without layout-dependent pointer simulation.
            if (fixture.update) $(input).ionRangeSlider('update', fixture.update);
            if (fixture.repeat) await initialize();
            const query = new w.URLSearchParams(new w.FormData(form)).toString();
            assert.equal(new w.URLSearchParams($(form).serialize()).toString(), query,
                'native successful controls must match jQuery serialization after URL encoding normalization');
            results[jquery] = {
                before, initialized, query,
                after: { value: input.value, min: form.querySelector('.irs-min').textContent, max: form.querySelector('.irs-max').textContent },
            };
        } finally {
            w.close();
        }
    }
    process.stdout.write(JSON.stringify(results));
})().catch(error => { console.error(error); process.exitCode = 1; });
