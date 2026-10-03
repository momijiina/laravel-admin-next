'use strict';

// Execute the PHP-rendered script partial, including its real ready closure.
// Fixtures, parent handlers, collection handlers, and templates are production output.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const jquery = require('jquery');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const shippedJquery = fs.readFileSync(path.resolve(__dirname,
    '../../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');
const result = {jquery: [], assertions: 0, snapshots: []};
const eq = (actual, expected, message) => {
    result.assertions++;
    assert.deepEqual(actual, expected, message);
};
const ok = (condition, message) => {
    result.assertions++;
    assert.ok(condition, message);
};
const parts = fixture.type === 'list' ? ['values'] : ['keys', 'values'];
const namePath = name => Array.from(name.matchAll(/(^[^\[]+)|\[([^\]]+)\]/g), match => match[1] || match[2]);

async function scenario(version, callback) {
    const dom = new JSDOM('<form id="actual-form"><div id="retained-content">' + fixture.html + '</div></form>', {
        runScripts: 'outside-only', url: 'https://hasmany-table.test/',
    });
    const window = dom.window;
    try {
        if (version === '2.1.4') window.eval(shippedJquery);
        else window.$ = window.jQuery = jquery(window);
        const $ = window.$;
        eq($.fn.jquery, version, 'the expected real jQuery is loaded');
        const scriptElement = window.document.createElement('div');
        scriptElement.innerHTML = fixture.renderedScript;
        const script = scriptElement.querySelector('script[data-exec-on-popstate]');
        ok(script, 'actual admin::partials.script ready wrapper is present');
        ok(script.textContent.includes('$(function () {'), 'do not flatten or replace the real ready callback');
        const errors = [];
        window.addEventListener('error', event => errors.push(event.error || event.message));
        const root = relation => window.document.getElementById('has-many-' + relation);
        const body = relation => root(relation).querySelector('table.table-has-many > tbody.has-many-' + relation + '-forms');
        const rows = relation => Array.from(body(relation).children);
        const collection = row => row.querySelector('[data-admin-collection]');
        const collectionRows = element => Array.from(element.querySelector('[data-collection-body]').children);
        const base = row => collection(row).querySelector('input[type=hidden]').name.replace(/\[(keys|values)\]$/, '');
        const key = row => namePath(base(row)).at(-2);
        const consumerCalls = new Map();
        const registerConsumers = () => {
            for (const relation of ['children', 'others']) {
                const parent = root(relation);
                const calls = {add: 0, addNamespaced: 0, remove: 0, removeNamespaced: 0};
                consumerCalls.set(parent, calls);
                $(parent).on('click', '.add', () => calls.add++)
                    .on('click.consumer', '.add', () => calls.addNamespaced++)
                    .on('click', '.remove', () => calls.remove++)
                    .on('click.consumer', '.remove', () => calls.removeNamespaced++);
            }
        };
        const bindings = () => {
            for (const relation of ['children', 'others']) {
                const events = $._data(root(relation), 'events') || {};
                const clicks = events.click || [];
                for (const selector of ['.add', '.remove']) {
                    eq(clicks.filter(handler => handler.selector === selector &&
                        handler.namespace.split('.').includes('adminHasManyTable')).length, 1,
                    'exactly one owned ' + selector + ' binding on ' + relation);
                    eq(clicks.filter(handler => handler.selector === selector).length, 3,
                        'owned binding and both same-selector consumer handlers survive');
                }
            }
        };
        const run = async () => {
            // A sentinel ready callback runs after the real partial, on both the
            // synchronous old-jQuery and deferred modern-jQuery ready paths.
            window.eval(script.textContent);
            await new Promise(resolve => $(resolve));
            eq(errors, [], 'ready callbacks did not throw');
            bindings();
        };
        const snapshotRows = relation => rows(relation).map(row => $(row).find('input').serializeArray());
        const add = (relation, expectedKey) => {
            const before = rows(relation).length;
            const other = relation === 'children' ? 'others' : 'children';
            const otherBefore = snapshotRows(other);
            const calls = consumerCalls.get(root(relation));
            const callsBefore = {...calls};
            $(root(relation).querySelector('.add i')).trigger('click');
            eq(rows(relation).length, before + 1, 'one parent Add inserts exactly one child');
            eq(snapshotRows(other), otherBefore, 'a parent Add leaves the second parent unchanged');
            eq(calls.add, callsBefore.add + 1, 'plain consumer Add listener survives');
            eq(calls.addNamespaced, callsBefore.addNamespaced + 1, 'namespaced consumer Add listener survives');
            const added = rows(relation).at(-1);
            eq(key(added), expectedKey, 'new child gets the expected never-reused identity');
            eq(new Set(rows(relation).map(key)).size, rows(relation).length, 'every live child envelope is unique');
            const probe = added.querySelector('input[name$="[probe]"]');
            eq(probe.getAttribute('data-probe-index'), expectedKey.slice(4),
                'generic captured child initializer sees the actual allocated identity');
            const safeIndex = BigInt(expectedKey.slice(4)) <= BigInt(Number.MAX_SAFE_INTEGER);
            eq(probe.getAttribute('data-probe-type'), safeIndex ? 'number' : 'string',
                'captured index preserves legacy number semantics within the safe range');
            eq(probe.getAttribute('data-probe-next'), safeIndex ? String(BigInt(expectedKey.slice(4)) + 1n) : null,
                'safe captured index supports arithmetic rather than string concatenation');
            eq(probe.getAttribute('data-probe-calls'), '1', 'generic child initializer runs once for this child');
            eq(probe.value, 'quoted "< & value', 'escaped template values survive insertion');
            return added;
        };
        const remove = (relation, row, isNew) => {
            const siblings = rows(relation).filter(sibling => sibling !== row);
            const before = siblings.map(sibling => sibling.outerHTML);
            const calls = consumerCalls.get(root(relation));
            const callsBefore = {...calls};
            $(row.querySelector('.remove i')).trigger('click');
            eq(calls.remove, callsBefore.remove + 1, 'plain consumer Remove listener survives');
            eq(calls.removeNamespaced, callsBefore.removeNamespaced + 1, 'namespaced consumer Remove listener survives');
            eq(siblings.map(sibling => sibling.outerHTML), before, 'parent Remove leaves sibling controls unchanged');
            if (isNew) {
                ok(!window.document.contains(row), 'new child is actually detached');
            } else {
                ok(window.document.contains(row), 'existing child stays available for submission');
                eq(row.style.display, 'none', 'existing child is hidden');
                eq($(row).find('input[name$="[_remove_]"]').val(), '1', 'existing removal flag is set');
                eq(row.querySelectorAll('input[required]').length, 0, 'existing required inputs are cleared');
            }
            bindings();
        };
        const checkCollection = row => {
            const element = collection(row);
            eq(element.dataset.adminCollection, fixture.type, 'inserted collection keeps its type marker');
            const markers = Array.from(element.querySelectorAll('input[type=hidden]'));
            eq(markers.map(input => input.name), parts.map(part => base(row) + '[' + part + ']'),
                'exactly one correctly scoped empty marker per part');
            const templates = Array.from(element.children).filter(child => child.tagName === 'TEMPLATE');
            eq(templates.length, 1, 'one direct child collection template');
            eq(Array.from(templates[0].content.querySelectorAll('input')).map(input => input.name),
                parts.map(part => base(row) + '[' + part + '][]'), 'collection template inherits the new parent identity');
            eq(templates[0].content.querySelectorAll('input[type=hidden]').length, 0, 'no empty markers in removable template');
            const inputs = Array.from($(element).find('input').serializeArray(), input => ({name: input.name, value: input.value}));
            for (const marker of markers) {
                eq(marker.value, '', 'empty marker value');
                ok(!marker.closest('[data-collection-body]'), 'empty marker is outside collection rows');
                const controls = inputs.filter(input => input.name === marker.name || input.name === marker.name + '[]');
                eq(controls[0], {name: marker.name, value: ''}, 'empty marker precedes submitted array controls');
                for (const visible of element.querySelectorAll('[data-collection-body] input')) {
                    ok(marker.compareDocumentPosition(visible) & window.Node.DOCUMENT_POSITION_FOLLOWING,
                        'empty marker precedes all populated inputs in the DOM');
                }
            }
        };
        const exerciseCollection = row => {
            const element = collection(row);
            const all = Array.from(window.document.querySelectorAll('[data-admin-collection]'));
            const counts = () => all.map(current => collectionRows(current).length);
            const before = counts();
            const position = all.indexOf(element);
            $(element.querySelector('[data-collection-add] i')).trigger('click');
            eq(counts(), before.map((count, index) => count + (index === position ? 1 : 0)),
                'captured initializer gives a new/existing collection exactly one local Add');
            checkCollection(row);
            $(collectionRows(element).at(-1).querySelector('[data-collection-remove] i')).trigger('click');
            eq(counts(), before, 'captured initializer gives a new/existing collection exactly one local Remove');
            checkCollection(row);
        };
        const fill = (row, label) => {
            for (const part of parts) {
                Array.from(collection(row).querySelectorAll('[data-collection-body] input'))
                    .filter(input => input.name.endsWith('[' + part + '][]'))
                    .forEach((input, index) => input.value = part + '-' + label + '-' + index);
            }
        };
        const snapshot = (persist = false) => {
            const parents = [];
            const collections = [];
            for (const relation of ['children', 'others']) {
                parents.push({path: [relation], keys: rows(relation).map(key)});
                for (const row of rows(relation)) {
                    checkCollection(row);
                    const value = {};
                    for (const part of parts) {
                        const values = Array.from(collection(row).querySelectorAll('[data-collection-body] input'))
                            .filter(input => input.name.endsWith('[' + part + '][]')).map(input => input.value);
                        value[part] = values.length ? values : '';
                    }
                    collections.push({path: namePath(base(row)), value});
                }
            }
            result.snapshots.push({query: $('#actual-form').serialize(), parents, collections, persist});
        };
        const pending = (relation, identity) => {
            // Model retained/redisplayed pending DOM using the unchanged emitted
            // parent template. No replacement HasMany script is supplied.
            const html = root(relation).querySelector(':scope > template.' + relation + '-tpl').innerHTML;
            $(body(relation)).append(html.replace(/__LA_KEY__/g, identity));
            return rows(relation).at(-1);
        };
        registerConsumers();
        for (const relation of ['children', 'others']) {
            eq(rows(relation).map(key), fixture.initialKeys[relation], 'the original database identities are rendered unchanged');
        }
        await callback({$, window, root, rows, collection, collectionRows, key, run, add, remove,
            fill, snapshot, pending, checkCollection, exerciseCollection, registerConsumers});
    } finally {
        dom.window.close();
    }
}

(async () => {
    for (const version of ['2.1.4', '3.7.1']) {
        result.jquery.push(version);
        for (const repeats of [1, 2, 3]) {
            await scenario(version, async context => {
                const {run, add, remove, rows, exerciseCollection, $} = context;
                for (let iteration = 0; iteration < repeats; iteration++) await run();
                const added = add('children', 'new_1');
                exerciseCollection(rows('children')[0]);
                exerciseCollection(added);
                remove('children', added, true);
                // ListField does not require its values, so set a real HTML
                // required attribute to verify the preserved parent-delete branch.
                $(rows('children')[0]).find('input').attr('required', 'required');
                $(rows('children')[1]).find('input').attr('required', 'required');
                remove('children', rows('children')[0], false);
                ok(rows('children')[1].querySelector('input[required]'), 'sibling required attributes survive');
            });
        }
        await scenario(version, async context => {
            const {run, add, remove, rows, fill, snapshot, exerciseCollection, collection,
                collectionRows, $, window, registerConsumers} = context;
            await run();
            const first = add('children', 'new_1');
            fill(first, 'first');
            const second = add('children', 'new_2');
            fill(second, 'second');
            const previous = [first.outerHTML, second.outerHTML];
            await run();
            await run();
            const third = add('children', 'new_3');
            fill(third, 'third');
            eq([first.outerHTML, second.outerHTML], previous, 'retained values and names survive full parent reruns');
            fill(add('others', 'new_1'), 'independent');
            for (const row of [...rows('children'), ...rows('others')]) exerciseCollection(row);
            snapshot(!fixture.customized);
            remove('children', third, true);
            await run();
            const fourth = add('children', 'new_4');
            remove('children', fourth, true);
            remove('children', second, true);
            remove('children', first, true);
            await run();
            const fifth = add('children', 'new_5');
            fill(fifth, 'after-removing-all-new');
            snapshot();
            // The PR40 empty marker and clear/re-add contract also applies to a
            // collection inserted by a repeatedly initialized table parent.
            const element = collection(fifth);
            while (collectionRows(element).length) {
                $(collectionRows(element)[0].querySelector('[data-collection-remove] i')).trigger('click');
            }
            snapshot();
            $(element.querySelector('[data-collection-add] i')).trigger('click');
            eq(collectionRows(element).length, 1, 'cleared newly added collection can be repopulated');
            fill(fifth, 'readded');
            snapshot();
            // Fresh replacement has fresh per-element state, despite old parent
            // callbacks having previously allocated identities through new_5.
            $('#retained-content').html(fixture.html);
            registerConsumers();
            await run();
            fill(add('children', 'new_1'), 'fresh');
            snapshot();
            ok(window.document.querySelector('#has-many-others'), 'replacement contains the independent parent');
        });
        await scenario(version, async context => {
            const {pending, root, run, add, remove, fill, snapshot, rows, $} = context;
            fill(pending('children', '2'), 'pending-two');
            fill(pending('children', '7'), 'pending-seven');
            // A different field-name prefix must not advance this parent, and
            // concrete new_N names inside inert templates must not be scanned.
            $(root('others')).append('<input name="others[new_999][unrelated]" value="ignore">');
            $(root('children')).append('<input name="children[new_700][unrelated]" value="ignore">');
            $(root('children')).prepend('<template><input name="children[new_800][items][values][]"></template>');
            const unrelatedTable = $('<table class="table table-has-many"><tbody class="unrelated-forms">' +
                '<tr><td><input name="decoy[keep]" value="untouched"></td></tr></tbody></table>')
                .prependTo(root('children'))[0];
            const unrelatedBefore = unrelatedTable.outerHTML;
            await run();
            await run();
            const eighth = add('children', 'new_8');
            remove('children', eighth, true);
            await run();
            fill(add('children', 'new_9'), 'pending-nine');
            // Reconcile genuinely newer pending markup after state exists too.
            fill(pending('children', '20'), 'pending-twenty');
            await run();
            fill(add('children', 'new_21'), 'pending-twenty-one');
            fill(add('others', 'new_1'), 'independent-after-pending');
            eq(unrelatedTable.outerHTML, unrelatedBefore, 'earlier unrelated parent table and its dummy row stay untouched');
            eq(rows('children').map(context.key).slice(-5), ['new_2', 'new_7', 'new_9', 'new_20', 'new_21'],
                'pending gaps retain their identity and values');
            // Remove the deliberately unrelated successful input before parsing.
            $(root('others')).children('input').remove();
            $(root('children')).children('input').remove();
            snapshot();
        });
        await scenario(version, async context => {
            const {pending, run, add, remove, fill, snapshot} = context;
            fill(pending('children', '9007199254740992'), 'large-pending');
            await run();
            const next = add('children', 'new_9007199254740993');
            fill(next, 'large-next');
            snapshot();
            remove('children', next, true);
            await run();
            fill(add('children', 'new_9007199254740994'), 'large-after-delete');
            snapshot();
        });
        await scenario(version, async context => {
            const {pending, run, add, fill, snapshot} = context;
            fill(pending('children', '999'), 'safe-carry-pending');
            fill(pending('others', '9'.repeat(30)), 'large-carry-pending');
            await run();
            fill(add('children', 'new_1000'), 'safe-carry');
            fill(add('others', 'new_1' + '0'.repeat(30)), 'large-carry');
            snapshot();
        });
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
