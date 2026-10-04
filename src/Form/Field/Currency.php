<?php

namespace Encore\Admin\Form\Field;

class Currency extends Text
{
    /**
     * @var string
     */
    protected $symbol = '$';

    /**
     * @var array
     */
    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/input-mask/jquery.inputmask.bundle.min.js',
    ];

    /**
     * @see https://github.com/RobinHerbots/Inputmask#options
     *
     * @var array
     */
    protected $options = [
        'alias'              => 'currency',
        'radixPoint'         => '.',
        'prefix'             => '',
        'removeMaskOnSubmit' => true,
    ];

    /**
     * Set symbol for currency field.
     *
     * @param string $symbol
     *
     * @return $this
     */
    public function symbol($symbol)
    {
        $this->symbol = $symbol;

        return $this;
    }

    /**
     * Set digits for input number.
     *
     * @param int $digits
     *
     * @return $this
     */
    public function digits($digits)
    {
        return $this->options(compact('digits'));
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        // Inputmask removes grouping/affixes on submit but retains its radix.
        $radixPoint = $this->options['radixPoint'] ?? '.';

        if (is_string($value) && is_string($radixPoint) && $radixPoint !== '' && $radixPoint !== '.') {
            $value = str_replace($radixPoint, '.', $value);
        }

        return (float) $value;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->inputmask($this->options);

        $this->prepend($this->symbol)
            ->defaultAttribute('style', 'width: 120px');

        return parent::render();
    }
}
