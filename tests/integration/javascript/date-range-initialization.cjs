'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const assets = path.resolve(__dirname, '../../../resources/assets');
const result = {fixtures: 0, assertions: 0};
const eq = (actual, expected, message) => { result.assertions++; assert.deepEqual(actual, expected, message); };
const ok = (value, message) => { result.assertions++; assert.ok(value, message); };

(async () => {
    for (const fixture of fixtures) {
        const dom = new JSDOM('<form>'+fixture.html+'</form>', {runScripts: 'outside-only'});
        const w = dom.window;
        try {
            const errors = [];
            w.addEventListener('error', e => errors.push(String(e.error || e.message)));
            // Shipped Moment 2.10 predates moment.now, so pin its actual Date clock.
            w.eval(`{ const NativeDate = Date; window.Date = class extends NativeDate {
                constructor(...args) { super(...(args.length ? args : [NativeDate.UTC(2026, 9, 20, 12)])); }
                static now() { return NativeDate.UTC(2026, 9, 20, 12); }
            }; }`);
            for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
                'moment/min/moment-with-locales.min.js', 'eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js']) {
                w.eval(fs.readFileSync(path.join(assets, asset), 'utf8'));
            }
            eq(w.moment().format('YYYY-MM-DD'), '2026-10-20', 'real Moment clock is pinned');
            const $ = w.$;
            const start = $('[name=one_start]'), end = $('[name=one_end]');
            const other = $('[name^=two_]');
            const values = () => $('form').serialize();
            const original = values();
            let consumerCalls = 0, parserCalls = 0;
            start.add(end).on('dp.change', () => consumerCalls++).on('dp.change.consumer', () => consumerCalls++);
            if (fixture.mode === 'parser') {
                const dataOptions = new w.Object();
                dataOptions.parseInputDate = value => {
                    parserCalls++;
                    return w.moment.isMoment(value) ? value.clone() : w.moment(value, fixture.type === 'dateRange' ? 'YYYY-MM-DD' : fixture.type === 'timeRange' ? 'HH:mm:ss' : 'YYYY-MM-DD HH:mm:ss', true);
                };
                $('input').data('dateOptions', dataOptions);
            }
            if (fixture.mode === 'endpoint-limits') {
                const limit = fixture.type === 'timeRange' ? '2026-10-20T'+fixture.values[1] : fixture.values[1];
                start.data('dateMaxDate', limit);
                end.data('dateMinDate', limit);
            }
            const scriptHolder = w.document.createElement('div');
            scriptHolder.innerHTML = fixture.renderedScript;
            const script = scriptHolder.querySelector('script[data-exec-on-popstate]');
            ok(script && script.textContent.includes('$(function () {'), 'production ready callback');
            const run = async () => {
                w.eval(script.textContent);
                await new Promise(resolve => $(resolve));
                eq(errors, [], fixture.type+' '+fixture.mode+' ready errors');
            };
            await run();
            const sp = start.data('DateTimePicker'), ep = end.data('DateTimePicker');
            const stamp = date => date ? date.format('YYYY-MM-DD HH:mm:ss') : null;
            const state = picker => [stamp(picker.date()), stamp(picker.minDate()), stamp(picker.maxDate()), picker.useCurrent()];
            eq(values(), original, fixture.type+' '+fixture.mode+' initial values preserved');
            eq(sp.useCurrent(), ['use-current-false', 'limits', 'endpoint-limits'].includes(fixture.mode) ? false : fixture.mode === 'keep-invalid' ? 'day' : true, 'start useCurrent preserved');
            eq(ep.useCurrent(), fixture.mode === 'keep-invalid' ? 'day' : false, 'end useCurrent preserved');
            const excluded = ['inverted', 'parser', 'timezone'].includes(fixture.mode);
            if (fixture.mode === 'endpoint-limits') {
                eq(sp.maxDate().format(sp.format()), fixture.values[1], 'stricter per-endpoint maximum retained');
                eq(ep.minDate().format(ep.format()), fixture.values[1], 'stricter per-endpoint minimum retained');
            } else if (fixture.mode.startsWith('strict-')) {
                const method = fixture.mode === 'strict-min' ? 'minDate' : 'maxDate';
                eq(sp[method]().format(sp.format()), fixture.values[1], 'stricter start application bound retained');
                eq(ep[method]().format(ep.format()), fixture.values[1], 'stricter end application bound retained');
            } else if (excluded) {
                eq(sp.maxDate(), false, fixture.mode+' excluded initial max unchanged');
                eq(ep.minDate(), false, 'excluded initial min unchanged');
            } else {
                eq(stamp(sp.maxDate()), stamp(ep.date()), 'initial end seeds maximum');
                eq(stamp(ep.minDate()), stamp(sp.date()), 'initial start seeds minimum');
            }
            if (fixture.mode === 'parser') ok(parserCalls > 0, 'custom parser executed');
            if (fixture.mode === 'timezone') eq(sp.options().timeZone, 'UTC', 'timezone preserved');
            const states = [state(sp), state(ep)], calls = consumerCalls;
            await run();
            await run();
            eq([state(sp), state(ep)], states, 'repeat initialization preserves dates/options/bounds');
            eq(values(), original, 'repeat initialization preserves serialized values');
            eq(consumerCalls, calls, 'repeat initialization emits no changes');
            for (const input of [start, end]) {
                const handlers = $._data(input[0], 'events').dp;
                eq(handlers.filter(handler => handler.namespace.includes('adminDateRange')).length, 1, 'one owned listener');
                eq(handlers.filter(handler => handler.namespace === 'change' || handler.namespace === 'change.consumer').length, 2, 'consumer handlers preserved');
            }
            const untouched = other.serialize();
            if (['full', 'use-current-false', 'limits'].includes(fixture.mode)) {
                const before = start.val();
                start.val(fixture.values[3]).trigger('change');
                eq(start.val(), before, 'first invalid start edit rejected');
                const endBefore = end.val();
                // End before initial start: use the same parsed day/time minus one unit.
                end.val(sp.date().clone().subtract(1, fixture.type === 'dateRange' ? 'day' : 'hour').format(ep.format())).trigger('change');
                eq(end.val(), endBefore, 'first invalid end edit rejected');
                start.val(fixture.values[1]).trigger('change');
                eq(start.val(), fixture.values[1], 'valid start edit accepted');
                eq(stamp(ep.minDate()), stamp(sp.date()), 'valid start updates peer bound');
                end.val(fixture.values[3]).trigger('change');
                eq(end.val(), fixture.values[3], 'valid end edit accepted');
                eq(stamp(sp.maxDate()), stamp(ep.date()), 'valid end updates peer bound');
                ok(consumerCalls > calls, 'consumer handlers receive normal changes');
                end.val('').trigger('change');
                eq(sp.maxDate(), false, 'clearing end retains existing vendor bound clearing');
                start.val('').trigger('change');
                eq(ep.minDate(), false, 'clearing start retains existing vendor bound clearing');
                eq(start.val(), '', 'cleared start stays empty');
                eq(end.val(), '', 'cleared end stays empty');
            }
            if (['null-start', 'null-end', 'empty'].includes(fixture.mode)) {
                if (fixture.mode === 'null-start') {
                    start.val(fixture.values[3]).trigger('change');
                    eq(start.val(), '', 'first invalid edit cannot fill nullable start');
                }
                if (fixture.mode === 'null-end') {
                    end.val(sp.date().clone().subtract(1, fixture.type === 'dateRange' ? 'day' : 'hour').format(ep.format())).trigger('change');
                    eq(end.val(), '', 'first invalid edit cannot fill nullable end');
                }
                start.val(fixture.values[1]).trigger('change');
                end.val(fixture.values[2]).trigger('change');
                eq(start.val(), fixture.values[1], 'nullable pair accepts valid start');
                eq(end.val(), fixture.values[2], 'nullable pair accepts valid end');
                eq(stamp(ep.minDate()), stamp(sp.date()), 'nullable edit updates minimum');
                eq(stamp(sp.maxDate()), stamp(ep.date()), 'nullable edit updates maximum');
            }
            eq(other.serialize(), untouched, 'independent range unchanged');
            // Applications may replace widget bounds after initialization; rerunning
            // the ready partial must not reinstate a stale relationship.
            sp.maxDate(false);
            ep.minDate(false);
            await run();
            eq(sp.maxDate(), false, 'reinitialization does not reseed maximum');
            eq(ep.minDate(), false, 'reinitialization does not reseed minimum');
            eq(errors, [], 'no asynchronous widget errors');
            result.fixtures++;
        } finally { w.close(); }
    }
    process.stdout.write(JSON.stringify(result));
})().catch(error => { console.error(error); process.exitCode = 1; });
