'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
// jsdom has no picker/DataTransfer UI. Its fixture API supplies a genuine
// FileList, without replacing production input handlers or FileList methods.
const FileList = require('jsdom/lib/generated/idl/FileList.js');
const utils = require('jsdom/lib/generated/idl/utils.js');
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
        w.eval(fs.readFileSync(path.join(assets, 'AdminLTE/plugins/iCheck/icheck.min.js'), 'utf8'));
        const $ = w.jQuery;
        w.LA = { token: 'test-token' };
        const notices = [];
        let reloads = 0;
        w.toastr = { success: message => notices.push(message) };
        $.admin = { reload: () => reloads++ };
        w.console.info = () => {};
        const inputs = Array.from(w.document.querySelectorAll('input.inline-upload'));
        const triggers = Array.from(w.document.querySelectorAll('.inline-upload-trigger'));
        assert.equal(inputs.length, fixture.cells.length, 'actual Grid must render all expected upload cells');
        assert.equal(triggers.length, inputs.length);
        const inputIds = inputs.map(input => input.id);
        assert.equal(new Set(inputIds).size, inputs.length, 'every rendered cell needs a distinct target, including repeated Grid instances');
        const consumerClicks = inputs.map(() => 0);
        const consumerInputClicks = inputs.map(() => 0);
        const consumerChanges = inputs.map(() => 0);
        const pickerClicks = [];
        for (const [index, input] of inputs.entries()) {
            assert.ok(input.id);
            assert.equal(triggers[index].dataset.target, input.id);
            assert.equal(input.dataset.key, String(fixture.cells[index].key));
            assert.equal(triggers[index].textContent.trim(), fixture.cells[index].label);
            assert.equal(input.multiple, false, 'this regression covers ordinary single-file upload only');
            assert.ok(input.files instanceof w.FileList);
            assert.equal(input.files.length, 0);
            $(triggers[index]).on('click.consumer', () => consumerClicks[index]++);
            $(input).on('click.consumer', () => consumerInputClicks[index]++);
            $(input).on('change.consumer', () => consumerChanges[index]++);
            input.addEventListener('click', event => {
                event.preventDefault();
                pickerClicks.push(index);
            });
        }
        const requests = [];
        const callbacks = [];
        $.ajax = options => {
            assert.ok(options.data instanceof w.FormData, 'production must emit native multipart FormData');
            requests.push(options);
            callbacks.push(options.success);
        };
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        const initialize = async () => {
            for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
            await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
                $(function () { clearTimeout(timer); resolve(); });
            });
            assert.deepEqual(errors, [], 'actual Grid ready scripts must not throw');
            assert.equal(w.inlineUploadBefore, true);
            assert.equal(w.inlineUploadAfter, true);
        };
        // Repeat the actual ready-wrapped output, with consumer handlers already
        // installed. A broad off('click') / off('change') would erase them.
        for (let run = 1; run <= 2; run++) {
            await initialize();
            for (const [index, trigger] of triggers.entries()) {
                const before = pickerClicks.length;
                trigger.click();
                assert.deepEqual(pickerClicks.slice(before), [index], 'a trigger must activate only its own input, exactly once');
                assert.equal(consumerClicks[index], run, 'consumer trigger click handlers must survive initialization');
                assert.equal(consumerInputClicks[index], run, 'consumer input click handlers must survive initialization');
                assert.equal(inputs[index].files.length, 0);
                // Model a canceled picker by dispatching no change event. This
                // does not exercise an OS picker or an empty-FileList change.
                assert.equal(requests.length, 0, 'opening/canceling without change must not issue AJAX');
                assert.deepEqual(consumerChanges, inputs.map(() => 0));
            }
        }
        const readBytes = file => new Promise((resolve, reject) => {
            const reader = new w.FileReader();
            reader.onload = () => resolve(Buffer.from(new Uint8Array(reader.result)).toString('base64'));
            reader.onerror = () => reject(reader.error);
            reader.readAsArrayBuffer(file);
        });
        const serialized = [];
        if (!fixture.inspectOnly) {
            for (const [index, input] of inputs.entries()) {
                const cell = fixture.cells[index];
                const file = new w.File([Uint8Array.from(Buffer.from(cell.file.bytes, 'base64'))], cell.file.name, {
                    type: cell.file.type,
                });
                const files = FileList.create(w);
                utils.implForWrapper(files).push(utils.implForWrapper(file));
                input.files = files;
                assert.ok(input.files instanceof w.FileList);
                assert.equal(input.files.length, 1);
                assert.equal(input.files[0], file);
                input.dispatchEvent(new w.Event('change', { bubbles: true }));
                assert.equal(requests.length, index + 1, 'one native change must produce only its own field/resource request');
                assert.deepEqual(consumerChanges, inputs.map((_, other) => other <= index ? 1 : 0),
                    'consumer change handlers must remain bound only to their own input');
                const request = requests[index];
                const entries = [];
                for (const [name, value] of request.data.entries()) {
                    entries.push([name, value instanceof w.File ? {
                        name: value.name, type: value.type, bytes: await readBytes(value), size: value.size,
                    } : value]);
                }
                assert.deepEqual(entries, [
                    [cell.field, { ...cell.file, size: Buffer.from(cell.file.bytes, 'base64').length }],
                    ['_token', 'test-token'], ['_method', 'PUT'],
                ], 'the multipart body must contain only the intended field and original selected bytes');
                serialized.push({
                    url: request.url, type: request.type, processData: request.processData,
                    contentType: request.contentType, enctype: request.enctype, entries,
                });
                assert.equal(request.url, cell.url);
                assert.equal(request.type, 'POST');
                assert.equal(request.processData, false);
                assert.equal(request.contentType, false);
                assert.equal(request.enctype, 'multipart/form-data');
                assert.equal(reloads, fixture.responses ? index : 0);
                if (fixture.responses) {
                    callbacks[index](fixture.responses[index]);
                    assert.equal(reloads, index + 1, 'successful upload must retain the existing reload behavior');
                    assert.deepEqual(notices, fixture.responses.slice(0, index + 1).map(response => response.message));
                }
            }
        }
        assert.deepEqual(errors, [], 'native clicks and changes must not throw in production scripts');
        process.stdout.write(JSON.stringify({ requests: serialized, inputIds, reloads, notices }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
