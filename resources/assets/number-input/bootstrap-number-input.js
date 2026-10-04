/* ========================================================================
 * bootstrap-spin - v1.0
 * https://github.com/wpic/bootstrap-spin
 * ========================================================================
 * Copyright 2014 WPIC, Hamed Abdollahpour
 * Modified for laravel-admin-next: exact integer comparisons and unit steps.
 *
 * ========================================================================
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ========================================================================
 */

(function($) {

    // Compare and step integers as decimal text, including values above 2^53.
    function integerText(value) {
        var match = String(value).match(/^\s*([+-]?)(\d+)\s*$/);
        if (!match) {
            return null;
        }
        var digits = match[2].replace(/^0+/, '') || '0';
        return match[1] === '-' && digits !== '0' ? '-' + digits : digits;
    }

    function compareIntegers(left, right) {
        var leftNegative = left.charAt(0) === '-';
        var rightNegative = right.charAt(0) === '-';
        if (leftNegative !== rightNegative) {
            return leftNegative ? -1 : 1;
        }
        var a = leftNegative ? left.slice(1) : left;
        var b = rightNegative ? right.slice(1) : right;
        var order = a.length === b.length ? (a === b ? 0 : (a < b ? -1 : 1)) : (a.length < b.length ? -1 : 1);
        return leftNegative ? -order : order;
    }

    function stepInteger(value, direction) {
        var negative = value.charAt(0) === '-';
        var digits = (negative ? value.slice(1) : value).split('');
        if (value === '0') {
            return direction < 0 ? '-1' : '1';
        }
        var carry = negative ? -direction : direction;
        for (var i = digits.length - 1; i >= 0 && carry !== 0; i--) {
            var digit = Number(digits[i]) + carry;
            digits[i] = String((digit + 10) % 10);
            carry = digit < 0 ? -1 : (digit > 9 ? 1 : 0);
        }
        if (carry > 0) {
            digits.unshift('1');
        }
        return integerText((negative ? '-' : '') + digits.join(''));
    }

    $.fn.bootstrapNumber = function(options) {

        var settings = $.extend({
            upClass: 'default',
            downClass: 'default',
            center: true
        }, options);

        return this.each(function(e) {
            var self = $(this);
            var clone = self.clone();

            var min = self.attr('min');
            var max = self.attr('max');
            var integerMin = integerText(min);
            var integerMax = integerText(max);

            function setText(n, numericFallback) {
                n = isNaN(n) ? 0 : n;
                var integer = integerText(n);
                // Noninteger bounds retain the event's legacy string/number comparison.
                if (min && (integer !== null && integerMin !== null ? compareIntegers(integer, integerMin) < 0 : (numericFallback ? Number(n) : n) < min)) {
                    n = min;
                } else if (max && (integer !== null && integerMax !== null ? compareIntegers(integer, integerMax) > 0 : (numericFallback ? Number(n) : n) > max)) {
                    n = max;
                }
                clone.val(n);
            }

            var group = $("<div class='input-group'></div>");
            var down = $("<button type='button'>-</button>").attr('class', 'btn btn-' + settings.downClass).click(function() {
                var integer = integerText(clone.val());
                setText(integer === null ? parseInt(clone.val(), 10) - 1 : stepInteger(integer, -1), true);
                clone.focus().trigger('change');
            });
            var up = $("<button type='button'>+</button>").attr('class', 'btn btn-' + settings.upClass).click(function() {
                var integer = integerText(clone.val());
                setText(integer === null ? parseInt(clone.val(), 10) + 1 : stepInteger(integer, 1), true);
                clone.focus().trigger('change');
            });
            $("<span class='input-group-btn'></span>").append(down).appendTo(group);
            clone.appendTo(group);
            if (clone) {
                clone.css('text-align', 'center');
            }
            $("<span class='input-group-btn'></span>").append(up).appendTo(group);

            // remove spins from original
            clone.prop('type', 'text').keydown(function(e) {
                if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 110, 190]) !== -1 || (e.keyCode == 65 && e.ctrlKey === true) || (e.keyCode >= 35 && e.keyCode <= 39)) {
                    return;
                }
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            }).keyup(function(event) {
                var n = clone.val().match(/\-?\d+/) || [0];
                setText(n[0]);
                clone.trigger('change');
            }).blur(function(e) {
                var c = String.fromCharCode(e.which);
                var integer = integerText(clone.val());
                var n = integer === null ? parseInt(clone.val() + c, 10) : integer;
                setText(n, true);
                clone.trigger('change');
            });


            self.replaceWith(group);
        });
    };
}(jQuery));
