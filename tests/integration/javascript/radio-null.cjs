const fs = require('node:fs');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM(fixture.html, {runScripts: 'outside-only'});
const window = dom.window;
window.eval(fs.readFileSync(fixture.jquery, 'utf8'));
window.eval(fs.readFileSync(fixture.icheck, 'utf8'));
const $ = window.jQuery;
const form = window.document.querySelector('form');
const native = () => new window.URLSearchParams(new window.FormData(form)).toString();
const before = native();
$('input[type=radio]').iCheck({radioClass: 'iradio_minimal-blue'});
assert.equal(native(), before, 'iCheck initialization must preserve successful controls');
if (fixture.selection !== null) {
    $('input[type=radio]').iCheck('uncheck');
    for (const value of fixture.selection) {
        for (const input of form.querySelectorAll('input[type=radio]')) {
            if (input.value === value) $(input).iCheck('check');
        }
    }
}
assert.equal($(form).serialize(), native(), 'shipped jQuery and native FormData must agree');
process.stdout.write(JSON.stringify({query: native(), valid: form.checkValidity(), checked: [...form.querySelectorAll('input[type=radio]:checked')].map(input => input.value)}));
window.close();
