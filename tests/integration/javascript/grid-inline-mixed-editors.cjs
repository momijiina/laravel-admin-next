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
        for (const asset of [
            'AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js',
            'moment/min/moment-with-locales.min.js',
            'eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js',
        ]) w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        const $ = w.jQuery;
        assert.equal($.fn.jquery, fixture.jquery === 'shipped' ? '2.1.4' : '3.7.1');
        if (fixture.jquery === 'modern') {
            // Test-only prerequisite: the legacy shipped datetimepicker uses
            // jQuery's removed size() API. Preserve the real plugin and its
            // dp.change behavior; modern Datetime coverage is shim-assisted.
            $.fn.size = function () { return this.length; };
        }
        w.LA = { token: 'test-token' };
        const notices = [], requests = [], callbacks = [], completed = [], inspected = [];
        w.toastr = { success: message => notices.push(message) };
        const plain = value => JSON.parse(JSON.stringify(value));
        let activeCase = -1, invalid = false, unrelatedClicks = 0;
        $(w.document).on('click', '.unrelated-submit', () => ++unrelatedClicks);
        // Only transport is intercepted. PHP replays every exact $.param query
        // through the real web middleware and Form, and returns those responses.
        $.ajax = options => {
            requests.push(plain({
                case: activeCase, invalid, url: options.url, type: options.type,
                data: options.data, query: $.param(options.data),
            }));
            callbacks.push(options);
        };
        if (!fixture.bindingOnly) {
            const scripts = w.document.createElement('div');
            scripts.innerHTML = fixture.scriptHtml;
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            assert.equal(w.inlineBefore, true);
            assert.equal(w.inlineAfter, true);
        }
        assert.ok(fixture.submitScripts.length > 0, 'exercise actual emitted submit registrations');
        // Rebind only the emitted submit partials, including reversed order.
        // Full popover reinitialization is deliberately outside this regression.
        for (const scripts of [fixture.submitScripts.slice().reverse(), fixture.submitScripts]) {
            for (const script of scripts) w.eval(script);
        }
        const unrelated = w.document.createElement('button');
        unrelated.className = 'unrelated-submit';
        w.document.body.append(unrelated);
        unrelated.click();
        assert.equal(unrelatedClicks, 1, 'rebinding must preserve unrelated delegated handlers');
        assert.deepEqual(errors, []);
        const triggers = Array.from(w.document.querySelectorAll('[data-toggle=popover]'));
        assert.equal(triggers.length, fixture.cases.length, 'all real Grid rows and columns must render');
        const snapshot = trigger => ({
            value: plain($(trigger).data('value')), original: plain($(trigger).data('original')),
            valueAttribute: trigger.getAttribute('data-value'), originalAttribute: trigger.getAttribute('data-original'),
            label: trigger.querySelector('.ie-display').textContent,
        });
        for (const [index, c] of fixture.cases.entries()) {
            activeCase = index;
            const trigger = triggers.find(node => node.closest('[data-fixture-grid]').dataset.fixtureGrid === c.group
                && node.dataset.name === c.name && node.dataset.key === String(c.id));
            assert.ok(trigger, `${c.group}/${c.name}/${c.id} must have a real trigger`);
            const display = trigger.querySelector('.ie-display');
            const original = snapshot(trigger);
            const otherTriggers = triggers.filter(node => node !== trigger);
            const otherSnapshots = otherTriggers.map(snapshot);
            const attribute = Array.isArray(c.stored) ? JSON.stringify(c.stored) : String(c.stored);
            assert.equal(original.valueAttribute, attribute);
            assert.equal(original.originalAttribute, attribute);
            assert.equal(display.textContent, c.label);
            let content;
            const transition = (event, action) => new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error(`${c.name}/${c.id}: ${event} did not finish`)), 3000);
                $(trigger).one(event, () => { clearTimeout(timer); resolve(); });
                action();
            });
            const controls = () => content.querySelectorAll('.ie-input');
            const value = () => {
                if (['multipleSelect', 'checkbox'].includes(c.editor)) {
                    return Array.from(content.querySelectorAll(c.editor === 'multipleSelect'
                        ? 'option:checked' : 'input:checked'), node => node.value);
                }
                if (c.editor === 'radio') return content.querySelector('input:checked')?.value ?? null;
                return controls()[0].value;
            };
            const change = next => {
                if (c.editor === 'radio' || c.editor === 'checkbox') {
                    for (const input of controls()) {
                        const checked = Array.isArray(next) ? next.includes(input.value) : input.value === next;
                        if (checked !== input.checked) {
                            if (c.editor === 'checkbox' || checked) input.click();
                        }
                    }
                } else if (c.editor === 'multipleSelect') {
                    const select = controls()[0];
                    for (const option of select.options) option.selected = next.includes(option.value);
                    select.dispatchEvent(new w.Event('change', { bubbles: true }));
                } else if (c.editor === 'datetime' && !fixture.bindingOnly) {
                    const picker = $(content).find('.ie-container').data('DateTimePicker');
                    assert.ok(picker, 'shipped datetime picker must initialize');
                    // Use its real API, which emits production dp.change and updates
                    // the native hidden inline input used by the submit handler.
                    picker.date(w.moment(next, 'YYYY-MM-DD HH:mm:ss', true));
                } else {
                    controls()[0].value = next;
                    controls()[0].dispatchEvent(new w.Event('input', { bubbles: true }));
                    controls()[0].dispatchEvent(new w.Event('change', { bubbles: true }));
                }
                assert.deepEqual(value(), next, `${c.name}/${c.id}: actual control changes must take effect`);
            };
            const open = async expected => {
                if (fixture.bindingOnly) {
                    // Same-name Grids already collide in trigger/template IDs.
                    // Test ONLY submit ownership: use each real rendered template
                    // and supply the association normally made by shown.bs.popover.
                    const template = Array.from(w.document.querySelectorAll('template')).find(node => {
                        if (node.id !== trigger.dataset.target) return false;
                        return c.editor === 'radio' ? node.content.querySelector('input[type=radio]')
                            : node.content.querySelector('select');
                    });
                    assert.ok(template, `rendered ${c.editor} template must exist`);
                    const holder = w.document.createElement('div');
                    holder.className = 'popover in binding-only';
                    holder.innerHTML = template.innerHTML;
                    w.document.body.append(holder);
                    content = holder.querySelector('.ie-content');
                    $(content).data('trigger', $(trigger)).data('display', $(display));
                    change(expected);
                } else {
                    await transition('shown.bs.popover', () => trigger.click());
                    assert.equal(w.document.querySelectorAll('.popover.in').length, 1, 'actual Bootstrap popover opens');
                    content = w.document.querySelector('.popover.in .ie-content');
                    assert.ok(content);
                    assert.equal($(content).data('trigger')[0], trigger, 'popover belongs to the requested row/column');
                }
                assert.ok(controls().length > 0);
                assert.deepEqual(value(), expected, `${c.name}/${c.id}: reopened control values`);
            };
            const hide = async action => {
                if (fixture.bindingOnly) {
                    action();
                    content.closest('.binding-only').remove();
                } else await transition('hidden.bs.popover', action);
                assert.equal(w.document.querySelectorAll('.popover.in').length, 0);
            };
            const cancel = () => hide(() => content.querySelector('.ie-cancel').click());
            const unchanged = () => {
                assert.deepEqual(snapshot(trigger), original, 'pending/invalid/cancel must preserve display and original metadata');
                assert.deepEqual(otherTriggers.map(snapshot), otherSnapshots, 'other editors must remain untouched');
            };
            const submit = isInvalid => {
                invalid = isInvalid;
                const before = requests.length;
                content.querySelector('.ie-submit').click();
                assert.equal(requests.length, before + 1, `${c.name}/${c.id}: one native submit emits exactly one request`);
                const request = requests.at(-1);
                assert.equal(request.url, c.url, 'each editor retains its own resource URL');
                assert.equal(request.type, 'POST');
                assert.deepEqual(request.data, {
                    _token: 'test-token', _method: 'PUT', _edit_inline: true,
                    [c.name]: isInvalid ? c.invalid : c.saved,
                }, `${c.name}/${c.id}: use this editor's extractor and payload name`);
                unchanged();
                return callbacks.at(-1);
            };
            await open(c.initial);
            unchanged();
            inspected.push(index);
            if (fixture.inspectOnly) {
                await cancel();
                continue;
            }
            const count = requests.length;
            change(c.saved);
            await cancel();
            assert.equal(requests.length, count, 'cancel emits no request');
            unchanged();
            await open(c.initial);
            unchanged();
            if (c.invalid !== null) {
                change(c.invalid);
                const callback = submit(true);
                callback.statusCode[422]({ responseJSON: fixture.invalidResponse });
                assert.equal(w.document.querySelectorAll('.popover.in').length, 1, 'validation keeps the editor open');
                assert.equal(value(), c.invalid, 'validation retains typed input for correction');
                assert.ok(content.querySelector('.error').textContent.includes(fixture.invalidResponse.errors['title.0']));
                assert.deepEqual(notices, completed.map(item => fixture.responses[item].message));
                unchanged();
            }
            change(c.saved);
            const callback = submit(false);
            if (fixture.responses) {
                const response = fixture.responses[index];
                assert.equal(response.status, true, 'deliver actual successful HTTP response');
                await hide(() => callback.success(response));
                assert.equal(display.textContent, c.savedLabel, 'use this editor’s display callback');
                assert.deepEqual(plain($(trigger).data('value')), c.saved);
                assert.deepEqual(plain($(trigger).data('original')), c.saved);
                // Preserve the existing jQuery-cache contract, not raw attribute rewrites.
                assert.equal(trigger.getAttribute('data-value'), original.valueAttribute);
                assert.equal(trigger.getAttribute('data-original'), original.originalAttribute);
                assert.deepEqual(otherTriggers.map(snapshot), otherSnapshots);
                await open(c.saved);
                assert.equal(display.textContent, c.savedLabel);
                await cancel();
                assert.equal(requests.length, count + (c.invalid !== null ? 2 : 1), 'success reopen/cancel must not resubmit');
                completed.push(index);
            } else {
                await cancel();
                unchanged();
            }
        }
        assert.deepEqual(errors, [], 'emitted production code and shipped plugins must not throw');
        assert.deepEqual(notices, completed.map(index => fixture.responses[index].message));
        process.stdout.write(JSON.stringify({ requests, completed, inspected }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
