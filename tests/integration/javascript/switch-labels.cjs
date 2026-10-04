'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    const results = {};
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
        const w = dom.window;
        try {
            const errors = [];
            w.addEventListener('error', event => errors.push(String(event.error || event.message)));
            w.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
            for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js', 'bootstrap-switch/dist/js/bootstrap-switch.min.js']) {
                w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            const $ = w.jQuery;
            const form = w.document.querySelector('form');
            const hidden = form.querySelector('input[type=hidden][name=enabled]');
            const peerHidden = form.querySelector('input[type=hidden][name=other_enabled]');
            const input = form.querySelector('input.enabled.la_checkbox');
            const peer = form.querySelector('input.other_enabled.la_checkbox');
            const initial = hidden.value;
            const scripts = w.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            let initializations = 0;
            const initialize = async () => {
                for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                    $(function () { clearTimeout(timer); resolve(); });
                });
                assert.deepEqual(errors, [], 'production ready scripts must not throw');
                initializations++;
                assert.equal(w.switchBefore, initializations, 'earlier ready content must execute');
                assert.equal(w.switchAfter, initializations, 'later ready content must execute');
                assert.equal(form.querySelectorAll('.bootstrap-switch').length, 2, 'exactly one widget per field');
                assert.ok($(input).data('bootstrap-switch'), 'localized field must initialize');
                assert.ok($(peer).data('bootstrap-switch'), 'another field must initialize');
            };
            await initialize();
            assert.equal(hidden.value, initial, 'initialization must preserve submitted value');
            assert.equal(input.checked, initial === 'on');
            const widget = $(input).data('bootstrap-switch');
            for (const [key, value] of Object.entries(fixture.options)) {
                assert.equal(widget.options[key], value, `${jquery}: ${key} must preserve the original string`);
            }
            for (const state of ['on', 'off']) {
                const expected = w.document.createElement('span');
                expected.innerHTML = fixture.options[`${state}Text`];
                const handle = input.closest('.bootstrap-switch').querySelector(`.bootstrap-switch-handle-${state}`);
                assert.equal(handle.innerHTML, expected.innerHTML, 'existing plugin HTML-label rendering must remain');
                assert.ok(handle.classList.contains(`bootstrap-switch-${fixture.options[`${state}Color`]}`));
            }
            assert.ok(input.closest('.bootstrap-switch').classList.contains(`bootstrap-switch-${fixture.options.size}`));
            let changes = 0;
            $(hidden).on('change.switchLabelsTest', () => { changes++; });
            for (const action of fixture.actions) {
                const beforeChanges = changes;
                const beforeValue = hidden.value;
                const beforePeer = peerHidden.value;
                if (action === 'repeat') {
                    await initialize();
                } else if (action === 'native') {
                    input.click();
                } else if (action === 'other-native') {
                    peer.click();
                } else {
                    const state = action.endsWith('on') ? 'off' : 'on';
                    input.closest('.bootstrap-switch').querySelector(`.bootstrap-switch-handle-${state}`).click();
                }
                assert.equal(hidden.value, input.checked ? 'on' : 'off', 'checkbox changes must update the posted hidden state');
                assert.equal(peerHidden.value, peer.checked ? 'on' : 'off');
                assert.equal($(input).bootstrapSwitch('state'), input.checked);
                assert.equal(changes, beforeChanges + (beforeValue === hidden.value ? 0 : 1), 'one hidden change per state transition');
                if (action !== 'other-native') assert.equal(peerHidden.value, beforePeer, 'another field must retain its value');
                if (action === 'repeat' || action.startsWith('blocked-')) assert.equal(hidden.value, beforeValue);
            }
            const query = new w.URLSearchParams(new w.FormData(form)).toString();
            assert.equal(new w.URLSearchParams($(form).serialize()).toString(), query,
                'native successful controls and jQuery serialization must agree');
            results[jquery] = { initial, query, checked: input.checked, changes };
        } finally {
            w.close();
        }
    }
    process.stdout.write(JSON.stringify(results));
})().catch(error => { console.error(error); process.exitCode = 1; });
