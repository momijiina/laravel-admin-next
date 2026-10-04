const fs = require('node:fs');
const path = require('node:path');
const {JSDOM, VirtualConsole} = require('jsdom');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const results = [];

for (const fixture of input.fixtures) {
    const logs = [];
    const console = new VirtualConsole();
    for (const event of ['jsdomError', 'error', 'warn']) {
        console.on(event, error => logs.push({event, message: String(error)}));
    }
    const dom = new JSDOM(fixture.html, {runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: console});
    const window = dom.window;
    try {
        const asset = file => window.eval(fs.readFileSync(path.join(input.assets, file), 'utf8'));
        if (input.jquery === 'modern') {
            window.eval(fs.readFileSync(require.resolve('jquery'), 'utf8'));
        } else {
            asset('AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js');
        }
        asset('flatpickr/dist/flatpickr.js');
        asset('flatpickr/dist/shortcut-buttons-flatpickr/shortcut-buttons-flatpickr.min.js');
        asset('flatpickr/dist/l10n/zh.js');
        const element = window.document.querySelector('input[name="dates"]');
        const before = element.value;
        window.eval(fixture.script);
        const picker = element._flatpickr;
        if (!picker) throw new Error('Production DateMultiple initializer failed: ' + JSON.stringify(logs));
        const snapshot = () => ({
            value: element.value,
            selected: picker.selectedDates.map(date => picker.formatDate(date, 'Y-m-d')),
            alternate: picker.altInput ? picker.altInput.value : null,
            readOnly: element.readOnly,
            isOpen: picker.isOpen,
            native: Object.fromEntries(new window.FormData(element.form)),
            jquery: window.jQuery(element.form).serialize(),
        });
        const initialized = snapshot();
        const clicks = [];
        for (const action of fixture.actions) {
            if (action === 'clear') {
                picker.open();
                const button = picker.calendarContainer.querySelector('.shortcut-buttons-flatpickr-button');
                if (!button) throw new Error('Missing shipped Clear button');
                button.dispatchEvent(new window.MouseEvent('click', {bubbles: true, cancelable: true}));
                continue;
            }
            const date = picker.parseDate(action, 'Y-m-d');
            picker.jumpToDate(date);
            const day = [...picker.daysContainer.querySelectorAll('.flatpickr-day')]
                .find(node => node.dateObj && node.dateObj.getTime() === date.getTime());
            if (!day) throw new Error('Missing calendar day ' + action);
            clicks.push({date: action, disabled: day.classList.contains('flatpickr-disabled')});
            // Real day events exercise the shipped widget's isEnabled path; setDate does not.
            day.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        }
        results.push({
            before, initialized, final: snapshot(), clicks, logs,
            unexpectedExecution: Boolean(window.unexpectedExecution),
            clearButton: picker.calendarContainer.querySelector('.shortcut-buttons-flatpickr-button')?.textContent,
            config: {
                mode: picker.config.mode,
                dateFormat: picker.config.dateFormat,
                locale: picker.config.locale,
                month: picker.l10n.months.longhand[9],
                firstDayOfWeek: picker.l10n.firstDayOfWeek,
                minDate: picker.config.minDate ? picker.formatDate(picker.config.minDate, 'Y-m-d') : null,
                maxDate: picker.config.maxDate ? picker.formatDate(picker.config.maxDate, 'Y-m-d') : null,
                conjunction: picker.config.conjunction,
                allowInput: picker.config.allowInput,
                weekNumbers: picker.config.weekNumbers,
                showMonths: picker.config.showMonths,
                defaultHour: picker.config.defaultHour,
                pluginCount: picker.config.plugins.length,
            },
        });
    } finally {
        window.close();
    }
}
process.stdout.write(JSON.stringify(results));
