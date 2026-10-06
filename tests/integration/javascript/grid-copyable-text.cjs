'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

(async function () {
    const payload = JSON.parse(fs.readFileSync(0, 'utf8'));
    const dom = new JSDOM('<!doctype html><html><body>' + payload.html + '</body></html>', {
        runScripts: 'outside-only', url: 'https://offline.invalid/grid-copyable-text',
    });
    const w = dom.window;
    try {
        const errors = [];
        w.addEventListener('error', event => errors.push(String(event.error || event.message)));
        const assets = path.resolve(__dirname, '../../../resources/assets/AdminLTE');
        w.eval(fs.readFileSync(payload.jquery === 'shipped'
            ? path.join(assets, 'plugins/jQuery/jQuery-2.1.4.min.js') : require.resolve('jquery'), 'utf8'));
        w.eval(fs.readFileSync(path.join(assets, 'bootstrap/js/bootstrap.min.js'), 'utf8'));
        w.eval(fs.readFileSync(path.join(assets, 'plugins/iCheck/icheck.min.js'), 'utf8'));
        const $ = w.jQuery;
        const copies = [];
        const afterClicks = [];
        let commandResult = true;
        // Observe native DOM selection at the real handler's clipboard boundary.
        // This does not claim a browser/OS clipboard write succeeded.
        w.document.execCommand = command => {
            assert.equal(command, 'copy');
            const temp = w.document.body.lastElementChild;
            const selection = w.getSelection();
            const range = selection.rangeCount ? selection.getRangeAt(0) : null;
            const isInput = temp.tagName === 'INPUT' || temp.tagName === 'TEXTAREA';
            copies.push({
                selectedText: isInput ? temp.value.slice(temp.selectionStart, temp.selectionEnd) : selection.toString(),
                elementText: isInput ? temp.value : temp.textContent,
                selectionInsideTemporaryElement: !!range && temp.contains(range.commonAncestorContainer),
                editable: temp.getAttribute('contenteditable'),
                position: temp.style.position, left: temp.style.left, whiteSpace: temp.style.whiteSpace,
                commandResult,
            });
            return commandResult;
        };
        const scriptDocument = new JSDOM(payload.scripts);
        const scripts = Array.from(scriptDocument.window.document.querySelectorAll('script'))
            .map(script => script.textContent).filter(script => script.includes('grid-column-copyable'));
        scriptDocument.window.close();
        assert.equal(scripts.length, 1, 'one actual emitted ready wrapper');
        w.eval(scripts[0]);
        w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
        w.dispatchEvent(new w.Event('load'));
        await new Promise(resolve => w.setTimeout(resolve, 10));
        const buttons = Array.from(w.document.querySelectorAll('.grid-column-copyable'));
        const displayNodes = buttons.map(button => button.parentElement.querySelector('.copyable-visible') || button.nextSibling);
        const controls = buttons.map(button => {
            const formatted = button.parentElement.querySelector('.copyable-visible');
            return {
                attribute: button.getAttribute('data-content'), class: button.className,
                title: button.getAttribute('title'), placement: button.getAttribute('data-placement'),
                href: button.getAttribute('href'), copyIcons: button.querySelectorAll('i.fa.fa-copy').length,
                displayTag: formatted ? formatted.tagName : null,
                displayText: formatted ? formatted.textContent : button.nextSibling.textContent.replace(/^\u00a0/, ''),
                displayHtml: formatted ? formatted.outerHTML : button.nextSibling.textContent,
            };
        });
        // The existing delegated binding must stay within its Grid table.
        const outside = buttons[0].cloneNode(true);
        w.document.body.append(outside);
        outside.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
        const outsideCopyCalls = copies.length;
        outside.remove();
        for (let index = 0; index < payload.clicks.length; index++) {
            const buttonIndex = payload.clicks[index];
            const button = buttons[buttonIndex];
            commandResult = index === payload.clicks.length - 1 ? payload.lastResult : true;
            const previous = w.document.createRange();
            previous.selectNodeContents(displayNodes[buttonIndex]);
            w.getSelection().removeAllRanges();
            w.getSelection().addRange(previous);
            button.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
            const formatted = button.parentElement.querySelector('.copyable-visible');
            const displayHtml = formatted ? formatted.outerHTML : displayNodes[buttonIndex].textContent;
            afterClicks.push({
                remainingTemporaryElements: w.document.querySelectorAll('body > [contenteditable], body > input, body > textarea').length,
                remainingRanges: w.getSelection().rangeCount,
                displayUnchanged: displayHtml === controls[buttonIndex].displayHtml,
                tooltipShown: button.parentElement.querySelector('.tooltip.in .tooltip-inner')?.textContent === 'Copied!',
            });
        }
        process.stdout.write(JSON.stringify({
            errors, jqueryVersion: $.fn.jquery, emittedScriptCount: scripts.length,
            outsideCopyCalls, controls, copies, afterClicks,
        }));
    } finally {
        w.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
