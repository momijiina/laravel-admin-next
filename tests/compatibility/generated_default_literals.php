<?php

/**
 * Standalone ResourceGenerator literal regression; no Composer or database needed.
 * Run: php tests/compatibility/generated_default_literals.php
 * These metadata doubles isolate code generation, not schema discovery.
 */

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/../../src/Console/ResourceGenerator.php';

class DefaultLiteralColumn
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
        return new DefaultLiteralType($this->type);
    }

    public function getDefault()
    {
        return $this->default;
    }
}

class DefaultLiteralType
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

class DefaultLiteralGenerator extends \Encore\Admin\Console\ResourceGenerator
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

class DefaultLiteralForm
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
foreach (['string', 'custom_type'] as $type) {
    foreach ([
        'apostrophe' => "O'Reilly",
        'only apostrophe' => "'",
        'double quotes' => '"hello"',
        'backslashes' => 'C:\\directory\\\\server\\file',
        'trailing backslash' => 'C:\\directory\\',
        'backslash and apostrophe' => "path\\'name",
        'newlines' => "first\nsecond\r\nthird",
        'unicode' => 'こんにちは café 🐈',
        'interpolation text' => '$value ${value} {$value}',
        'numeric string' => '0123',
        'decimal string' => '12.50',
        'mixed string' => '12 cats',
        'whitespace' => '  ',
    ] as $label => $value) {
        $cases[] = [$type.' '.$label, $type, $value, [$value]];
    }
    // Preserve the existing omission of empty, null and zero string defaults.
    foreach (['', '0', 0, null] as $value) {
        $cases[] = [$type.' omitted '.var_export($value, true), $type, $value, []];
    }
    $cases[] = [$type.' integer metadata', $type, 42, ['42']];
}
foreach ([
    ['integer', '42', [42]],
    ['bigint', '123456', [123456]],
    ['smallint', '-2', [-2]],
    ['decimal', '12.5', [12.5]],
    ['float', '1.25', [1.25]],
    ['real', '-2.5', [-2.5]],
    ['boolean', '1', [1]],
    ['integer', '0', []],
    ['integer', 0, []],
    ['decimal', '0.0', [0.0]],
] as $case) {
    $cases[] = [$case[0].' numeric '.var_export($case[1], true), $case[0], $case[1], $case[2]];
}

$failures = [];
foreach ($cases as $case) {
    list($label, $type, $default, $expected) = $case;
    try {
        $generator = new DefaultLiteralGenerator([new DefaultLiteralColumn($type, $default)]);
        $source = $generator->generateForm();
        // Parse complete PHP and then execute only these fixed, local fixtures.
        token_get_all('<?php '.$source, TOKEN_PARSE);
        $form = new DefaultLiteralForm();
        eval($source);
        if ($form->defaults !== $expected) {
            throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($form->defaults, true));
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
echo 'PASS all '.count($cases).' generated defaults parse and retain their values/types.'.PHP_EOL;
