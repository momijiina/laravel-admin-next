'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {JSDOM, VirtualConsole} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const logs = [];
const console = new VirtualConsole();
for (const event of ['jsdomError', 'error', 'warn']) {
    console.on(event, error => logs.push({event, message: String(error)}));
}
const dom = new JSDOM(input.fixture.html, {runScripts: 'outside-only', virtualConsole: console});
const window = dom.window;
try {
    const asset = file => window.eval(fs.readFileSync(path.join(input.assets, file), 'utf8'));
    if (input.jquery === 'modern') {
        window.eval(fs.readFileSync(require.resolve('jquery'), 'utf8'));
    } else {
        asset('AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js');
    }
    const $ = window.jQuery;
    let result;
    if (input.mode === 'file') {
        let calls = 0;
        let options;
        $.fn.fileinput = function (value) {
            ++calls;
            assert.equal(this.length, 1);
            assert.equal(this[0].name, 'document');
            options = value;
            return this;
        };
        window.eval(input.fixture.script);
        assert.ok(options, 'Production File initializer must call fileinput');
        const { _token: token, ...deleteData } = options.deleteExtraData;
        const element = window.document.querySelector('input[name="document"]');
        result = {
            calls, callbacks: [options.ajaxSettings.success(), options.ajaxDeleteSettings.success()],
            options: [options.ajaxSettings.timeout, options.ajaxDeleteSettings.cache],
            metadata: options.metadata,
            defaults: [options.overwriteInitial, options.initialPreviewAsData, options.showUpload,
                options.showCancel, options.showRemove, options.dropZoneEnabled],
            token, deleteData, element: {type: element.type, name: element.name},
        };
    } else {
        asset('AdminLTE/plugins/input-mask/jquery.inputmask.bundle.min.js');
        window.eval(input.fixture.script);
        const element = window.document.querySelector('input[name="code"]');
        assert.ok(element.inputmask, 'Production Text/Mobile initializer must attach the shipped Inputmask');
        const options = element.inputmask.opts;
        const values = input.mode === 'callbacks' ? ['A1', 'AB', 'B2'] : ['1234'];
        const states = target => values.map(value => {
            $(target).val(value).trigger('setvalue');
            return {input: value, value: target.value,
                native: Object.fromEntries(new window.FormData(target.form)),
                complete: $(target).inputmask('isComplete')};
        });
        result = {states: states(element), metadata: options.metadata};
        if (input.mode === 'callbacks') {
            result.validators = [options.definitions.X.validator('A'), options.definitions.X.validator('1'),
                options.definitions.Y.validator('A'), options.definitions.Y.validator('1')];
            // Direct same-widget configuration is independent of the PHP serializer.
            const form = window.document.createElement('form');
            const control = window.document.createElement('input');
            control.name = 'code';
            form.appendChild(control);
            window.document.body.appendChild(form);
            $(control).inputmask({mask: 'XY', definitions: {
                X: {validator: function(chrs){return /^[A-Z]$/.test(chrs);}, cardinality: 1},
                Y: {validator: function(chrs){return /^[0-9]$/.test(chrs);}, cardinality: 1},
            }});
            result.control = states(control);
        } else {
            result.options = [options.onBeforeMask, options.repeat, options.clearIncomplete, options.showMaskOnHover];
        }
    }
    process.stdout.write(JSON.stringify({...result, logs}));
} finally {
    window.close();
}
