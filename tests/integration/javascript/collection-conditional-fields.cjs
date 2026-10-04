'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
let result;
for (const jquery of ['shipped', 'modern']) {
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://offline.invalid/' });
    const w = dom.window;
    try {
        w.eval(fs.readFileSync(jquery === 'shipped'
            ? path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
        for (const asset of ['bootstrap/js/bootstrap.min.js', 'plugins/iCheck/icheck.min.js', 'plugins/select2/select2.full.min.js']) {
            w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        }
        const $ = w.jQuery;
        w.eval(fixture.script);
        const scalar = ['select', 'radio'].includes(fixture.widget);
        const name = scalar ? 'scalar' : 'choices[]';
        const $controls = $(`[name="${name}"]`).not('[type=hidden]');
        const isSelect = $controls.is('select');
        const selected = () => isSelect ? Array.from($controls[0].selectedOptions).map(option => option.value)
            : $controls.filter(':checked').map(function () { return this.value; }).get();
        const visible = name => !$(`[name="detail_${name}"]`).closest('.cascade-group').hasClass('hide');
        const expectVisible = choices => {
            const expected = {
                has: choices.includes('alpha'), any: choices.some(value => ['0', '2'].includes(value)),
                none: !choices.some(value => ['blocked', 'excluded'].includes(value)), equal: choices.length === 2 && choices.includes('alpha') && choices.includes('2'),
                different: !(choices.length === 2 && choices.includes('alpha') && choices.includes('blocked')),
                escaped: choices.includes(fixture.escaped), empty: false,
            };
            for (const [name, value] of Object.entries(expected)) {
                assert.equal(visible(name), value, `${jquery} ${fixture.widget} ${name}: ${JSON.stringify(choices)}`);
            }
            assert.equal(visible('other'), true, 'independent field must remain visible');
        };
        const choose = choices => {
            if (isSelect) {
                $controls.val(scalar ? choices[0] : choices).trigger('change');
            } else if (['checkbox', 'radio'].includes(fixture.widget)) {
                $controls.iCheck('uncheck');
                $controls.filter(function () { return choices.includes(this.value); }).iCheck('check');
            } else {
                // Use the shipped Button/Card label handler, including repeated toggles.
                $controls.each(function () {
                    if (this.checked !== choices.includes(this.value)) $(this).closest('label').trigger('click');
                });
            }
            assert.deepEqual([...selected()].sort(), [...choices].sort());
            if (!scalar) expectVisible(choices);
        };
        assert.deepEqual([...selected()].sort(), [...fixture.expected].sort());
        const initialSelected = [...selected()];
        if (isSelect) assert.ok($controls.data('select2'));
        else if (['checkbox', 'radio'].includes(fixture.widget)) assert.ok($controls.first().parent().hasClass(fixture.widget === 'radio' ? 'iradio_minimal-blue' : 'icheckbox_minimal-blue'));
        if (scalar) {
            // Preserve legacy scalar startup (equality only), then all existing change operators.
            assert.equal(visible('scalar_equal'), (fixture.scalarInitial ?? fixture.expected[0]) === '0');
            assert.equal(visible('scalar_in'), false);
            assert.equal(visible('scalar_gt'), false);
            for (const value of ['2', '0', "quote'\\"]) {
                choose([value]);
                assert.equal(visible('scalar_equal'), value === '0');
                assert.equal(visible('scalar_in'), value === '2');
                assert.equal(visible('scalar_gt'), value === '2' || value === "quote'\\");
            }
        } else {
            expectVisible(fixture.expected);
            if (fixture.changes) {
                for (const choices of [['alpha'], ['0'], ['2'], ['alpha', '2'], ['blocked', 'alpha'], ['excluded'], [fixture.escaped], [], ['alpha']]) choose(choices);
                if (isSelect) {
                    const clear = $controls.next().find('.select2-selection__clear');
                    assert.equal(clear.length, 1, 'actual shipped Select2 clear-all control');
                    clear.trigger('mousedown');
                    assert.equal($controls.val() === null, jquery === 'shipped');
                    assert.deepEqual(selected(), []);
                    expectVisible([]);
                    choose(['2']);
                    const remove = $controls.next().find('.select2-selection__choice__remove');
                    assert.equal(remove.length, 1);
                    remove.trigger('click');
                    expectVisible([]);
                } else choose([]);
            }
        }
        const submitted = new w.FormData($controls[0].form).getAll(name);
        if (!scalar && !fixture.changes) assert.deepEqual(submitted.filter(value => value !== '').sort(), [...fixture.expected].sort());
        result = { selected: initialSelected.sort((a, b) => fixture.expected.indexOf(a) - fixture.expected.indexOf(b)), submitted };
    } finally {
        w.close();
    }
}
process.stdout.write(JSON.stringify(result));
