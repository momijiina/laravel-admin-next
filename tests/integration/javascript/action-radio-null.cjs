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
        for (const asset of ['plugins/jQuery/jQuery-2.1.4.min.js', 'bootstrap/js/bootstrap.min.js', 'plugins/iCheck/icheck.min.js']) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        $.admin = { token: 'synthetic-token' };
        const fields = Array.from(w.document.querySelectorAll('input[type="radio"]'));
        assert.equal(fields.length, fixture.count, fixture.label);
        assert.ok(fields.every(field => field.name === 'choice' && field.getAttribute('data-probe') === 'fixture'));
        assert.equal(w.document.querySelectorAll('script, .radio-inline b').length, 0);
        const selected = () => fields.filter(field => field.checked).map(field => field.value);
        const initial = selected();
        const submissions = [];
        $.ajax = options => {
            assert.equal(options.method, 'POST');
            assert.equal(options.contentType, false);
            assert.equal(options.processData, false);
            assert.ok(options.data instanceof w.FormData);
            submissions.push(options.data.getAll('choice'));
        };
        w.eval(fixture.script);
        const button = $('.nullable-radio-action');
        button.trigger('click');
        assert.ok($('.modal').hasClass('in'));
        assert.equal(w.document.querySelectorAll('.iradio_minimal-blue').length, fields.length);
        const initialized = selected();
        const form = fields[0].form;
        const valid = form.checkValidity();
        // Deliberately invoke the emitted handler even for invalid required fixtures.
        // Native validity and FormData omission are distinct contracts.
        $(form).trigger('submit');
        $('.modal').modal('hide');
        button.trigger('click');
        assert.ok($('.modal').hasClass('in'));
        $(form).trigger('submit');
        assert.equal(submissions.length, 2, fixture.label);
        return { initial, initialized, submitted: submissions[0], reopened: submissions[1], valid };
    } finally {
        w.close();
    }
});
process.stdout.write(JSON.stringify(results));
