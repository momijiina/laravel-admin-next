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
const shippedBootstrap = fs.readFileSync(path.resolve(__dirname,
    '../../../resources/assets/AdminLTE/bootstrap/js/bootstrap.min.js'), 'utf8');
const isTab = fixture.mode === 'tab';
const namespace = isTab ? 'adminHasManyTab' : 'adminHasManyDefault';
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

async function scenario(version, callback, rendered = fixture) {
    const dom = new JSDOM('<form id="actual-form"><div id="retained-content">' + rendered.html + '</div></form>', {
        runScripts: 'outside-only', url: 'https://hasmany-modes.test/',
    });
    const window = dom.window;
    try {
        if (version === '2.1.4') window.eval(shippedJquery);
        else window.$ = window.jQuery = jquery(window);
        window.eval(shippedBootstrap);
        const $ = window.$;
        eq($.fn.tab.Constructor.VERSION, '3.3.4', 'the shipped Bootstrap tab plugin is loaded');
        eq($.fn.jquery, version, 'the expected real jQuery is loaded');
        const scriptElement = window.document.createElement('div');
        scriptElement.innerHTML = rendered.renderedScript;
        const script = scriptElement.querySelector('script[data-exec-on-popstate]');
        ok(script, 'actual admin::partials.script ready wrapper is present');
        ok(script.textContent.includes('$(function () {'), 'do not flatten or replace the real ready callback');
        const errors = [];
        window.addEventListener('error', event => errors.push(event.error || event.message));
        const root = relation => window.document.getElementById('has-many-' + relation);
        const body = relation => Array.from(root(relation).children).find(element => element.classList.contains('has-many-' + relation + '-forms'));
        const nav = relation => root(relation).querySelector(':scope > .nav');
        const navItems = relation => Array.from(nav(relation).children);
        const addRoot = relation => isTab ? root(relation).querySelector(':scope > .header') : root(relation);
        const removeRoot = relation => isTab ? nav(relation) : root(relation);
        const removeSelector = isTab ? 'i.close-tab' : '.remove';
        const rows = relation => Array.from(body(relation).children);
        const collection = row => row.querySelector('[data-admin-collection]');
        const collectionRows = element => Array.from(element.querySelector('[data-collection-body]').children);
        const base = row => collection(row).querySelector('input[type=hidden]').name.replace(/\[(keys|values)\]$/, '');
        const key = row => namePath(row.querySelector('input[name$="[id]"]').name)[1];
        const activeKeys = relation => rows(relation).filter(row => row.classList.contains('active')).map(key);
        const checkTabs = relation => {
            if (!isTab) return;
            const ids = rows(relation).map(row => row.id);
            eq(new Set(ids).size, ids.length, 'all live pane IDs are unique');
            const targets = navItems(relation).map(item => item.querySelector('a').getAttribute('href').slice(1));
            eq(new Set(targets).size, targets.length, 'all navigation targets are unique');
            eq(targets, rows(relation).filter(row => row.querySelector('.fom-removed').value !== '1').map(row => row.id),
                'navigation is bijective with the unremoved panes in DOM order');
            for (const target of targets) {
                eq(window.document.querySelectorAll('[id="' + target + '"]').length, 1, 'each tab target resolves to exactly one pane');
            }
            const active = navItems(relation).filter(item => item.classList.contains('active'));
            eq(active.length, targets.length ? 1 : 0, 'one active navigation tab, or none after the last close');
            eq(activeKeys(relation), active.map(item => key(window.document.getElementById(item.querySelector('a').hash.slice(1)))),
                'active pane agrees with the active navigation target');
        };
        const consumerCalls = new Map();
        const registerConsumers = () => {
            for (const relation of ['children', 'others']) {
                const parent = root(relation);
                const calls = {add: 0, addNamespaced: 0, remove: 0, removeNamespaced: 0};
                consumerCalls.set(parent, calls);
                $(addRoot(relation)).on('click', '.add', () => calls.add++)
                    .on('click.consumer', '.add', () => calls.addNamespaced++);
                $(removeRoot(relation)).on('click', removeSelector, () => calls.remove++)
                    .on('click.consumer', removeSelector, () => calls.removeNamespaced++);
            }
        };
        const bindings = () => {
            for (const relation of ['children', 'others']) {
                for (const [target, selector] of [[addRoot(relation), '.add'], [removeRoot(relation), removeSelector]]) {
                    const clicks = ($._data(target, 'events') || {}).click || [];
                    eq(clicks.filter(handler => handler.selector === selector &&
                        handler.namespace.split('.').includes(namespace)).length, 1,
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
            if (isTab) eq(activeKeys(relation), [expectedKey], 'the newly added tab becomes active');
            checkTabs(relation);
            return added;
        };
        const remove = (relation, row, isNew) => {
            const siblings = rows(relation).filter(sibling => sibling !== row);
            const before = siblings.map(sibling => $(sibling).find('input').serializeArray());
            const other = relation === 'children' ? 'others' : 'children';
            const otherBefore = root(other).outerHTML;
            const calls = consumerCalls.get(root(relation));
            const callsBefore = {...calls};
            const requiredBefore = row.querySelectorAll('input[required]').length;
            let expectedActive;
            let control = row.querySelector('.remove i');
            if (isTab) {
                const item = navItems(relation).find(item => item.querySelector('a').hash === '#' + row.id);
                const wasActive = item.classList.contains('active');
                const remaining = navItems(relation).filter(current => current !== item);
                expectedActive = wasActive ? remaining.slice(0, 1).map(current =>
                    key(window.document.getElementById(current.querySelector('a').hash.slice(1)))) : activeKeys(relation);
                control = item.querySelector('i.close-tab');
            }
            $(control).trigger('click');
            eq(calls.remove, callsBefore.remove + 1, 'plain consumer Remove listener survives');
            eq(calls.removeNamespaced, callsBefore.removeNamespaced + 1, 'namespaced consumer Remove listener survives');
            eq(siblings.map(sibling => $(sibling).find('input').serializeArray()), before,
                'parent Remove leaves sibling controls unchanged');
            eq(root(other).outerHTML, otherBefore, 'closing a child leaves the independent relation unchanged');
            if (isTab && isNew) {
                ok(!window.document.contains(row), 'tab new child is actually detached');
            } else {
                ok(window.document.contains(row), 'removed child stays available for submission');
                eq($(row).find('input[name$="[_remove_]"]').val(), '1', 'removal flag is set');
                if (isTab) {
                    eq(row.querySelectorAll('input[required]').length, requiredBefore,
                        'tab close preserves its existing required-attribute semantics');
                    ok(!row.classList.contains('active'), 'closed existing pane is inactive');
                } else {
                    eq(row.style.display, 'none', 'default removed child is hidden, including new children');
                    eq(row.querySelectorAll('input[required]').length, 0, 'default required inputs are cleared');
                }
            }
            if (isTab) eq(activeKeys(relation), expectedActive,
                'active close selects the first remaining tab; inactive close preserves selection');
            checkTabs(relation);
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
            // Render retained pending DOM with unchanged production templates.
            // This does not replace parent handlers or claim an old-input round trip.
            const template = root(relation).querySelector(isTab ? ':scope > template.pane-tpl' : ':scope > template.' + relation + '-tpl');
            $(body(relation)).append(template.innerHTML.replace(/__LA_KEY__/g, identity));
            if (isTab) {
                const navTemplate = root(relation).querySelector(':scope > template.nav-tab-tpl');
                $(nav(relation)).append(navTemplate.innerHTML.replace(/__LA_KEY__/g, identity));
            }
            return rows(relation).at(-1);
        };
        registerConsumers();
        for (const relation of ['children', 'others']) {
            eq(rows(relation).map(key), fixture.initialKeys[relation], 'the original database identities are rendered unchanged');
        }
        await callback({$, window, root, rows, collection, collectionRows, key, run, add, remove,
            fill, snapshot, pending, checkCollection, exerciseCollection, registerConsumers, body, nav, navItems, activeKeys, checkTabs});
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
            const previous = [first, second].map(row => $(row).find('input').serializeArray());
            await run();
            await run();
            const third = add('children', 'new_3');
            fill(third, 'third');
            eq([first, second].map(row => $(row).find('input').serializeArray()), previous, 'retained values and names survive full parent reruns');
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
            // collection inserted by a repeatedly initialized parent.
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
            fill(pending('children', '0007'), 'pending-seven');
            // A different field-name prefix must not advance this parent, and
            // concrete new_N names inside inert templates must not be scanned.
            $(root('others')).append('<input name="others[new_999][unrelated]" value="ignore">');
            $(root('children')).append('<input name="children[new_700][unrelated]" value="ignore">');
            $(root('children')).prepend('<template><input name="children[new_800][items][values][]"></template>');
            const decoy = $('<div class="outside-decoy"><template class="children-tpl"><div>wrong template</div></template>' +
                '<template class="pane-tpl"><div>wrong pane</div></template>' +
                '<div class="has-many-children-forms tab-content"><input name="decoy[keep]" value="untouched"></div></div>')
                .prependTo('#retained-content')[0];
            $(root('children')).prepend('<template><div>wrong direct template</div></template>');
            const decoyBefore = decoy.outerHTML;
            $(rows('children')[0]).append('<input name="children[new_999x][unrelated]" value="ignore">' +
                '<input name="children[new_1e9][unrelated]" value="ignore">' +
                '<input name="other[new_999999][unrelated]" value="ignore">');
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
            eq(decoy.outerHTML, decoyBefore, 'earlier unrelated template/body outside the concrete parent stay untouched');
            eq(rows('children').map(context.key).filter(value => value.startsWith('new_')),
                isTab ? ['new_2', 'new_0007', 'new_9', 'new_20', 'new_21']
                    : ['new_2', 'new_0007', 'new_8', 'new_9', 'new_20', 'new_21'],
                'pending gaps and leading zeros retain their identity and values');
            // Remove the deliberately unrelated successful input before parsing.
            $(root('others')).children('input').remove();
            $(root('children')).children('input').remove();
            $(rows('children')[0]).find('input[name*="[unrelated]"]').remove();
            $(decoy).remove();
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
        await scenario(version, async context => {
            const {pending, run, add, fill, snapshot} = context;
            fill(pending('children', '9007199254740990'), 'last-safe-pending');
            await run();
            fill(add('children', 'new_9007199254740991'), 'last-safe');
            await run();
            fill(add('children', 'new_9007199254740992'), 'first-unsafe');
            snapshot();
        });
        if (isTab) {
            await scenario(version, async context => {
                const {run, add, remove, rows, navItems, activeKeys, checkTabs, $} = context;
                await run();
                const [first, second] = rows('children');
                eq(activeKeys('children'), [fixture.initialKeys.children[0]], 'first existing tab starts active');
                const newFirst = add('children', 'new_1');
                const newSecond = add('children', 'new_2');
                // Inactive new close must remove precisely its linked pane.
                remove('children', newFirst, true);
                ok(newSecond.isConnected, 'closing inactive new tab does not remove the active new pane');
                // Existing close must retain its canonical successful controls.
                $(second).find('input').attr('required', 'required');
                remove('children', second, false);
                eq(activeKeys('children'), ['new_2'], 'inactive existing close preserves the new selection');
                await run();
                remove('children', newSecond, true);
                eq(activeKeys('children'), [fixture.initialKeys.children[0]], 'active new close selects first remaining existing tab');
                $(first).find('input').attr('required', 'required');
                remove('children', first, false);
                eq(navItems('children').length, 0, 'closing the last existing tab leaves no nav items');
                eq(activeKeys('children'), [], 'closing the last tab leaves no active panes');
                await run();
                const afterEmpty = add('children', 'new_3');
                eq(navItems('children').length, 1, 'adding after the last close restores one nav item');
                remove('children', afterEmpty, true);
                eq(navItems('children').length, 0, 'closing the final new tab removes its last navigation item');
                await run();
                add('children', 'new_4');
                checkTabs('children');
            });
            await scenario(version, async context => {
                const {run, root, rows, navItems, activeKeys, checkTabs} = context;
                ok(root('children').querySelector('.has-error'), 'actual Blade validation markup is present');
                await run();
                eq(activeKeys('children'), [fixture.initialKeys.children[1]], 'ready activates the existing pane with the first validation error');
                const links = navItems('children').map(item => item.querySelector('a'));
                eq(links.map(link => link.querySelector('i').classList.contains('hide')), [true, false],
                    'only the failing pane receives a visible validation indicator');
                eq(rows('children').map(context.key), fixture.initialKeys.children, 'validation activation retains canonical existing identities');
                await run();
                eq(activeKeys('children'), [fixture.initialKeys.children[1]], 'reinitialization preserves validation error activation');
                checkTabs('children');
                checkTabs('others');
            }, fixture.validation);
        }
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
