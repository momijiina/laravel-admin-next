'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');

(async () => {
    let result;
    for (const jquery of ['shipped', 'modern']) {
        const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
        const w = dom.window;
        try {
            w.eval(fs.readFileSync(jquery === 'shipped'
                ? path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
            for (const asset of ['bootstrap/js/bootstrap.min.js', 'plugins/iCheck/icheck.min.js']) {
                w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            const runtimeErrors = [];
            w.addEventListener('error', event => runtimeErrors.push(event.error || new Error(event.message)));
            const $ = w.jQuery;
            const form = w.document.querySelector('form');
            const controls = [...form.querySelectorAll('input[type=checkbox][name="choices[]"]')];
            const selected = (name = 'choices[]') => [...form.querySelectorAll(`input[type=checkbox][name="${name}"]:checked`)].map(input => input.value);
            const native = () => new w.URLSearchParams(new w.FormData(form)).toString();
            const before = native();
            const other = selected('other_choices[]');
            const assertState = expected => {
                assert.deepEqual(selected(), expected, `${jquery}: successful controls`);
                for (const input of controls) {
                    assert.equal(input.closest('label').classList.contains('active'), input.checked, `${jquery}: active ${input.value}`);
                }
                assert.deepEqual(selected('other_choices[]'), other, 'another styled field must retain its selection');
                assert.equal($(form).serialize(), native(), 'jQuery and native FormData must agree');
            };
            assertState(fixture.expected);
            const scripts = w.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            assert.deepEqual(runtimeErrors, [], 'production ready scripts must not throw');
            assert.equal(native(), before, 'production script initialization must preserve successful controls');
            assertState(fixture.expected);
            let changes = 0;
            $(controls).on('change.styledZeroTest', () => { changes++; });
            for (const selection of fixture.selections) {
                for (const input of controls) {
                    if (input.checked !== selection.includes(input.value)) {
                        const beforeChanges = changes;
                        const label = input.closest('label');
                        // Card content clicks bubble to the same actual shipped label handler.
                        (label.querySelector('.panel-body') || label).click();
                        assert.equal(changes, beforeChanges + 1, 'each actual label click must emit one change');
                    }
                }
                assertState(selection);
            }
            const current = { query: native(), selected: selected() };
            if (result) assert.deepEqual(current, result, 'both jQuery versions must submit identical controls');
            result = current;
        } finally {
            w.close();
        }
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => { console.error(error); process.exitCode = 1; });
