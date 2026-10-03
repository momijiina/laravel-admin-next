'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
const w = dom.window;
try {
    for (const asset of ['plugins/jQuery/jQuery-2.1.4.min.js', 'bootstrap/js/bootstrap.min.js',
        'plugins/iCheck/icheck.min.js', 'plugins/select2/select2.full.min.js']) {
        w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
    }
    const $ = w.jQuery;
    w.eval(fixture.script);
    const $select = $('select[name="choice[]"]');
    assert.equal($select.length, 1);
    assert.ok($select.data('select2'));
    assert.equal($('input[type="hidden"][name="choice[]"]').length, 1);
    assert.equal($select.attr('data-placeholder'), 'Choose values');
    assert.equal($select.attr('data-probe'), 'fixture');
    const values = () => Array.from($select[0].selectedOptions).map(option => option.value);
    const selected = values();
    if (fixture.mode === 'clear-all') {
        const clear = $select.next().find('.select2-selection__clear');
        assert.equal(clear.length, 1);
        clear.trigger('mousedown');
    } else if (fixture.mode === 'remove-last') {
        const remove = $select.next().find('.select2-selection__choice__remove');
        assert.equal(remove.length, 1);
        remove.trigger('click');
    } else if (fixture.mode === 'choose') {
        $select.val(fixture.choice).trigger('change');
    }
    const visibleAfter = values();
    const form = $select[0].form;
    const submitted = new w.FormData(form).getAll('choice[]');
    // Opening and closing the shipped widget must not add a selection.
    $select.select2('open');
    $select.select2('close');
    assert.deepEqual(values(), visibleAfter);
    assert.deepEqual(new w.FormData(form).getAll('choice[]'), submitted);
    process.stdout.write(JSON.stringify({ selected, visibleAfter, submitted }));
} finally {
    w.close();
}
