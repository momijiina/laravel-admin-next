'use strict';

// Offline jsdom regression: real Blade, unmodified emitted JavaScript, real jQuery.
// This does not establish native browser layout, PJAX, or third-party widget behavior.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const jquery = require('jquery');
const fixtures = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
const shipped = fs.readFileSync(path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');
const bootstrap = fs.readFileSync(path.join(assets, 'bootstrap/js/bootstrap.min.js'), 'utf8');
const result = {jquery: [], assertions: 0, snapshots: []};
const eq = (actual, expected, message) => { result.assertions++; assert.deepEqual(actual, expected, message); };
const ok = (value, message) => { result.assertions++; assert.ok(value, message); };

for (const version of ['2.1.4', '3.7.1']) {
    result.jquery.push(version);
    for (const [index, fixture] of fixtures.entries()) {
        const dom = new JSDOM('<form id="fixture">' + fixture.html + '</form>', {
            runScripts: 'outside-only', url: 'https://collection.test/',
        });
        const window = dom.window;
        try {
            if (version === '2.1.4') window.eval(shipped);
            else window.$ = window.jQuery = jquery(window);
            const $ = window.$;
            eq($.fn.jquery, version, 'actual intended jQuery runtime');
            window.eval(bootstrap);
            const run = scripts => scripts.forEach(script => window.eval(script));
            const roots = () => Array.from(window.document.querySelectorAll('[data-admin-collection]'));
            const own = (root, selector) => Array.from(root.querySelectorAll(selector))
                .filter(element => element.closest('[data-admin-collection]') === root);
            const rows = root => Array.from(own(root, '[data-collection-body]')[0].children);
            const visible = root => own(root, '[data-collection-body] input');
            const metadata = new Map();
            const consumer = new Map();
            const serialize = root => $('<form>').append($(root).clone()).serialize();
            const register = (root, expected) => {
                metadata.set(root, expected);
                const calls = {plain: 0, namespaced: 0, remove: 0};
                consumer.set(root, calls);
                $(root).on('click', '[data-collection-add]', () => calls.plain++);
                $(root).on('click.consumer', '[data-collection-add]', () => calls.namespaced++);
                $(own(root, '[data-collection-body]')[0]).on('click.consumer', '[data-collection-remove]', () => calls.remove++);
            };
            eq(roots().length, fixture.roots.length, 'expected production-rendered roots');
            roots().forEach((root, number) => register(root, fixture.roots[number]));
            const check = root => {
                const expected = metadata.get(root);
                const locked = expected.disabled || expected.readonly;
                eq(root.hasAttribute('data-collection-locked'), locked, 'root lock by attribute presence');
                const template = Array.from(root.children).find(element => element.tagName === 'TEMPLATE');
                ok(template, 'real inert row template');
                const parts = expected.type === 'list' ? ['values'] : ['keys', 'values'];
                const controls = [...visible(root), ...template.content.querySelectorAll('input')];
                for (const input of controls) {
                    eq(input.disabled, expected.disabled, 'live and template native disabled state');
                    eq(input.readOnly, expected.readonly, 'live and template native readonly state');
                    ok(parts.some(part => input.name === expected.base + '[' + part + '][]'), 'scoped repeated control name');
                }
                eq(template.content.querySelectorAll('input[type=hidden]').length, 0, 'no template markers');
                const markers = own(root, 'input[type=hidden]');
                eq(markers.map(input => input.name), parts.map(part => expected.base + '[' + part + ']'), 'exact scoped marker names');
                for (const marker of markers) {
                    eq(marker.disabled, expected.disabled, 'markers stay enabled for readonly and unsupported disable()');
                    eq(marker.value, '', 'empty marker value');
                    ok(!own(root, '[data-collection-body]')[0].contains(marker), 'marker outside removable rows');
                    for (const input of visible(root)) {
                        ok(marker.compareDocumentPosition(input) & window.Node.DOCUMENT_POSITION_FOLLOWING, 'markers precede row controls');
                    }
                }
                for (const control of [...own(root, '[data-collection-add], [data-collection-remove]'),
                    ...template.content.querySelectorAll('[data-collection-remove]')]) {
                    eq(control.classList.contains('disabled'), locked, 'locked affordance disabled styling');
                    eq(control.getAttribute('aria-disabled'), locked ? 'true' : null, 'locked affordance accessibility state');
                }
                const values = $('<form>').append($(root).clone()).serializeArray();
                {
                    eq(values.length, markers.length + visible(root).length, 'readonly/normal values included; inert template omitted');
                    for (const marker of markers) {
                        const part = Array.from(values).filter(input => input.name === marker.name || input.name === marker.name + '[]');
                        eq(part.map(input => [input.name, input.value]), [[marker.name, ''],
                            ...visible(root).filter(input => input.name === marker.name + '[]').map(input => [input.name, input.value])],
                        'successful marker before original row values, including zero/blank');
                    }
                }
            };
            const exercise = root => {
                const expected = metadata.get(root);
                const locked = expected.disabled || expected.readonly;
                const before = roots().map(item => rows(item).length);
                const number = roots().indexOf(root);
                const original = serialize(root);
                const calls = {...consumer.get(root)};
                $(own(root, '[data-collection-add]')[0]).find('i').trigger('click');
                eq(roots().map(item => rows(item).length), before.map((count, position) => count + (!locked && number === position ? 1 : 0)), 'add affects only enabled owning root once');
                eq(consumer.get(root).plain, calls.plain + 1, 'ordinary consumer handler retained');
                eq(consumer.get(root).namespaced, calls.namespaced + 1, 'namespaced consumer handler retained');
                if (rows(root).length) {
                    $(rows(root).at(-1).querySelector('[data-collection-remove] i')).trigger('click');
                    eq(consumer.get(root).remove, calls.remove + 1, 'consumer tbody handler retained');
                }
                eq(roots().map(item => rows(item).length), before, 'remove guard or enabled removal restores exact root counts');
                eq(serialize(root), original, 'clicks preserve original values after balanced enabled edits');
                check(root);
            };
            run(fixture.scripts);
            roots().forEach(check);
            // Registering consumers before initialization catches broad off() calls.
            roots().forEach(exercise);
            run(fixture.collectionScripts || fixture.scripts);
            run(fixture.collectionScripts || fixture.scripts);
            roots().forEach(exercise);
            if (fixture.captureOriginal) result.snapshots.push({fixture: index, query: $('#fixture').serialize()});
            if (['default', 'tab', 'table'].includes(fixture.shape)) {
                for (let insertion = 1; insertion <= 2; insertion++) {
                    const before = roots().length;
                    $('#has-many-children .add').trigger('click');
                    eq(roots().length, before + 2, 'actual HasMany script inserts both locked and open child fields');
                    roots().slice(-2).forEach((root, number) => register(root, {
                        ...fixture.roots[number], base: 'children[new_' + insertion + '][' + (number ? 'open' : 'items') + ']',
                    }));
                    roots().forEach(check);
                    roots().forEach(exercise);
                }
            }
            if (!fixture.shape) {
                const root = roots()[0];
                const queries = {original: serialize(root)};
                // Negative control deliberately re-enables markers while disabling
                // visible controls: externally disabling rows alone still clears storage.
                const copy = $(root).clone();
                copy.find('input[type=hidden]').prop('disabled', false);
                copy.find('[data-collection-body] input').prop('disabled', true);
                queries.visibleOnlyDisabled = $('<form>').append(copy).serialize();
                if (!metadata.get(root).disabled && !metadata.get(root).readonly) {
                    while (rows(root).length) $(rows(root)[0].querySelector('[data-collection-remove]')).trigger('click');
                    check(root);
                    queries.cleared = serialize(root);
                }
                result.snapshots.push({fixture: index, queries});
            }
        } catch (error) {
            error.message = fixture.label + ' / jQuery ' + version + ': ' + error.message;
            throw error;
        } finally {
            window.close();
        }
    }
}
process.stdout.write(JSON.stringify(result));
