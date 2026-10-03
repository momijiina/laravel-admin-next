const moment = require('../../../resources/assets/moment/min/moment-with-locales.min.js');

// Match datetimepicker's fixed-format, non-strict parsing and input rewriting.
process.stdout.write(JSON.stringify([
    moment(process.argv[2], ['YYYY-MM-DD'], false).format('YYYY-MM-DD'),
    moment(process.argv[3], ['YYYY-MM-DD HH:mm:ss'], false).format('YYYY-MM-DD HH:mm:ss'),
]));
