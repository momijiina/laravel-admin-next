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
            $('{$class['start']}').datetimepicker($startOptions);
            $('{$class['end']}').datetimepicker($endOptions);
            $("{$class['start']}").on("dp.change", function (e) {
                $('{$class['end']}').data("DateTimePicker").minDate(e.date);
            });
            $("{$class['end']}").on("dp.change", function (e) {
                $('{$class['start']}').data("DateTimePicker").maxDate(e.date);
            });
EOT;

        return parent::render();
    }
}
