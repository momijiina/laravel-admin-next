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
        for (const asset of ['AdminLTE/bootstrap/js/bootstrap.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js',
            'AdminLTE/plugins/select2/select2.full.min.js']) window.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
        const $ = window.jQuery;
        const templateResults = [];
        if (template === 'explicit') {
            const render = data => $('<strong class="explicit-zero-template">').text(data.text);
            $.fn.select2.defaults.set('templateResult', data => {
                if (data.extra) templateResults.push({id: data.id, index: data.extra.index});
                return render(data);
            });
            $.fn.select2.defaults.set('templateSelection', render);
        }
        const requests = [];
        let pending = 0;
        // Only transport is replaced; both page responses came from HTTP routes.
        $.ajax = options => {
            assert.equal(options.url, fixture.url);
            assert.equal(options.type, 'GET');
            assert.equal(options.dataType, 'json');
            assert.equal(options.cache, true);
            const page = options.data.page || 1;
            requests.push({q: options.data.q, page});
            pending++;
            const deferred = $.Deferred();
            window.setTimeout(() => {
                pending--;
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
        const select = window.document.querySelector('select[name="choice"], select[name="choice[]"]');
        const widget = $(select).data('select2');
        assert.ok(widget);
        for (const [key, value] of Object.entries({width: '100%', allowClear: true, closeOnSelect: false, minimumInputLength: 1})) {
            assert.equal(widget.options.get(key), value);
        }
        const capture = expected => {
            const selected = Array.from(select.selectedOptions, option => option.value).filter(value => value !== '');
            assert.deepEqual(selected, expected, 'Native selected values must contain the AJAX result ID.');
            const current = Array.from($(select).select2('data'));
            const data = current.filter(item => item.id !== '');
            assert.deepEqual(data.map(item => item.id), expected);
            for (const item of data) {
                assert.equal(typeof item.id, 'string');
                assert.equal(item.element.value, item.id);
            }
            assert.equal(widget.$selection.find('.ajax-zero-label').length, 0);
            if (expected.length) {
                assert.equal(widget.$selection.find('.explicit-zero-template').length, template === 'explicit' ? current.length : 0);
            }
            const entries = Array.from(new window.FormData(select.form).entries());
            const query = new window.URLSearchParams(entries).toString();
            assert.equal(new window.URLSearchParams($(select.form).serialize()).toString(), query);
            return {selected, query};
        };
        capture(['stored']);
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
        $(select).select2('open');
        (widget.dropdown.$search || widget.selection.$search).val('needle').trigger('input');
        await waitFor(() => pending === 0 && result('00').length === 1);
        assert.equal(widget.results.$loadingMore.parent().length, 1);
        widget.results.loadMore();
        await waitFor(() => pending === 0 && result('page-two').length === 1);
        assert.equal(widget.results.$loadingMore.parent().length, 0);
        const snapshots = [];
        const events = [];
        $(select).on('select2:select', event => events.push(event.params.data));
        const rows = fixture.responses.flatMap(response => response.data);
        const expectedIds = ['0', 7, '00', fixture.ids[3], 'page-two'];
        for (let index = 0; index < fixture.ids.length; index++) {
            const id = fixture.ids[index];
            const option = result(id);
            assert.equal(option.length, 1);
            const data = option.data('data');
            // Preserve positive numeric IDs, custom source keys, and extra data.
            assert.equal(data.text, rows[index][fixture.mapping[1]]);
            assert.equal(data.extra.index, index);
            if (fixture.mapping[0] !== 'id') assert.equal(data[fixture.mapping[0]], rows[index][fixture.mapping[0]]);
            assert.equal(option.text(), data.text);
            assert.equal(option.find('.ajax-zero-label').length, 0);
            assert.equal(option.find('.explicit-zero-template').length, template === 'explicit' ? 1 : 0);
            option.trigger('mouseup');
            snapshots.push(capture(fixture.multiple ? ['stored', ...fixture.ids.slice(0, index + 1)] : [id]));
            assert.equal(data.id, expectedIds[index]);
            assert.equal(events.at(-1).id, expectedIds[index]);
            assert.equal(widget.$selection.find('.select2-selection__placeholder').length, 0);
        }
        if (template === 'explicit') {
            assert.deepEqual(templateResults, expectedIds.map((id, index) => ({id, index})));
        }
        const clear = widget.$selection.find('.select2-selection__clear');
        assert.equal(clear.length, 1);
        clear.trigger('mousedown');
        snapshots.push(capture([]));
        $(select).select2('open');
        (widget.dropdown.$search || widget.selection.$search).val('needle').trigger('input');
        await waitFor(() => requests.length === 3 && pending === 0 && result('0').length === 1);
        result('0').trigger('mouseup');
        snapshots.push(capture(['0']));
        $(select).select2('close');
        assert.deepEqual(capture(['0']), snapshots.at(-1));
        assert.deepEqual(requests, [{q: 'needle', page: 1}, {q: 'needle', page: 2}, {q: 'needle', page: 1}]);
        assert.deepEqual(errors, []);
        return snapshots;
    } finally {
        window.close();
    }
}

(async () => {
    const results = {};
    for (const template of ['default', 'explicit']) {
        results[template] = await run('shipped', template);
        assert.deepEqual(await run('modern', template), results[template]);
    }
    process.stdout.write(JSON.stringify(results));
})().catch(error => { console.error(error); process.exitCode = 1; });
