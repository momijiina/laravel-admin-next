<?php

namespace Encore\Admin\Form\Field;

use Carbon\CarbonInterface;
use Encore\Admin\Form;
use Illuminate\Support\Carbon;

class Date extends Text
{
    protected static $css = [
        '/vendor/laravel-admin/eonasdan-bootstrap-datetimepicker/build/css/bootstrap-datetimepicker.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/moment/min/moment-with-locales.min.js',
        '/vendor/laravel-admin/eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js',
    ];

    protected $format = 'YYYY-MM-DD';

    public function format($format)
    {
        $this->format = $format;

        return $this;
    }

    public function prepare($value)
    {
        if ($value === '') {
            $value = null;
        }

        return $value;
    }

    /**
     * Format default Eloquent date serialization for the local date picker.
     *
     * Leave custom casts, formatters and ordinary strings under application control.
     */
    protected function formatDateCastValue()
    {
        if (!in_array(static::class, [self::class, Datetime::class], true)
            || !$this->form instanceof Form
            || !is_string($this->column)
            || !is_string($this->value)
            || $this->customFormat instanceof \Closure
            || $this->callback instanceof \Closure
            || isset($this->options['parseInputDate'])
            || !empty($this->options['timeZone'])) {
            return;
        }

        $model = $this->form->model();
        $cast = $model->getCasts()[$this->column] ?? null;

        if (!in_array($cast, ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true)) {
            return;
        }

        $date = $model->getAttribute($this->column);

        if ($date instanceof CarbonInterface && $this->value === $date->toJSON()) {
            // Work on a copy: rendering must not change the model or its timezone.
            $this->value = Carbon::instance($date)
                ->setTimezone(config('app.timezone'))
                ->locale($this->options['locale'])
                ->isoFormat($this->format);
        }
    }

    public function render()
    {
        $this->options['format'] = $this->format;
        $this->options['locale'] = array_key_exists('locale', $this->options) ? $this->options['locale'] : config('app.locale');
        $this->options['allowInputToggle'] = true;

        $this->formatDateCastValue();

        $this->script = "$('{$this->getElementClassSelector()}').parent().datetimepicker(".json_encode($this->options).');';

        $this->prepend('<i class="fa fa-calendar fa-fw"></i>')
            ->defaultAttribute('style', 'width: 110px');

        return parent::render();
    }
}
