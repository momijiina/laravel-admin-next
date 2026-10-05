'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

(async () => {
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', url: 'https://offline.invalid/'});
    const w = dom.window;
    w.scrollTo = () => {};
    try {
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        w.eval(fs.readFileSync(fixture.jquery === 'shipped'
            ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
        for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'sweetalert2/dist/sweetalert2.min.js']) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        const requests = [], callbacks = [], completed = [], notices = [];
        let selectedKeys = [], redirects = 0, respondingForm = null;
        const forms = Array.from(w.document.querySelectorAll('.modal form'));
        const modals = forms.map(form => form.closest('.modal'));
        const buttons = forms.map(form => form.querySelector('[type=submit]'));
        const inputs = forms.map(form => form.querySelector('[name=title]'));
        const labels = buttons.map(button => button.innerHTML);
        assert.equal(forms.length, 2);
        $.admin = {
            token: 'synthetic-token', swal: (...args) => w.swal(...args),
            grid: {selected: () => selectedKeys},
            toastr: Object.fromEntries(['success', 'warning', 'error'].map(type => [type, message => {
                notices.push([type, message]); return $('<div>');
            }])),
            redirect: url => {
                assert.equal(url, '/saved');
                const index = respondingForm;
                ++redirects;
                assert.equal($(modals[index]).hasClass('in'), false, 'successful form hides before navigation');
                assert.equal(buttons[index].disabled, true, 'Bootstrap reset must not precede navigation dispatch');
            },
        };
        // Replace only transport. Genuine HTTP response bodies are supplied by
        // the PHP fixture; every captured payload is replayed through Laravel.
        $.ajax = options => {
            assert.equal(options.contentType, false);
            assert.equal(options.processData, false);
            assert.equal(options.cache, false);
            requests.push({url: options.url, method: options.method, data: Array.from(options.data.entries())});
            callbacks.push(options);
        };
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        const settle = async () => {
            await new Promise(resolve => w.setTimeout(resolve, 20));
            // jsdom has no CSS animation engine. Complete only an animation
            // that the shipped widget has already started when closing.
            const hiding = w.document.querySelector('.swal2-popup.swal2-hide');
            if (hiding) {
                for (const event of ['webkitAnimationEnd', 'oAnimationEnd', 'animationend']) {
                    hiding.dispatchEvent(new w.Event(event));
                }
            }
        };
        // Repeating emitted ready initialization must still leave one handler.
        for (let repeat = 0; repeat < 2; ++repeat) {
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('ready timeout')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
        }
        const open = async index => {
            selectedKeys = [String(index + 1)];
            w.document.querySelector('.modal-retry-'+(index + 1)).click();
            await settle();
            assert.equal($(modals[index]).hasClass('in'), true);
        };
        const submit = async (index, confirm = false) => {
            const count = requests.length;
            buttons[index].click();
            await settle();
            assert.equal(buttons[index].disabled, true, 'pending Submit is locked');
            assert.equal(buttons[index].classList.contains('disabled'), true);
            assert.notEqual(buttons[index].innerHTML, labels[index]);
            buttons[index].click();
            assert.equal(requests.length, count + (confirm ? 0 : 1), 'repeated native click must not duplicate requests');
            if (confirm) {
                assert.ok(w.document.querySelector('.swal2-confirm'));
            }
            return count;
        };
        const confirm = async () => {
            const count = requests.length;
            w.document.querySelector('.swal2-confirm').click();
            await settle();
            assert.equal(requests.length, count + 1);
            w.document.querySelector('.swal2-confirm').click();
            buttons[0].click();
            assert.equal(requests.length, count + 1, 'Confirm and Submit stay locked while AJAX is pending');
            return count;
        };
        const complete = async (index, response) => {
            respondingForm = Number(requests[index].data.find(([name]) => name === 'record')[1]) - 1;
            if (response === 'http-error') callbacks[index].error({responseJSON: fixture.responses[response], status: 500});
            else if (response === 'network-error') callbacks[index].error({status: 0});
            else callbacks[index].success(fixture.responses[response]);
            completed.push({index, response});
            await settle();
        };
        const reset = () => {
            assert.equal(buttons[0].disabled, false, 'failed/cancelled Submit must reset');
            assert.equal(buttons[0].classList.contains('disabled'), false);
            assert.equal(buttons[0].innerHTML, labels[0], 'restore original Submit label');
            assert.equal($(modals[0]).hasClass('in'), true);
            assert.equal(inputs[0].value, title, 'retain entered input');
            assert.equal(buttons[1].disabled, true, 'other pending form must remain locked');
            assert.notEqual(buttons[1].innerHTML, labels[1]);
            assert.equal(redirects, 0, 'failure/cancel must not navigate');
            assert.equal(w.swal.isVisible(), false, 'confirmation should be dismissed');
        };
        await open(1);
        inputs[1].value = 'Other pending title';
        const other = await submit(1);
        await open(0);
        let title = fixture.mode === 'http-error' ? 'HTTP failure'
            : fixture.mode === 'status-false' ? 'x' : 'Entered title';
        inputs[0].value = title;
        const pendingDismissal = fixture.mode.startsWith('pending-');
        const dismissedSuccess = pendingDismissal && fixture.mode.endsWith('-success');
        if (pendingDismissal) {
            title = dismissedSuccess ? 'Corrected title' : 'HTTP failure';
            inputs[0].value = title;
            await submit(0, true);
            const request = await confirm();
            if (fixture.mode.includes('-escape-')) {
                w.document.querySelector('.swal2-popup').dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', keyCode: 27, which: 27, bubbles: true}));
            } else {
                w.document.querySelector('.swal2-container').click();
            }
            await settle();
            assert.equal(w.swal.isVisible(), false, 'actual pending confirmation dismissal');
            assert.equal(buttons[0].disabled, true, 'dismissal must not unlock an in-flight request');
            assert.equal(buttons[1].disabled, true);
            buttons[0].click(); buttons[1].click();
            assert.equal(requests.length, 2, 'dismissal cannot enable a duplicate native submission');
            assert.equal(redirects, 0);
            await complete(request, dismissedSuccess ? 'success' : 'http-error');
            // Existing SweetAlert dismissal discards the outer response handling.
            // Keep that behavior, but the request callback still owns reset.
            assert.deepEqual(notices, []);
            assert.equal(redirects, 0);
            if (!dismissedSuccess) reset();
        } else if (fixture.mode !== 'success') {
            for (let retry = 0; retry < 2; ++retry) {
                let request = await submit(0, fixture.confirm);
                if (fixture.mode === 'cancel' || fixture.mode === 'escape') {
                    if (fixture.mode === 'cancel') w.document.querySelector('.swal2-cancel').click();
                    else w.document.querySelector('.swal2-popup').dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', keyCode: 27, which: 27, bubbles: true}));
                    await settle();
                    assert.equal(requests.length, 1, 'dismissal before Confirm sends no request');
                } else {
                    if (fixture.confirm) request = await confirm();
                    await complete(request, fixture.mode);
                }
                reset();
                if (retry === 0) {
                    forms[0].querySelector('[data-dismiss=modal]').click();
                    assert.equal($(modals[0]).hasClass('in'), false, 'Close dismisses actual Bootstrap modal');
                    await open(0);
                    reset();
                }
            }
            const expectedNotice = fixture.mode === 'http-error' ? ['error', 'Temporary failure']
                : fixture.mode === 'status-false' ? ['error', fixture.responses['status-false'].toastr.content] : null;
            assert.deepEqual(notices, expectedNotice ? [expectedNotice, expectedNotice] : [], 'error/cancel notification semantics');
        }
        if (!dismissedSuccess) {
            inputs[0].value = 'Corrected title';
            let first = await submit(0, fixture.confirm);
            if (fixture.confirm) first = await confirm();
            await complete(first, 'success');
        }
        assert.equal(redirects, dismissedSuccess ? 0 : 1);
        assert.equal($(modals[0]).hasClass('in'), false);
        assert.equal(buttons[0].disabled, false, 'preserve existing successful hidden-button reset');
        assert.equal(buttons[0].innerHTML, labels[0]);
        assert.equal(buttons[1].disabled, true);
        const count = requests.length;
        buttons[1].click();
        assert.equal(requests.length, count);
        await complete(other, 'success');
        assert.equal(redirects, dismissedSuccess ? 1 : 2);
        assert.equal(buttons[1].disabled, false);
        assert.equal(buttons[1].innerHTML, labels[1]);
        assert.equal(w.swal.isVisible(), false);
        assert.deepEqual(notices.slice(-2), dismissedSuccess ? [['success', 'Saved']] : [['success', 'Saved'], ['success', 'Saved']]);
        assert.deepEqual(errors, []);
        assert.equal(requests.length, completed.length);
        process.stdout.write(JSON.stringify({requests, completed, redirects, pending: requests.length - completed.length}));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
