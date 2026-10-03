'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
const results = fixtures.map(fixture => {
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
    const w = dom.window;
    try {
        for (const asset of ['plugins/jQuery/jQuery-2.1.4.min.js', 'bootstrap/js/bootstrap.min.js', 'plugins/select2/select2.full.min.js']) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        $.admin = { token: 'synthetic-token' };
        const field = w.document.querySelector('select');
        assert.equal(w.document.querySelectorAll('select').length, 1, fixture.label);
        assert.equal(w.document.querySelectorAll('optgroup').length, 0, fixture.label);
        assert.equal(field.getAttribute('data-probe'), 'fixture', fixture.label);
        const selected = () => Array.from(field.selectedOptions, option => option.value);
        const before = selected();
        const submissions = [];
        // Exercise the emitted submit handler, stopping at AJAX before network I/O.
        $.ajax = options => {
            assert.equal(options.method, 'POST');
            assert.equal(options.contentType, false);
            assert.equal(options.processData, false);
            assert.ok(options.data instanceof w.FormData);
            submissions.push(options.data.getAll('choice'));
        };
        w.eval(fixture.script);
        const button = $('.nullable-select-action');
        button.trigger('click');
        assert.equal($('.modal').hasClass('in'), true, fixture.label);
        assert.ok($(field).data('select2'), fixture.label);
        assert.equal($(field).data('select2').options.get('placeholder'), 'Choose status', fixture.label);
        const initialized = selected();
        $(field.form).trigger('submit');
        $('.modal').modal('hide');
        button.trigger('click');
        $(field.form).trigger('submit');
        assert.equal(submissions.length, 2, fixture.label);
        return {
            before,
            initialized,
            submitted: submissions[0],
            reopened: submissions[1],
            options: Array.from(field.options, option => option.value),
        };
    } finally {
        w.close();
    }
});
process.stdout.write(JSON.stringify(results));
