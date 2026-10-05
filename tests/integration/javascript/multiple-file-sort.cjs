'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
// jsdom has no picker/DataTransfer UI. Its fixture API supplies a genuine
// FileList without replacing the production fileinput change handler.
const FileList = require('jsdom/lib/generated/idl/FileList.js');
const utils = require('jsdom/lib/generated/idl/utils.js');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.join(fixture.root, 'resources/assets');
const uploads = fixture.uploads || [];
const order = fixture.order === undefined ? [1, 0] : fixture.order;

(async () => {
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
    const w = dom.window;
    try {
        // The shipped plugin uses object URLs for previews. Only this missing
        // jsdom host API is shimmed; the widget and its event handlers are real.
        w.URL.createObjectURL = () => 'blob:https://offline.invalid/preview';
        w.URL.revokeObjectURL = () => {};
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        for (const asset of [
            'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
            'AdminLTE/bootstrap/js/bootstrap.min.js',
            'AdminLTE/plugins/iCheck/icheck.min.js',
            'bootstrap-fileinput/js/plugins/sortable.min.js',
            'bootstrap-fileinput/js/fileinput.min.js',
        ]) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        const ajax = [];
        $.ajax = options => {
            ajax.push(options.url);
            return $.Deferred().promise();
        };
        const scripts = w.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        for (const script of scripts.querySelectorAll('script')) w.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('production ready handler did not finish')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });

        const inputs = w.document.querySelectorAll('input[type="file"][multiple]');
        assert.equal(inputs.length, 1, 'fixture must render exactly one multiple-file input');
        const input = inputs[0];
        const markers = input.form.querySelectorAll('input[name^="_file_sort_["]');
        assert.equal(markers.length, 1, 'production must render its sort marker');
        const hidden = markers[0];
        const plugin = $(input).data('fileinput');
        assert.ok(plugin, 'shipped fileinput must initialize on the rendered input');
        assert.equal(hidden.value, '', 'an unchanged form must start with an empty sort marker');
        const plain = value => JSON.parse(JSON.stringify(value));
        const initialKeys = plain(plugin.initialPreviewConfig.map(item => item.key));
        const initialPreview = plain(plugin.initialPreview);
        const frames = Array.from(plugin.getFrames('.file-preview-initial'));
        assert.equal(frames.length, initialKeys.length);
        assert.deepEqual(initialKeys, frames.map((_, index) => index), 'saved previews must retain their original numeric keys');
        const events = [];
        $(input).on('filesorted.multipleFileSortFixture', (event, params) => {
            events.push({ oldIndex: params.oldIndex, newIndex: params.newIndex, keys: plain(params.stack.map(item => item.key)) });
        });

        if (order !== null) {
            assert.ok(Array.isArray(order) && order.length > 0, 'use order:null to leave a form unsorted');
            assert.deepEqual([...order].sort((a, b) => a - b), initialKeys, 'order must be a permutation of all saved preview indices');
            const sortable = plugin.$preview.data('kvsortable');
            assert.ok(sortable, 'shipped KvSortable must initialize for saved previews');
            const onSort = sortable.option('onSort');
            assert.equal(typeof onSort, 'function');
            for (const [newIndex, originalIndex] of order.entries()) {
                const current = Array.from(plugin.getFrames('.file-preview-initial'));
                const item = frames[originalIndex];
                const oldIndex = current.indexOf(item);
                if (oldIndex === newIndex) continue;
                // jsdom has no physical drag layout. Move the actual preview
                // nodes and enter the shipped Sortable onSort callback, which
                // updates plugin state and raises the real filesorted event.
                item.parentNode.insertBefore(item, current[newIndex]);
                onSort({ oldIndex, newIndex, item });
            }
            // An explicit identity permutation still exercises a sort event.
            // order:null is the separate unchanged/no-sort control.
            if (events.length === 0) onSort({ oldIndex: 0, newIndex: 0, item: frames[0] });
            assert.deepEqual(events.at(-1).keys, order);
            assert.deepEqual(Array.from(plugin.getFrames('.file-preview-initial')), order.map(index => frames[index]));
        }
        const expectedKeys = order === null ? initialKeys : order;
        const expectedSort = order === null ? '' : order.join(',');
        const assertSortState = () => {
            assert.equal(hidden.value, expectedSort, 'production filesorted listener must serialize the requested saved-file order');
            assert.deepEqual(plain(plugin.initialPreviewConfig.map(item => item.key)), expectedKeys);
            assert.deepEqual(plain(plugin.initialPreview), expectedKeys.map(index => initialPreview[index]));
        };
        assertSortState();

        const readBytes = file => new Promise((resolve, reject) => {
            const reader = new w.FileReader();
            reader.onload = () => resolve(Buffer.from(new Uint8Array(reader.result)).toString('base64'));
            reader.onerror = () => reject(reader.error);
            reader.readAsArrayBuffer(file);
        });
        const describeFile = async file => ({ name: file.name, type: file.type, size: file.size, bytes: await readBytes(file) });
        const expectedFiles = uploads.map(file => ({
            name: file.name, type: file.type, size: Buffer.from(file.bytes, 'base64').length, bytes: file.bytes,
        }));
        const loadedFiles = [];
        if (uploads.length) {
            const files = FileList.create(w);
            for (const file of uploads) {
                const nativeFile = new w.File([Uint8Array.from(Buffer.from(file.bytes, 'base64'))], file.name, { type: file.type });
                utils.implForWrapper(files).push(utils.implForWrapper(nativeFile));
            }
            input.files = files;
            assert.ok(input.files instanceof w.FileList);
            assert.equal(input.files.length, uploads.length);
            const loaded = new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('fileinput did not load every selected file')), 5000);
                $(input).on('fileloaded.multipleFileSortFixture', (event, file) => {
                    loadedFiles.push(file.name);
                    if (loadedFiles.length === uploads.length) {
                        clearTimeout(timer);
                        resolve();
                    }
                });
            });
            input.dispatchEvent(new w.Event('change', { bubbles: true }));
            await loaded;
            assert.deepEqual(loadedFiles, uploads.map(file => file.name), 'the actual plugin must load each selected file');
        }
        assertSortState();
        assert.deepEqual(await Promise.all(Array.from(input.files, describeFile)), expectedFiles, 'sorting must retain every native selected file and its exact bytes');
        const pluginFiles = await Promise.all(Array.from(plugin.getFileStack(), describeFile));
        assert.deepEqual(pluginFiles, expectedFiles, 'the shipped fileinput stack must retain every selected file');

        const entries = [];
        for (const [name, value] of new w.FormData(input.form).entries()) {
            if (value instanceof w.File) {
                // FormData includes an empty pseudo-file for an unselected
                // input. PHP omits that UPLOAD_ERR_NO_FILE entry from files.
                if (value.name === '' && value.size === 0) continue;
                entries.push([name, await describeFile(value)]);
            } else {
                entries.push([name, value]);
            }
        }
        assert.deepEqual(entries.filter(([name]) => name === input.name), expectedFiles.map(file => [input.name, file]),
            'native multipart FormData must contain exactly the selected files and their own bytes');
        assert.deepEqual(entries.filter(([name]) => name === hidden.name), [[hidden.name, expectedSort]]);
        assert.deepEqual(errors, [], 'production initialization, sorting and file selection must not throw');
        assert.deepEqual(ajax, [], 'ordinary multipart sorting and selection must not issue AJAX');
        process.stdout.write(JSON.stringify({
            jquery: $.fn.jquery,
            entries, events, pluginFiles, loadedFiles, initialPreviewKeys: expectedKeys,
            sortValue: hidden.value, ajax, errors,
        }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
