'use strict';

// The fixture contains real Blade output and unmodified Admin::$script strings.
// No collection implementation or expected replacement script lives in this test.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const jquery = require('jquery');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');
const shippedJquery = fs.readFileSync(path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');
const bootstrap = fs.readFileSync(path.join(assets, 'AdminLTE/bootstrap/js/bootstrap.min.js'), 'utf8');
const result = {jquery: [], assertions: 0, serializations: []};
const eq = (actual, expected, message) => {
    result.assertions++;
    assert.deepEqual(actual, expected, message);
};
const ok = (condition, message) => {
    result.assertions++;
    assert.ok(condition, message);
};

for (const version of ['2.1.4', '3.7.1']) {
    const dom = new JSDOM('<form id="collection-form"><div id="pjax-container">' + fixture.html + '</div></form>', {
        runScripts: 'outside-only',
        url: 'https://collection.test/',
    });
    const window = dom.window;
    try {
        if (version === '2.1.4') {
            window.eval(shippedJquery);
        } else {
            window.$ = window.jQuery = jquery(window);
        }
        const $ = window.$;
        eq($.fn.jquery, version, 'the intended real jQuery runtime is loaded');
        result.jquery.push(version);
        // HasMany tab mode uses the shipped Bootstrap tab implementation, not a stub.
        window.eval(bootstrap);
        const run = scripts => scripts.forEach(script => window.eval(script));
        const roots = () => Array.from(window.document.querySelectorAll('#pjax-container [data-admin-collection]'));
        const own = (root, selector) => Array.from(root.querySelectorAll(selector))
            .filter(element => element.closest('[data-admin-collection]') === root);
        const body = root => {
            const bodies = own(root, '[data-collection-body]');
            eq(bodies.length, 1, 'one owning collection body');
            return bodies[0];
        };
        const rows = root => Array.from(body(root).children);
        const counts = () => roots().map(root => rows(root).length);
        const expected = new Map();
        const consumers = new Map();
        const parts = root => expected.get(root).type === 'list' ? ['values'] : ['keys', 'values'];
        const inputNames = root => parts(root).map(part => expected.get(root).base + '[' + part + '][]');
        const snapshot = root => {
            // A real form's successful-control serialization excludes inert templates.
            const form = $('<form>').append($(root).clone());
            // Only nested-guard fixtures have physically nested collection roots.
            form.find('[data-admin-collection]').slice(1).remove();
            return Array.from(form.serializeArray(), input => ({name: input.name, value: input.value}));
        };
        const register = (root, metadata) => {
            expected.set(root, metadata);
            const calls = {plain: 0, namespaced: 0, body: 0};
            consumers.set(root, calls);
            // Reinitialization must preserve both ordinary and separately namespaced
            // consumer handlers, as well as listeners on unrelated table bodies.
            $(root).on('click', '[data-collection-add]', function () {
                if (this.closest('[data-admin-collection]') === root) calls.plain++;
            });
            $(root).on('click.consumer', '[data-collection-add]', function () {
                if (this.closest('[data-admin-collection]') === root) calls.namespaced++;
            });
            $(body(root)).on('click.consumer', '[data-collection-remove]', () => calls.body++);
        };
        const check = () => {
            for (const root of roots()) {
                const metadata = expected.get(root);
                ok(metadata, 'every live root is expected');
                eq(root.getAttribute('data-admin-collection'), metadata.type, 'field type marker');
                const templates = Array.from(root.children).filter(element => element.tagName === 'TEMPLATE');
                eq(templates.length, 1, 'template remains a direct child of its collection');
                eq(templates[0].className, metadata.column + '-tpl', 'legacy template class is retained');
                eq(body(root).className, (metadata.type === 'list' ? 'list-' : 'kv-') + metadata.column + '-table', 'legacy body class is retained');
                eq(own(root, '[data-collection-add]').length, 1, 'one local add control');
                ok(own(root, '[data-collection-add]')[0].classList.contains(metadata.column + '-add'), 'legacy add class is retained');
                const templateInputs = Array.from(templates[0].content.querySelectorAll('input'));
                eq(templateInputs.map(input => input.name), inputNames(root), 'template has this exact scoped field name');
                eq(templates[0].content.querySelectorAll('[data-collection-remove]').length, 1, 'template remove role');
                eq(templates[0].content.querySelectorAll('input[type=hidden]').length, 0, 'empty markers are never duplicated inside templates');
                for (const row of rows(root)) {
                    eq(row.tagName, 'TR', 'only collection rows are appended');
                    eq(Array.from(row.querySelectorAll('input')).map(input => input.name), inputNames(root), 'each row keeps its owner names');
                    eq(row.querySelectorAll('[data-collection-remove]').length, 1, 'one local remove control per row');
                    ok(row.querySelector('[data-collection-remove]').classList.contains(metadata.column + '-remove'), 'legacy remove class is retained');
                }
                const markers = own(root, 'input[type=hidden]');
                eq(markers.length, parts(root).length, 'one explicit empty marker for each envelope part');
                eq(markers.map(marker => marker.name), parts(root).map(part => metadata.base + '[' + part + ']'), 'marker names stay scoped');
                const serialized = snapshot(root);
                for (const marker of markers) {
                    eq(marker.value, '', 'marker value remains empty');
                    ok(!body(root).contains(marker), 'marker remains outside the removable body');
                    ok(!marker.closest('tr') || !root.contains(marker.closest('tr')), 'marker is not in a collection row');
                    ok(!marker.closest('template'), 'marker remains outside inert templates');
                    for (const input of own(root, '[data-collection-body] input')) {
                        ok(marker.compareDocumentPosition(input) & window.Node.DOCUMENT_POSITION_FOLLOWING, 'markers precede every real row in DOM order');
                    }
                    const serializedPart = serialized.filter(input => input.name === marker.name || input.name === marker.name + '[]');
                    eq(serializedPart[0], {name: marker.name, value: ''}, 'marker serializes before row arrays');
                    eq(serializedPart.slice(1).map(input => input.value),
                        own(root, '[data-collection-body] input').filter(input => input.name === marker.name + '[]').map(input => input.value),
                        'successful controls serialize in row order');
                }
                eq(serialized.length, parts(root).length * (rows(root).length + 1), 'inert template inputs do not serialize');
            }
        };
        const add = index => {
            const root = roots()[index];
            const before = counts();
            const consumerBefore = {...consumers.get(root)};
            // Click the icon to cover event bubbling from a descendant of the control.
            $(own(root, '[data-collection-add]')[0]).find('i').trigger('click');
            eq(counts(), before.map((count, position) => count + (position === index ? 1 : 0)), 'one click adds exactly one row to its owning root');
            eq(consumers.get(root).plain, consumerBefore.plain + 1, 'ordinary consumer handler survives initialization');
            eq(consumers.get(root).namespaced, consumerBefore.namespaced + 1, 'separately namespaced consumer handler survives initialization');
            eq(Array.from(rows(root).at(-1).querySelectorAll('input')).map(input => input.value), parts(root).map(() => ''), 'new row starts empty');
            check();
        };
        const remove = (index, row = rows(roots()[index]).at(-1)) => {
            const root = roots()[index];
            const before = counts();
            const consumerBefore = consumers.get(root).body;
            $(row.querySelector('[data-collection-remove] i')).trigger('click');
            eq(counts(), before.map((count, position) => count - (position === index ? 1 : 0)), 'one click removes only its own collection row');
            eq(consumers.get(root).body, consumerBefore + 1, 'consumer tbody handler still receives removal clicks');
            ok(!root.contains(row), 'the clicked row was actually removed');
            check();
        };
        const registerInitial = () => {
            eq(roots().length, fixture.roots.length, 'real Blade fixture root count');
            roots().forEach((root, index) => register(root, fixture.roots[index]));
            eq(counts(), fixture.roots.map(() => 2), 'two populated rows per initial root');
        };

        registerInitial();
        run(fixture.scripts);
        check();

        if (fixture.shape === 'nested-guard') {
            const [outer, inner] = roots();
            // Synthetic nesting of two unchanged, production-rendered roots probes
            // event bubbling and tbody ownership, not a new nested-field API.
            outer.insertBefore(inner, outer.firstChild);
            run(fixture.collectionScripts);
            add(1);
            remove(1);
            add(0);
            remove(0);
            run(fixture.collectionScripts);
            run(fixture.collectionScripts);
            add(1);
            remove(1);
            continue;
        }

        // Paired and mixed fields must work in both directions.
        for (let index = roots().length - 1; index >= 0; index--) {
            add(index);
            remove(index);
        }
        // This deliberately reruns only production collection initialization. The
        // preexisting HasMany table parent handler is not idempotent and is outside
        // this fix; the real parent is still executed for each child insertion.
        run(fixture.collectionScripts);
        run(fixture.collectionScripts);
        for (let index = 0; index < roots().length; index++) {
            add(index);
            remove(index);
        }

        // An unrelated tbody with legacy-looking controls must remain untouched.
        $('#collection-form').append('<table id="unrelated"><tbody><tr><td><div class="items-remove" data-collection-remove><i></i></div></td></tr></tbody></table>');
        let unrelatedClicks = 0;
        $('#unrelated tbody').on('click.consumer', '[data-collection-remove]', () => unrelatedClicks++);
        $('#unrelated i').trigger('click');
        eq($('#unrelated tbody tr').length, 1, 'collection remove handling cannot leak to an unrelated tbody');
        eq(unrelatedClicks, 1, 'unrelated consumer handler is preserved');

        if (['default', 'tab', 'table'].includes(fixture.shape)) {
            for (let number = 1; number <= 2; number++) {
                const beforeRoots = roots().length;
                // Execute the actual production HasMany handler and its captured
                // nested script. Do not manually insert or patch child templates.
                const parentAdd = $('#has-many-children .add');
                eq(parentAdd.length, 1, 'one actual HasMany add control');
                parentAdd.trigger('click');
                eq(roots().length, beforeRoots + 1, 'real parent inserts exactly one child');
                const root = roots().at(-1);
                register(root, {...fixture.roots[0], base: 'children[new_' + number + '][items]'});
                eq(rows(root).length, 1, 'new child contains the default empty collection row');
                check();
                add(0);
                remove(0);
                add(roots().length - 1);
                remove(roots().length - 1);
                // Child template initialization also revisits every existing root.
                add(1);
                remove(1);
            }
        }

        // Moving DOM rows preserves controls and delegated removal. There is no
        // sortable plugin on these fields; this is a row-order regression only.
        for (let index = 0; index < fixture.roots.length; index++) {
            const root = roots()[index];
            const originalRows = rows(root);
            originalRows.forEach((row, position) => {
                Array.from(row.querySelectorAll('input')).forEach((input, part) => {
                    input.value = 'row-' + (position + 1) + '-part-' + part;
                });
            });
            body(root).appendChild(originalRows[0]);
            eq(rows(root), [originalRows[1], originalRows[0]], 'rows move in the owning tbody');
            for (const [part, name] of inputNames(root).entries()) {
                eq(snapshot(root).filter(input => input.name === name).map(input => input.value),
                    ['row-2-part-' + part, 'row-1-part-' + part], 'serialization follows moved DOM row order');
            }
            check();
            remove(index, originalRows[0]);
            add(index);
        }

        // Clear every existing and newly created root, then re-add and pass actual
        // serialized form data back to PHP's bracket-name parser for validation.
        for (let index = 0; index < roots().length; index++) {
            const root = roots()[index];
            while (rows(root).length) remove(index);
            const empty = Object.fromEntries(parts(root).map(part => [part, '']));
            result.serializations.push({base: expected.get(root).base, query: $.param(snapshot(root)), expected: empty});
            add(index);
            const populated = {};
            Array.from(rows(root)[0].querySelectorAll('input')).forEach((input, part) => {
                input.value = 're-added-' + parts(root)[part];
                populated[parts(root)[part]] = [input.value];
            });
            result.serializations.push({base: expected.get(root).base, query: $.param(snapshot(root)), expected: populated});
            check();
        }

        // Modeled PJAX replacement destroys the old roots and initializes fresh
        // production markup. No network request or browser PJAX integration is claimed.
        const oldRoots = roots();
        $('#pjax-container').html(fixture.html);
        oldRoots.forEach(root => ok(!root.isConnected, 'old PJAX roots are detached'));
        registerInitial();
        run(fixture.scripts);
        check();
        run(fixture.collectionScripts);
        add(roots().length - 1);
        remove(roots().length - 1);
    } catch (error) {
        error.message = fixture.shape + ' / jQuery ' + version + ': ' + error.message;
        throw error;
    } finally {
        dom.window.close();
    }
}

process.stdout.write(JSON.stringify(result));
