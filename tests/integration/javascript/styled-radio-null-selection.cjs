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
            const errors = [];
            w.addEventListener('error', event => errors.push(event.error || new Error(event.message)));
            const $ = w.jQuery;
            const form = w.document.querySelector('form');
            const controls = [...form.querySelectorAll('input[type=radio][name="state"]')];
            const selected = (name = 'state') => [...form.querySelectorAll(`input[name="${name}"]:checked`)].map(input => input.value);
            const native = () => new w.URLSearchParams(new w.FormData(form)).toString();
            const before = native();
            const assertState = expected => {
                assert.deepEqual(selected(), expected, `${jquery}: selected state`);
                assert.deepEqual(selected('other_state'), ['1'], 'independent styled radio group');
                assert.deepEqual(selected('plain_state'), ['0'], 'ordinary radio control');
                assert.deepEqual(selected('button_choices[]'), ['0'], 'button checkbox control');
                assert.deepEqual(selected('card_choices[]'), ['0'], 'card checkbox control');
                for (const input of form.querySelectorAll('.radio-group-toggle input, .checkbox-group-toggle input')) {
                    assert.equal(input.closest('label').classList.contains('active'), input.checked,
                        `${jquery}: checked/active parity for ${input.name}=${input.value}`);
                }
                assert.equal(form.querySelectorAll('input[type=hidden][name="state"]').length, 0, 'no radio clearing marker');
                // jQuery versions encode spaces differently; compare ordered decoded pairs,
                // retaining duplicate names and empty markers. Submit native FormData below.
                assert.deepEqual([...new w.URLSearchParams($(form).serialize())],
                    [...new w.URLSearchParams(native())], 'jQuery/native successful controls parity');
            };
            const assertConditions = expected => {
                if (!fixture.conditional) return;
                for (const [name, visible] of [['zero', expected.includes('0')], ['one', expected.includes('1')], ['other', true]]) {
                    const group = form.querySelector(`input[name="detail_${name}"]`).closest('.cascade-group');
                    assert.equal(!group.classList.contains('hide'), visible, `conditional ${name}`);
                }
            };
            assertState(fixture.expected);
            const scripts = w.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            assert.ok(scripts.querySelector('script'), 'execute the emitted production ready wrapper');
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            assert.deepEqual(errors, [], 'production initialization errors');
            assert.equal(native(), before, 'initialization must not invent or clear a selection');
            assertState(fixture.expected);
            assertConditions(fixture.expected);
            const snapshots = [{ selected: selected(), valid: form.checkValidity() }];
            let changes = 0;
            $(controls).on('change.styledRadioTest', () => { changes++; });
            for (const action of fixture.actions) {
                if (action.click !== undefined) {
                    const input = controls.find(control => control.value === action.click);
                    assert.ok(input, 'requested option exists');
                    const expectedChanges = changes + (input.checked ? 0 : 1);
                    const label = input.closest('label');
                    // Card content and label clicks use actual native label activation and shipped handlers.
                    (action.body ? label.querySelector('.panel-body') || label : label).click();
                    assert.equal(changes, expectedChanges, 'changed selection emits once; repeated clicks do not change it');
                    assertState([action.click]);
                    assertConditions([action.click]);
                } else if (action.omit) {
                    // Programmatic clearing tests native omission; this is not a UI clearing feature.
                    for (const input of controls) {
                        input.checked = false;
                        input.closest('label').classList.remove('active');
                    }
                    assertState([]);
                } else if (action.checkboxes) {
                    for (const name of ['button_choices[]', 'card_choices[]']) {
                        const input = [...form.querySelectorAll('input[type=checkbox]')]
                            .find(control => control.name === name && control.value === '0');
                        const label = input.closest('label');
                        const target = label.querySelector('.panel-body') || label;
                        target.click();
                        assert.equal(input.checked, false, 'styled checkbox still toggles off');
                        assert.equal(label.classList.contains('active'), false);
                        target.click();
                        assert.equal(input.checked, true, 'styled checkbox still toggles on');
                        assertState(fixture.expected);
                    }
                }
                snapshots.push({ selected: selected(), valid: form.checkValidity() });
            }
            assert.deepEqual(errors, [], 'production interaction errors');
            const current = {
                query: native(), selected: selected(), otherSelected: selected('other_state'),
                valid: form.checkValidity(), snapshots,
            };
            if (result) assert.deepEqual(current, result, 'shipped and modern jQuery parity');
            result = current;
        } finally {
            w.close();
        }
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => { console.error(error); process.exitCode = 1; });
