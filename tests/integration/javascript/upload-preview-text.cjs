'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM, VirtualConsole} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');
const errors = [];
const virtualConsole = new VirtualConsole();
for (const event of ['jsdomError', 'error', 'warn']) {
    virtualConsole.on(event, error => errors.push(String(error)));
}
const dom = new JSDOM(fixture.html, {
    runScripts: 'outside-only', url: 'https://offline.invalid/', virtualConsole,
});
const window = dom.window;

try {
    window.addEventListener('error', event => errors.push(String(event.error || event.message)));
    // jsdom has no object-URL preview API. The plugin, templates, Bootstrap
    // modal, and all field initialization/event handlers are the shipped code.
    window.URL.createObjectURL = () => 'blob:https://offline.invalid/preview';
    window.URL.revokeObjectURL = () => {};
    window.eval(fs.readFileSync(fixture.jquery === 'modern'
        ? require.resolve('jquery')
        : path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'bootstrap-fileinput/js/fileinput.min.js']) {
        window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
    }
    window.eval(fixture.script);
    const $ = window.jQuery;
    const input = window.document.querySelector('input[type="file"]');
    assert.ok(input);
    const plugin = $(input).data('fileinput');
    assert.ok(plugin, 'the production initializer must attach the shipped fileinput');
    const frames = Array.from(plugin.getFrames('.file-preview-initial'));
    const previews = frames.map((frame, index) => {
        const caption = frame.querySelector('.file-caption-info');
        const title = frame.querySelector('.file-footer-caption');
        const download = frame.querySelector('.kv-file-download');
        const remove = frame.querySelector('.kv-file-remove');
        const config = plugin.initialPreviewConfig[index] || {};
        const asData = Object.hasOwn(config, 'previewAsData') ? config.previewAsData : plugin.initialPreviewAsData;
        const type = asData ? frame.getAttribute('data-template') : 'generic';
        let content = null;
        if (type === 'image') content = frame.querySelector('img')?.getAttribute('src') ?? null;
        if (type === 'text') content = frame.querySelector('textarea.file-preview-text')?.value ?? null;
        if (type === 'html') content = frame.querySelector('.file-preview-html')?.textContent ?? null;
        if (type === 'pdf') content = frame.querySelector('embed')?.getAttribute('src') ?? null;
        if (type === 'audio' || type === 'video') content = frame.querySelector('source')?.getAttribute('src') ?? null;
        if (type === 'generic') content = frame.querySelector('.kv-file-content').textContent.trim();
        $(frame).find('.kv-file-zoom').trigger('click');
        const zoom = window.document.querySelector('.kv-zoom-title');
        assert.ok(zoom, 'the native zoom button must open the shipped Bootstrap modal');
        const result = {
            caption: caption.textContent, title: title.getAttribute('title'),
            captionChildren: caption.children.length,
            markerNodes: frame.querySelectorAll('[data-preview-marker]').length,
            zoomCaption: zoom.textContent.trim(), zoomTitle: zoom.getAttribute('title').trim(),
            zoomMarkerNodes: plugin.$modal[0].querySelectorAll('[data-preview-marker]').length,
            key: plugin.initialPreviewConfig[index].key,
            removeKey: remove ? remove.getAttribute('data-key') : null,
            type, content,
            downloadUrl: download ? download.getAttribute('href') : null,
            downloadName: download ? download.getAttribute('download') : null,
        };
        plugin.$modal.modal('hide');
        return result;
    });
    const {_token, ...deleteData} = plugin.deleteExtraData;
    process.stdout.write(JSON.stringify({
        errors, previews, inputCaption: plugin.$caption.val(),
        initialPreview: plugin.initialPreview, config: plugin.initialPreviewConfig,
        defaults: [plugin.initialPreviewAsData, plugin.showUpload, plugin.showCancel, plugin.showRemove, plugin.dropZoneEnabled],
        deleteData,
    }));
} finally {
    window.close();
}
