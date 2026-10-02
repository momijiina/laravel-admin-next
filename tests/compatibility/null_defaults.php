<?php

/**
 * Standalone ResourceGenerator null-default regression; no Composer or database needed.
 * Run: php tests/compatibility/null_defaults.php
 * These metadata doubles isolate code generation, not schema discovery.
 */

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/../../src/Console/ResourceGenerator.php';

class NullDefaultColumn
{
    private $type;
    private $default;

    public function __construct($type, $default)
    {
        $this->type = $type;
        $this->default = $default;
    }

    public function getName()
    {
        return 'example';
    }

    public function getType()
    {
        return new NullDefaultType($this->type);
    }

    public function getDefault()
    {
        return $this->default;
    }
}

class NullDefaultType
{
    private $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }
}

class NullDefaultGenerator extends \Encore\Admin\Console\ResourceGenerator
{
    protected function getModel($columns)
    {
        return $columns;
    }

    protected function getReservedColumns()
    {
        return [];
    }

    protected function getTableColumns()
    {
        return $this->model;
    }
}

class NullDefaultForm
{
    public $defaults = [];

    public function __call($method, $arguments)
    {
        if ($method === 'default') {
            $this->defaults[] = $arguments[0];
        }

        return $this;
    }
}

function __($label)
{
    return $label;
}

$cases = [];
// All supported metadata types must omit an absent database default, except
// date/time fields, which intentionally retain their generated current value.
foreach ([
    'boolean' => 'switch', 'bool' => 'switch',
    'json' => 'text', 'array' => 'text', 'object' => 'text',
    'string' => 'text', 'custom_type' => 'text',
    'integer' => 'number', 'bigint' => 'number', 'smallint' => 'number',
    'timestamp' => 'number', 'decimal' => 'decimal', 'float' => 'decimal',
    'real' => 'decimal', 'text' => 'textarea', 'blob' => 'textarea',
] as $type => $field) {
    foreach ([null, '', 0, '0'] as $default) {
        $cases[] = [$type, $default, $field, null];
    }
}
foreach ([
    ['integer', '42', 'number', '42'],
    ['smallint', '-2', 'number', '-2'],
    ['decimal', '0.0', 'decimal', '0.0'],
    ['float', '1.25', 'decimal', '1.25'],
    ['boolean', '1', 'switch', '1'],
    ['string', 'hello', 'text', "'hello'"],
    ['custom_type', 'hello', 'text', "'hello'"],
    ['text', "'hello'", 'textarea', "'hello'"],
    ['blob', "'hello'", 'textarea', "'hello'"],
] as $case) {
    $cases[] = $case;
}
foreach (['datetime' => 'Y-m-d H:i:s', 'date' => 'Y-m-d', 'time' => 'H:i:s'] as $type => $format) {
    foreach ([null, '', '2000-01-01'] as $default) {
        $cases[] = [$type, $default, $type, "date('".$format."')"];
    }
}

$failures = [];
foreach ($cases as $case) {
    list($type, $default, $field, $expression) = $case;
    $label = $type.' default '.var_export($default, true);
    try {
        $generator = new NullDefaultGenerator([new NullDefaultColumn($type, $default)]);
        $source = $generator->generateForm();
        $expected = '$form->'.$field."('example', __('Example'))";
        if ($expression !== null) {
            $expected .= '->default('.$expression.')';
        }
        $expected .= ";\r\n";
        if ($source !== $expected) {
            throw new RuntimeException('Unexpected source: '.var_export($source, true));
        }
        token_get_all('<?php '.$source, TOKEN_PARSE);
        $form = new NullDefaultForm();
        eval($source);
        if ($expression === null && $form->defaults !== []) {
            throw new RuntimeException('Absent/empty/zero default was not omitted');
        }
        echo 'PASS '.$label.PHP_EOL;
    } catch (Throwable $error) {
        $failures[] = $label.': '.$error->getMessage();
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL '.$failure.PHP_EOL);
}
if ($failures) {
    exit(1);
}
echo 'PASS all '.count($cases).' null-default and unchanged-output cases.'.PHP_EOL;
