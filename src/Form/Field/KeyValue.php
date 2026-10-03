<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Support\Arr;

class KeyValue extends Field
{
    /**
     * @var array
     */
    protected $value = ['' => ''];

    /**
     * Fill data to the field.
     *
     * @param array $data
     *
     * @return void
     */
    public function fill($data)
    {
        $this->data = $data;

        $this->value = Arr::get($data, $this->column, $this->value);

        $this->formatValue();
    }

    /**
     * {@inheritdoc}
     */
    public function getValidator(array $input)
    {
        if ($this->validator) {
            return $this->validator->call($this, $input);
        }

        if (!is_string($this->column)) {
            return false;
        }

        $rules = $attributes = [];

        if (!$fieldRules = $this->getRules()) {
            return false;
        }

        if (!Arr::has($input, $this->column)) {
            return false;
        }

        $rules["{$this->column}.keys.*"] = 'distinct';
        $rules["{$this->column}.values.*"] = $fieldRules;
        $attributes["{$this->column}.keys.*"] = __('Key');
        $attributes["{$this->column}.values.*"] = __('Value');

        return validator($input, $rules, $this->getValidationMessages(), $attributes);
    }

    protected function setupScript()
    {
        $this->script = <<<'SCRIPT'

$('[data-admin-collection="key-value"]').each(function () {
    var root = $(this);

    // HasMany reruns child scripts after insertion. Replace only our handlers.
    root.off('.adminCollection')
        .on('click.adminCollection', '[data-collection-add]', function () {
            if (root.is('[data-collection-locked]') ||
                $(this).closest('[data-admin-collection]')[0] !== root[0]) {
                return;
            }

            var table = root.find('[data-collection-body]').filter(function () {
                return $(this).closest('[data-admin-collection]')[0] === root[0];
            });
            table.append(root.children('template').html());
        })
        .on('click.adminCollection', '[data-collection-remove]', function () {
            if (root.is('[data-collection-locked]') ||
                $(this).closest('[data-admin-collection]')[0] !== root[0]) {
                return;
            }

            $(this).closest('tr').remove();
        });
});

SCRIPT;
    }

    public function prepare($value)
    {
        // Only scalar empty markers represent zero rows; blank array elements are rows.
        if (is_array($value)) {
            foreach (['keys', 'values'] as $part) {
                if (array_key_exists($part, $value) && ($value[$part] === null || $value[$part] === '')) {
                    $value[$part] = [];
                }
            }
        }

        return array_combine($value['keys'], $value['values']);
    }

    public function render()
    {
        // HTML boolean attributes are enabled by presence, even with a false/empty value.
        $this->addVariables([
            'collectionReadonly' => array_key_exists('readonly', $this->attributes),
        ]);

        $this->setupScript();

        Admin::style('td .form-group {margin-bottom: 0 !important;}');

        return parent::render();
    }
}
