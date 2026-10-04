<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class Slider extends Field
{
    protected static $css = [
        '/vendor/laravel-admin/AdminLTE/plugins/ionslider/ion.rangeSlider.css',
        '/vendor/laravel-admin/AdminLTE/plugins/ionslider/ion.rangeSlider.skinNice.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/ionslider/ion.rangeSlider.min.js',
    ];

    protected $options = [
        'type'     => 'single',
        'prettify' => false,
        'hasGrid'  => true,
    ];

    public function render()
    {
        $option = json_encode($this->options);

        $this->script = <<<JS
$('{$this->getElementClassSelector()}').each(function () {
    var slider = $(this), options = $option;
    var value = slider.data('from');
    if (!slider.data('isActive') && (slider.data('type') || options.type) === 'double' && typeof value === 'string') {
        var range = /^(0|-?[1-9][0-9]*);(0|-?[1-9][0-9]*)$/.exec(value);
        if (range && range[0] === value && String(Number(range[1])) === range[1] && String(Number(range[2])) === range[2]) {
            // The shipped widget reads input.value as bounds, so restore its endpoints through data instead.
            slider.data('from', Number(range[1]));
            if (typeof slider.data('to') === 'undefined') {
                slider.data('to', Number(range[2]));
            }
        }
    }
    slider.ionRangeSlider(options);
});
JS;

        return parent::render();
    }
}
