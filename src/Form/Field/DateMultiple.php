<?php

namespace Encore\Admin\Form\Field;

class DateMultiple extends Text
{
    protected static $css = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.min.css',
        '/vendor/laravel-admin/flatpickr/dist/shortcut-buttons-flatpickr/themes/light.min.css',

    ];

    protected static $js = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.js',
        '/vendor/laravel-admin/flatpickr/dist/shortcut-buttons-flatpickr/shortcut-buttons-flatpickr.min.js',
        '/vendor/laravel-admin/flatpickr/dist/l10n/zh.js',
    ];

    // Flatpickr tokens, unlike the Moment tokens used by Date/Datetime.
    protected $format = 'Y-m-d';

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

    public function render()
    {
        // Explicit native options take precedence over the format()/locale defaults.
        // Only JSON data is supported here; strings are never evaluated as callbacks.
        $options = json_encode(array_merge([
            'dateFormat' => $this->format,
            'locale' => 'zh',
        ], $this->options));

        $this->script = "$('{$this->getElementClassSelector()}').flatpickr($.extend({}, {$options}, {mode: 'multiple', plugins: [
            ShortcutButtonsPlugin({
              button: {
                label: 'Clear',
              },
              onClick: (index, fp) => {
                fp.clear();
                fp.close();
              }
            })
          ]}));";

        $this->prepend('<i class="fa fa-calendar fa-fw"></i>')
            ->defaultAttribute('style', 'width: 100%');

        return parent::render();
    }
}
