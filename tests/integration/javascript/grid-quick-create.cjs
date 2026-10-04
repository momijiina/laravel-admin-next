'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
    const w = dom.window;
    try {
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        w.eval(fs.readFileSync(fixture.jquery === 'shipped'
            ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
        for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js']) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        const notices = [];
        let reloads = 0;
        $.admin = {
            toastr: Object.fromEntries(['success', 'warning', 'error'].map(type =>
                [type, message => notices.push([type, message])])),
            reload: () => reloads++,
        };
        w.console.info = () => {};
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });
        assert.deepEqual(errors, []);
        assert.equal(w.quickCreateBefore, true);
        assert.equal(w.quickCreateAfter, true);
        const forms = Array.from(w.document.querySelectorAll('.quick-create .create-form'));
        assert.equal(forms.length, 2, 'two real Grid QuickCreate forms must render');
        const inputs = forms.map(form => form.querySelector('[name="title"]'));
        const buttons = forms.map(form => form.querySelector('button'));
        const labels = buttons.map(button => button.innerHTML);
        const requests = [];
        const callbacks = [];
        const completed = [];
        $.ajax = options => {
            requests.push({ url: options.url, type: options.type, data: options.data });
            callbacks.push(options);
        };
        // Bootstrap changes button state asynchronously. Let its own timer run
        // before asserting disabled native clicks and settled reset behavior.
        const settle = () => new Promise(resolve => w.setTimeout(resolve, 20));
        const clickSubmit = async formIndex => {
            const count = requests.length;
            buttons[formIndex].click();
            await settle();
            assert.equal(requests.length, count + 1, 'native click must emit exactly one request');
            assert.equal(buttons[formIndex].disabled, true);
            assert.equal(buttons[formIndex].classList.contains('disabled'), true);
            assert.notEqual(buttons[formIndex].innerHTML, labels[formIndex]);
            buttons[formIndex].click();
            assert.equal(requests.length, count + 1, 'the loading button must remain locked');
            return count;
        };
        const complete = async (index, failed) => {
            if (failed && fixture.mode === 'error') {
                callbacks[index].error({ responseJSON: fixture.failure }, 'error');
            } else {
                callbacks[index].success(failed ? fixture.failure : fixture.success, 'success', {});
            }
            completed.push(index);
            await settle();
        };
        const assertReset = () => {
            assert.equal(buttons[0].disabled, false, 'unsuccessful response must enable the originating form');
            assert.equal(buttons[0].classList.contains('disabled'), false);
            assert.equal(buttons[0].innerHTML, labels[0], 'reset must restore the exact submit label');
            assert.equal(inputs[0].value, 'x', 'failed submission must preserve user input');
            assert.equal(reloads, 0, 'an unsuccessful response must not reload');
            assert.equal(buttons[1].disabled, true, 'another form must stay locked while its request is pending');
            assert.notEqual(buttons[1].innerHTML, labels[1]);
        };
        forms[0].closest('.quick-create').querySelector('.create').click();
        assert.notEqual(forms[0].style.display, 'none');
        inputs[1].value = 'Other pending title';
        const other = await clickSubmit(1);
        inputs[0].value = fixture.mode === 'success' ? 'Corrected title' : 'x';
        let first = await clickSubmit(0);
        if (fixture.mode !== 'success') {
            await complete(first, true);
            assertReset();
            const row = forms[0].closest('.quick-create');
            row.querySelector('.cancel').click();
            assert.equal(forms[0].style.display, 'none');
            row.querySelector('.create').click();
            assert.notEqual(forms[0].style.display, 'none');
            assertReset();
            first = await clickSubmit(0);
            await complete(first, true);
            assertReset();
            const kind = fixture.mode === 'validation' ? 'warning' : 'error';
            assert.deepEqual(notices, fixture.mode === 'rejected' ? [] : [
                [kind, fixture.failure.message], [kind, fixture.failure.message],
            ], 'existing warning/error/no-notice response semantics must remain unchanged');
            inputs[0].value = 'Corrected title';
            first = await clickSubmit(0);
        }
        await complete(first, false);
        assert.equal(reloads, 1);
        assert.equal(buttons[0].disabled, true, 'success must remain locked until the existing reload replaces the form');
        assert.equal(buttons[1].disabled, true, 'success must not reset a different pending form');
        const count = requests.length;
        buttons[0].click();
        buttons[1].click();
        assert.equal(requests.length, count, 'success and pending native clicks must not duplicate saves');
        await complete(other, false);
        assert.equal(reloads, 2);
        assert.deepEqual(notices.slice(-2), [
            ['success', fixture.success.message], ['success', fixture.success.message],
        ]);
        assert.equal(inputs[0].value, 'Corrected title');
        assert.equal(inputs[1].value, 'Other pending title');
        assert.deepEqual(errors, [], 'production Grid scripts and shipped plugins must not throw');
        process.stdout.write(JSON.stringify({ requests, completed, reloads }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
