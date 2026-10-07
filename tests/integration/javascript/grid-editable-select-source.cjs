'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

(async () => {
    const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
    const w = dom.window;
    const assets = path.resolve(__dirname, '../../../resources/assets');
    try {
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        const load = file => w.eval(fs.readFileSync(file, 'utf8'));
        load(fixture.jquery === 'shipped'
            ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'));
        for (const file of [
            'AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js',
            'bootstrap3-editable/js/bootstrap-editable.min.js',
            'nprogress/nprogress.js', 'toastr/build/toastr.min.js', 'sweetalert2/dist/sweetalert2.min.js',
        ]) load(path.join(assets, file));
        const $ = w.jQuery;
        // jsdom has no layout. Supply only the popup visibility boundary used by
        // X-editable's real hide/cancel handler; selection and events stay native.
        Object.defineProperty(w.HTMLElement.prototype, 'offsetWidth', {
            get() { return this.matches('.popover.in') ? 100 : 0; },
        });
        Object.defineProperty(w.HTMLElement.prototype, 'offsetHeight', {
            get() { return this.matches('.popover.in') ? 50 : 0; },
        });
        // PJAX navigation is outside this source-attribute regression; its old
        // bundled build depends on a jQuery 2-only event API.
        $.pjax = Object.assign(() => { throw new Error('unexpected navigation'); }, { defaults: {} });
        $.fn.pjax = function () { return this; };
        w.LA = { token: 'test-token' };
        load(path.join(assets, 'laravel-admin/laravel-admin.js'));
        const notices = [];
        $.admin.toastr.success = message => notices.push(message);
        $.admin.toastr.error = message => { throw new Error(message); };
        const requests = [];
        const pending = [];
        $.ajax = options => {
            requests.push({ url: options.url, type: options.type, data: options.data, query: $.param(options.data) });
            const request = $.Deferred();
            pending.push(request);
            return request.promise();
        };
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scripts;
        for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });
        assert.deepEqual(errors, []);
        assert.equal(requests.length, 0, 'array sources must not cause a URL request');
        const anchors = Array.from(w.document.querySelectorAll('a.grid-editable-choice'));
        assert.equal(anchors.length, fixture.expected.length);
        assert.equal(w.document.querySelectorAll('[data-unexpected]').length, 0);
        const waitFor = async predicate => {
            for (let i = 0; i < 100; i++) {
                if (predicate()) return;
                await new Promise(resolve => w.setTimeout(resolve, 10));
            }
            throw new Error('the shipped editable control did not reach the expected state');
        };
        for (let index = 0; index < anchors.length; index++) {
            const anchor = anchors[index];
            const expected = fixture.expected[index];
            const source = Object.entries(expected.options).map(([value, text]) => ({ value, text }));
            assert.equal(anchor.getAttribute('data-pk'), String(expected.id));
            assert.equal(anchor.getAttribute('data-value'), 'stored');
            assert.equal(anchor.textContent, 'Ordinary');
            if (fixture.dynamic) {
                assert.deepEqual(JSON.parse(anchor.getAttribute('data-source')), source);
                assert.deepEqual(JSON.parse(JSON.stringify($(anchor).data('source'))), source);
            } else assert.equal(anchor.hasAttribute('data-source'), false);
            const open = async current => {
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('X-editable did not finish rendering')), 3000);
                    $(anchor).one('shown.sourceTest', () => { clearTimeout(timer); resolve(); });
                    anchor.click();
                });
                const select = w.document.querySelector('.popover.in select');
                assert.deepEqual(Array.from(select.options).map(option => ({ value: option.value, text: option.textContent })), source);
                assert.equal(select.value, current);
                assert.equal(w.document.querySelectorAll('.popover.in [data-unexpected], .popover.in select b').length, 0);
                return select;
            };
            const initialRequests = requests.length;
            let select = await open('stored');
            select.value = source[1].value;
            w.document.querySelector('.popover.in .editable-cancel').click();
            await waitFor(() => !w.document.querySelector('.popover'));
            assert.equal(requests.length, initialRequests, 'cancel must not submit');
            assert.equal(anchor.textContent, 'Ordinary');
            for (const [current, next] of [['stored', source[1].value], [source[1].value, 'stored']]) {
                select = await open(current);
                select.value = next;
                select.dispatchEvent(new w.Event('change', { bubbles: true }));
                const count = requests.length;
                const form = w.document.querySelector('.popover.in form.editableform');
                form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
                assert.equal(requests.length, count + 1, 'one native submit must emit exactly one save');
                assert.equal(requests[count].data.value, next);
                assert.equal(requests[count].data.name, 'choice');
                assert.equal(requests[count].data.pk, expected.id);
                pending[count].resolve({ status: true, message: 'Saved' });
                await waitFor(() => !w.document.querySelector('.popover'));
                assert.equal(anchor.textContent, source.find(option => option.value === next).text);
            }
            assert.equal(anchor.textContent, 'Ordinary');
        }
        assert.equal(notices.length, fixture.expected.length * 2);
        assert.deepEqual(errors, []);
        process.stdout.write(JSON.stringify({ requests, errors }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
