const moment = require('../../../resources/assets/moment/min/moment-with-locales.min.js');

// Use options extracted from the actual rendered picker setup. This reproduces
// datetimepicker's fixed-format, non-strict parsing and rewriting, not its DOM UI.
const values = {};
for (const {name, value, options} of JSON.parse(process.argv[2])) {
    values[name] = value === '' ? '' : moment(value, [options.format], options.locale, false).format(options.format);
}
process.stdout.write(JSON.stringify(values));
