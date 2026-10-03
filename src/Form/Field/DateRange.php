<?php

namespace Encore\Admin\Form\Field;

use Carbon\CarbonInterface;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Illuminate\Support\Carbon;

class DateRange extends Field
{
    protected static $css = [
        '/vendor/laravel-admin/eonasdan-bootstrap-datetimepicker/build/css/bootstrap-datetimepicker.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/moment/min/moment-with-locales.min.js',
        '/vendor/laravel-admin/eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js',
    ];

    protected $format = 'YYYY-MM-DD';

    /**
     * Column name.
     *
     * @var array
     */
    protected $column = [];

    public function __construct($column, $arguments)
    {
        $this->column['start'] = $column;
        $this->column['end'] = $arguments[0];

        array_shift($arguments);
        $this->label = $this->formatLabel($arguments);
        $this->id = $this->formatId($this->column);

        $this->options(['format' => $this->format]);
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        if ($value === '') {
            $value = null;
        }

        return $value;
    }

    /**
     * Present each default native cast endpoint in the picker's local format.
     *
     * Keep application-controlled serialization and parsing unchanged.
     */
    protected function formatDateCastValues()
    {
        if (!in_array(static::class, [self::class, DatetimeRange::class], true)
            || !$this->form instanceof Form
            || !is_array($this->value)
            || !is_string($this->options['format'] ?? null)
            || $this->options['format'] === ''
            || $this->customFormat instanceof \Closure
            || $this->callback instanceof \Closure
            || isset($this->options['parseInputDate'])
            || !empty($this->options['timeZone'])) {
            return;
        }

        $model = $this->form->model();
        $casts = $model->getCasts();

        foreach ($this->column as $endpoint => $column) {
            if (!is_string($column)
                || strpos($column, '.') !== false
                || !is_string($this->value[$endpoint] ?? null)
                || !in_array($casts[$column] ?? null, ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true)) {
                continue;
            }

            $date = $model->getAttribute($column);

            if ($date instanceof CarbonInterface && $this->value[$endpoint] === $date->toJSON()) {
                // Rendering must not change either model attribute or its timezone.
                $this->value[$endpoint] = Carbon::instance($date)
                    ->setTimezone(config('app.timezone'))
                    ->locale($this->options['locale'])
                    ->isoFormat($this->options['format']);
            }
        }
    }

    public function render()
    {
        $this->options['locale'] = array_key_exists('locale', $this->options) ? $this->options['locale'] : config('app.locale');

        $this->formatDateCastValues();

        $startOptions = json_encode($this->options);
        $endOptions = json_encode($this->options + ['useCurrent' => false]);

        $class = $this->getElementClassSelector();

        $this->script = <<<EOT
            (function () {
                var start = $('{$class['start']}'), end = $('{$class['end']}');
                var fresh = !start.data('DateTimePicker') && !end.data('DateTimePicker');
                $('{$class['start']}').datetimepicker($startOptions);
                $('{$class['end']}').datetimepicker($endOptions);

                // Only synchronize an unambiguous pair, using the widget's parsed dates.
                if (fresh && start.length === 1 && end.length === 1) {
                    var startPicker = start.data('DateTimePicker'), endPicker = end.data('DateTimePicker');
                    var startDate = startPicker.date(), endDate = endPicker.date();
                    var startConfig = startPicker.options(), endConfig = endPicker.options();
                    // Custom parsers/timezones retain their existing initialization contract.
                    if (!startConfig.parseInputDate && !endConfig.parseInputDate &&
                        startConfig.timeZone === 'Etc/UTC' && endConfig.timeZone === 'Etc/UTC' &&
                        !(startDate && endDate && startDate.isAfter(endDate))) {
                        var synchronize = function (picker, method, date) {
                            if (!date || !date.isValid()) return;
                            var minimum = picker.minDate(), maximum = picker.maxDate();
                            if ((minimum && date.isBefore(minimum)) || (maximum && date.isAfter(maximum))) return;
                            // Bound setters can fill an empty input when useCurrent is enabled.
                            var useCurrent = picker.useCurrent();
                            try {
                                picker.useCurrent(false);
                                picker[method](date);
                            } finally {
                                picker.useCurrent(useCurrent);
                            }
                        };
                        synchronize(endPicker, 'minDate', startDate);
                        synchronize(startPicker, 'maxDate', endDate);
                    }
                }

                start.off('dp.change.adminDateRange').on('dp.change.adminDateRange', function (e) {
                    end.data('DateTimePicker').minDate(e.date);
                });
                end.off('dp.change.adminDateRange').on('dp.change.adminDateRange', function (e) {
                    start.data('DateTimePicker').maxDate(e.date);
                });
            })();
EOT;

        return parent::render();
    }
}
