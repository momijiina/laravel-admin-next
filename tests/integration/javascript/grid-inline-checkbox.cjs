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
        const plain = value => JSON.parse(JSON.stringify(value));
        assert.deepEqual(plain(metadata), fixture.stored, 'Grid must retain the stored JSON scalar types');
        assert.deepEqual(plain(original), fixture.stored);
        assert.deepEqual(JSON.parse(valueAttribute), fixture.stored);
        assert.deepEqual(JSON.parse(originalAttribute), fixture.stored);
        assert.equal(display.textContent, fixture.label);
        const selected = () => Array.from(w.document.querySelectorAll('.popover.in input[type=checkbox]:checked'), checkbox => checkbox.value);
        const transition = (event, action) => new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`${event} did not finish`)), 3000);
            $(trigger).one(event, () => { clearTimeout(timer); resolve(); });
            action();
        });
        const open = async expected => {
            await transition('shown.bs.popover', () => trigger.click());
            assert.equal(w.document.querySelectorAll('.popover.in input[type=checkbox]').length, 7, 'actual Bootstrap popover must open');
            assert.deepEqual(selected(), expected, 'only exact string-equivalent IDs must be selected');
        };
        const change = changes => {
            const checkboxes = Array.from(w.document.querySelectorAll('.popover.in input[type=checkbox]'));
            for (const [value, state] of Object.entries(changes)) {
                const option = checkboxes.find(option => option.value === value);
                assert.ok(option, `option ${value} must exist`);
                if (option.checked !== state) option.click();
            }
        };
        const assertUnchanged = () => {
            assert.equal($(trigger).data('value'), metadata, 'opening must not replace cached metadata');
            assert.equal($(trigger).data('original'), original);
            assert.deepEqual(plain(metadata), fixture.stored, 'opening must not mutate integer IDs');
            assert.deepEqual(plain(original), fixture.stored);
            assert.equal(trigger.getAttribute('data-value'), valueAttribute);
            assert.equal(trigger.getAttribute('data-original'), originalAttribute);
            assert.equal(display.textContent, fixture.label);
        };
        const requests = [];
        let success;
        $.ajax = options => {
            requests.push(plain({ url: options.url, type: options.type, data: options.data, query: $.param(options.data) }));
            success = options.success;
        };
        await open(fixture.initial);
        assertUnchanged();
        if (fixture.inspectOnly) {
            assert.equal(requests.length, 0);
            process.stdout.write(JSON.stringify({ initial: selected() }));
            return;
        }
        const initial = selected();
        change(fixture.changes);
        assert.deepEqual(selected(), fixture.saved);
        await transition('hidden.bs.popover', () => w.document.querySelector('.popover.in .ie-cancel').click());
        assert.equal(w.document.querySelectorAll('.popover.in').length, 0);
        assert.equal(requests.length, 0, 'cancel must not save');
        assertUnchanged();
        await open(fixture.initial);
        assertUnchanged();
        change(fixture.changes);
        assert.deepEqual(selected(), fixture.saved);
        w.document.querySelector('.popover.in .ie-submit').click();
        assert.equal(requests.length, 1, 'the actual submit handler must emit one AJAX request');
        assertUnchanged();
        const request = requests[0];
        assert.equal(request.url, fixture.url);
        assert.deepEqual(request.data.choices, fixture.saved, 'AJAX must include retained and newly selected IDs');
        const result = { initial, request };
        if (fixture.response) {
            assert.equal(fixture.response.status, true);
            await transition('hidden.bs.popover', () => success(fixture.response));
            assert.deepEqual(notices, [fixture.response.message]);
            assert.equal(display.textContent, fixture.savedLabel);
            assert.deepEqual(plain($(trigger).data('value')), fixture.saved);
            assert.deepEqual(plain($(trigger).data('original')), fixture.saved);
            // The existing handler updates jQuery metadata, not the raw attributes.
            assert.equal(trigger.getAttribute('data-value'), valueAttribute);
            assert.equal(trigger.getAttribute('data-original'), originalAttribute);
            await open(fixture.saved);
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
