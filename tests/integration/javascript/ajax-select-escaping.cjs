'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');

async function run(jquery, template) {
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', url: 'https://offline.invalid/'});
    const window = dom.window;
    try {
        const errors = [];
        window.addEventListener('error', event => errors.push(String(event.error || event.message)));
        window.eval(fs.readFileSync(jquery === 'shipped'
            ? path.join(assets, 'AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js')
            : require.resolve('jquery'), 'utf8'));
        window.eval(fs.readFileSync(path.join(assets, 'AdminLTE/plugins/select2/select2.full.min.js'), 'utf8'));
        const $ = window.jQuery;
        if (template !== 'default') {
            // These are real JavaScript callbacks registered through Select2's
            // native extension API, not PHP config() callback strings.
            const render = data => {
                const node = window.document.createElement('strong');
                node.className = 'explicit-select-template';
                node.textContent = data.text;
                return template === 'jquery' ? $(node) : node;
            };
            $.fn.select2.defaults.set('templateResult', render);
            $.fn.select2.defaults.set('templateSelection', render);
        }
        const requests = [];
        let pending = 0;
        // Replace transport only. PHP fetched both pages from real HTTP routes.
        $.ajax = options => {
            assert.equal(options.url, fixture.url);
            assert.equal(options.dataType, 'json');
            assert.equal(options.cache, true);
            const page = options.data.page || 1;
            requests.push({q: options.data.q, page});
            assert.ok(page === 1 || page === 2);
            pending++;
            const deferred = $.Deferred();
            window.setTimeout(() => {
                pending--;
                // processResults mutates its rows; each response is independent.
                deferred.resolve(JSON.parse(JSON.stringify(fixture.responses[page - 1])));
            }, 0);
            return deferred.promise({abort() { deferred.reject(null, 'abort'); }});
        };
        const scripts = window.document.createElement('div');
        scripts.innerHTML = fixture.scriptHtml;
        for (const script of scripts.querySelectorAll('script')) window.eval(script.textContent);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('ready timeout')), 5000);
            $(function () { clearTimeout(timer); resolve(); });
        });
        const select = window.document.querySelector('select');
        const widget = $(select).data('select2');
        assert.ok(widget);
        assert.equal(widget.options.get('width'), '100%');
        assert.equal(widget.options.get('allowClear'), true);
        assert.equal(widget.options.get('minimumInputLength'), 1);
        assert.equal(widget.options.get('closeOnSelect'), false);
        const describe = element => {
            const clone = element.cloneNode(true);
            for (const remove of clone.querySelectorAll('.select2-selection__clear, .select2-selection__choice__remove')) remove.remove();
            return {
                text: clone.textContent,
                parsedLabelNodes: clone.querySelectorAll('.ajax-label-markup').length,
                templateNodes: clone.querySelectorAll('.explicit-select-template').length,
            };
        };
        const selection = () => Array.from(widget.$selection[0].querySelectorAll(fixture.multiple
            ? '.select2-selection__choice' : '.select2-selection__rendered'), describe);
        const result = id => widget.$results.find('.select2-results__option').filter(function () {
            const data = $(this).data('data');
            return data && String(data.id) === id;
        });
        const waitFor = async condition => {
            const deadline = Date.now() + 5000;
            while (!condition()) {
                assert.ok(Date.now() < deadline, 'Select2 response timeout');
                await new Promise(resolve => window.setTimeout(resolve, 1));
            }
        };
        const labels = {initial: selection()};
        assert.deepEqual(Array.from(select.selectedOptions, option => option.value), ['stored']);
        $(select).select2('open');
        const search = widget.dropdown.$search || widget.selection.$search;
        search.val('needle').trigger('input');
        await waitFor(() => pending === 0 && result(fixture.ids[1]).length === 1);
        const more = [widget.results.$loadingMore.parent().length > 0];
        // Exercise Select2's normal next-page implementation without a viewport.
        widget.results.loadMore();
        await waitFor(() => pending === 0 && result(fixture.ids[2]).length === 1);
        more.push(widget.results.$loadingMore.parent().length > 0);
        labels.results = fixture.ids.map(id => {
            assert.equal(result(id).length, 1);
            return describe(result(id)[0]);
        });
        result(fixture.ids[0]).trigger('mouseup');
        labels.afterZero = selection();
        result(fixture.ids[1]).trigger('mouseup');
        labels.afterOther = selection();
        const entries = Array.from(new window.FormData(select.form).entries());
        assert.equal(new window.URLSearchParams(entries).toString(),
            new window.URLSearchParams($(select.form).serialize()).toString());
        assert.deepEqual(errors, []);
        return {
            labels,
            selected: Array.from(select.selectedOptions, option => option.value),
            entries, requests, more,
        };
    } finally {
        window.close();
    }
}

(async () => {
    const results = {};
    for (const template of fixture.templates) {
        results[template] = await run('shipped', template);
        assert.deepEqual(await run('modern', template), results[template]);
    }
    process.stdout.write(JSON.stringify(results));
})().catch(error => { console.error(error); process.exitCode = 1; });
