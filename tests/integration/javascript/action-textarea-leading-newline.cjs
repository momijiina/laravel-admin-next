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
        w.eval(fs.readFileSync(path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        w.eval(fs.readFileSync(path.join(assets, 'bootstrap/js/bootstrap.min.js'), 'utf8'));
        const $ = w.jQuery;
        $.admin = { token: 'synthetic-token' };
        const submissions = [];
        // Execute the emitted submit handler; stop at AJAX before any network I/O.
        $.ajax = options => {
            assert.equal(options.method, 'POST');
            assert.equal(options.contentType, false);
            assert.equal(options.processData, false);
            assert.ok(options.data instanceof w.FormData);
            assert.equal(options.data.getAll('body').length, 1);
            submissions.push(options.data.get('body'));
        };
        w.eval(fixture.script);
        const button = $('.multiline-textarea-action');
        button.trigger('click');
        const fields = w.document.querySelectorAll('textarea');
        assert.equal(fields.length, 1, fixture.label);
        assert.equal(w.document.querySelectorAll('script').length, 0, fixture.label);
        const field = fields[0];
        const visible = $('.modal').hasClass('in');
        $(field.form).trigger('submit');
        // Cancel/reopen and bind the emitted submit handler again without edits.
        $('.modal').modal('hide');
        button.trigger('click');
        $(field.form).trigger('submit');
        assert.equal(submissions.length, 2, fixture.label);
        return {
            value: field.value,
            defaultValue: field.defaultValue,
            submitted: submissions[0],
            reopened: submissions[1],
            visible,
            rows: field.rows,
            placeholder: field.placeholder,
            attribute: field.getAttribute('data-probe'),
        };
    } finally {
        w.close();
    }
});
process.stdout.write(JSON.stringify(results));
