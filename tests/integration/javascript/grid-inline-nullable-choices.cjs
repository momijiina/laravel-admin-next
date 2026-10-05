'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    assert.ok(['select', 'radio'].includes(fixture.mode));
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
        // Grid initialization needs iCheck; the inline Radio itself uses native controls.
        const $ = w.jQuery;
        w.LA = { token: 'test-token' };
        const notices = [];
        w.toastr = { success: message => notices.push(message) };
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });
        assert.deepEqual(errors, []);
        assert.equal(w.inlineBefore, true);
        assert.equal(w.inlineAfter, true);
        const triggers = w.document.querySelectorAll('[data-toggle=popover]');
        assert.equal(triggers.length, 1, 'the real Grid must render one inline editor');
        const trigger = triggers[0];
        const display = trigger.querySelector('.ie-display');
        const metadata = $(trigger).data('value');
        const original = $(trigger).data('original');
        const valueAttribute = trigger.getAttribute('data-value');
        const originalAttribute = trigger.getAttribute('data-original');
        const storedAttribute = fixture.stored === null ? '' : String(fixture.stored);
        // The shared inline view writes scalar attributes, and jQuery parses canonical numbers.
        const storedMetadata = storedAttribute !== '' && String(Number(storedAttribute)) === storedAttribute
            ? Number(storedAttribute) : storedAttribute;
        assert.equal(valueAttribute, storedAttribute);
        assert.equal(originalAttribute, storedAttribute);
        assert.equal(metadata, storedMetadata);
        assert.equal(original, storedMetadata);
        assert.equal(display.textContent, fixture.label);
        const selected = () => {
            const popover = w.document.querySelector('.popover.in');
            if (fixture.mode === 'select') {
                const option = popover.querySelector('select').selectedOptions[0];
                return option ? option.value : null;
            }
            const radio = popover.querySelector('input[type=radio]:checked');
            return radio ? radio.value : null;
        };
        const transition = (event, action) => new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`${event} did not finish`)), 3000);
            $(trigger).one(event, () => { clearTimeout(timer); resolve(); });
            action();
        });
        const open = async expected => {
            await transition('shown.bs.popover', () => trigger.click());
            assert.equal(w.document.querySelectorAll('.popover.in').length, 1, 'actual Bootstrap popover must open');
            const controls = w.document.querySelectorAll(fixture.mode === 'select'
                ? '.popover.in select' : '.popover.in input[type=radio]');
            assert.ok(controls.length > 0, 'the inline editor must contain its actual controls');
            assert.equal(selected(), expected, 'existing selection and default behavior must remain unchanged');
        };
        const choose = value => {
            const popover = w.document.querySelector('.popover.in');
            if (fixture.mode === 'select') {
                const select = popover.querySelector('select');
                assert.ok(Array.from(select.options).some(option => option.value === value), `option ${value} must exist`);
                select.value = value;
                select.dispatchEvent(new w.Event('change', { bubbles: true }));
            } else {
                const radio = Array.from(popover.querySelectorAll('input[type=radio]')).find(input => input.value === value);
                assert.ok(radio, `radio ${value} must exist`);
                radio.click();
            }
            assert.equal(selected(), value);
        };
        const assertUnchanged = () => {
            assert.equal($(trigger).data('value'), metadata, 'opening and cancellation must not change cached metadata');
            assert.equal($(trigger).data('original'), original);
            assert.equal(trigger.getAttribute('data-value'), valueAttribute);
            assert.equal(trigger.getAttribute('data-original'), originalAttribute);
            assert.equal(display.textContent, fixture.label);
        };
        const requests = [];
        let success;
        $.ajax = options => {
            requests.push(JSON.parse(JSON.stringify({
                url: options.url, type: options.type, data: options.data, query: $.param(options.data),
            })));
            success = options.success;
        };
        await open(fixture.initial);
        assertUnchanged();
        const initial = selected();
        if (fixture.inspectOnly) {
            assert.equal(requests.length, 0);
            assert.deepEqual(errors, [], 'production scripts and shipped plugins must not throw');
            process.stdout.write(JSON.stringify({ initial }));
            return;
        }
        choose(fixture.choose);
        await transition('hidden.bs.popover', () => w.document.querySelector('.popover.in .ie-cancel').click());
        assert.equal(w.document.querySelectorAll('.popover.in').length, 0);
        assert.equal(requests.length, 0, 'cancel must not save');
        assertUnchanged();
        await open(fixture.initial);
        assertUnchanged();
        choose(fixture.choose);
        w.document.querySelector('.popover.in .ie-submit').click();
        assert.equal(requests.length, 1, 'the actual submit handler must emit one AJAX request');
        assertUnchanged();
        const request = requests[0];
        assert.equal(request.url, fixture.url);
        assert.equal(request.type, 'POST');
        assert.deepEqual(request.data, {
            _token: 'test-token', _method: 'PUT', _edit_inline: true,
            [trigger.getAttribute('data-name')]: fixture.choose,
        });
        const result = { initial, request };
        if (fixture.response) {
            assert.equal(fixture.response.status, true);
            await transition('hidden.bs.popover', () => success(fixture.response));
            assert.deepEqual(notices, [fixture.response.message]);
            assert.equal(display.textContent, fixture.savedLabel);
            assert.equal($(trigger).data('value'), fixture.choose);
            assert.equal($(trigger).data('original'), fixture.choose);
            // The existing handler updates jQuery metadata, not the raw attributes.
            assert.equal(trigger.getAttribute('data-value'), valueAttribute);
            assert.equal(trigger.getAttribute('data-original'), originalAttribute);
            await open(fixture.choose);
            result.reopened = selected();
            result.label = display.textContent;
            assert.equal(requests.length, 1, 'reopening must not submit a second request');
        }
        assert.deepEqual(errors, [], 'production scripts and shipped plugins must not throw');
        process.stdout.write(JSON.stringify(result));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
