<?php

/**
 * Standalone ResourceGenerator temporal/default regression; no Composer or database needed.
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
    private $notnull;

    public function __construct($type, $default, $notnull = true)
    {
        $this->type = $type;
        $this->default = $default;
        $this->notnull = $notnull;
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

    public function getNotnull()
    {
        return $this->notnull;
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
// required date/time fields, which retain their generated current value.
foreach ([
    'boolean' => 'switch', 'bool' => 'switch',
    'json' => 'text', 'array' => 'text', 'object' => 'text',
    'string' => 'text', 'custom_type' => 'text',
    'integer' => 'number', 'bigint' => 'number', 'smallint' => 'number',
    'timestamp' => 'number', 'decimal' => 'decimal', 'float' => 'decimal',
    'real' => 'decimal', 'text' => 'textarea', 'blob' => 'textarea',
] as $type => $field) {
    foreach ([true, false] as $notnull) {
        foreach ([null, '', 0, '0'] as $default) {
            $cases[] = [$type, $default, $field, null, $notnull, null];
        }
    }
}
// Expected runtime values are explicit, so byte parity cannot hide a type change.
foreach ([
    ['integer', '42', 'number', '42', 42],
    ['smallint', '-2', 'number', '-2', -2],
    ['timestamp', '42', 'number', '42', 42],
    ['decimal', '0.0', 'decimal', '0.0', 0.0],
    ['float', '1.25', 'decimal', '1.25', 1.25],
    ['boolean', '1', 'switch', '1', 1],
    ['string', 'hello', 'text', "'hello'", 'hello'],
    ['custom_type', 'hello', 'text', "'hello'", 'hello'],
    ['text', "'hello'", 'textarea', "'hello'", 'hello'],
    ['blob', "'hello'", 'textarea', "'hello'", 'hello'],
    ['string', '2024-02-29', 'text', "'2024-02-29'", '2024-02-29'],
    ['custom_type', '23:59:59', 'text', "'23:59:59'", '23:59:59'],
    ['datetime_immutable', '2024-02-29 12:34:56', 'text', "'2024-02-29 12:34:56'", '2024-02-29 12:34:56'],
    ['text', "'2024-02-29'", 'textarea', "'2024-02-29'", '2024-02-29'],
] as $case) {
    foreach ([true, false] as $notnull) {
        $cases[] = [$case[0], $case[1], $case[2], $case[3], $notnull, $case[4]];
    }
}

$validDates = [
    '0001-01-01', '0099-12-31', '0100-02-28', '0400-02-29', '1000-01-01',
    '1900-02-28', '2000-02-29', '2004-02-29', '2024-04-30', '2024-12-31', '9999-12-31',
];
$validTimes = ['00:00:00', '00:00:01', '01:02:03', '12:34:56', '23:59:59'];
$validDatetimes = [];
foreach ($validDates as $date) {
    foreach (['00:00:00', '23:59:59'] as $time) {
        $validDatetimes[] = $date.' '.$time;
    }
}
$validDatetimes[] = '2024-02-29 12:34:56';

$invalidDates = [
    '0000-00-00', '0000-01-01', '0000-02-29', '2024-00-01', '2024-13-01',
    '2024-01-00', '2024-01-32', '2024-04-31', '2024-06-31', '2024-09-31', '2024-11-31',
    '2023-02-29', '1900-02-29', '2100-02-29', '2024-02-30', '2024-02-31',
    '1-01-01', '001-01-01', '10000-01-01', '-0001-01-01', '+2024-01-01',
    '2024-2-29', '2024-02-9', '2024/02/29', '20240229', '2024-02-29Z',
    '２０２４-０２-２９', '٢٠٢٤-٠٢-٢٩',
];
$invalidTimes = [
    '24:00:00', '23:60:00', '23:59:60', '99:99:99', '-01:00:00', '+01:00:00',
    '1:02:03', '01:2:03', '01:02:3', '001:02:03', '01:02', '010203', '01.02.03',
    '12:34:56.0', '12:34:56.123456', '12:34:56Z', '12:34:56+00:00', '12:34:56-05:00',
    '12:34:56 UTC', '１２:３４:５６',
];
$invalidDatetimes = [
    '2024-02-29T12:34:56', '2024-02-29t12:34:56', '2024-02-29  12:34:56',
    "2024-02-29\t12:34:56", "2024-02-29\n12:34:56", '2024-02-29 12:34',
];
foreach ($invalidDates as $date) {
    $invalidDatetimes[] = $date.' 12:34:56';
}
foreach ($invalidTimes as $time) {
    $invalidDatetimes[] = '2024-02-29 '.$time;
}

// Strings that merely resemble literals must remain current-value scaffolding.
// __toString must not turn non-string metadata into a recognized literal.
$stringable = new class {
    public function __toString()
    {
        return '2024-02-29';
    }
};
$rejectedCommon = [
    null, '', 0, 1, -1, 0.0, 1.25, true, false, [], ['2024-02-29'],
    new stdClass(), $stringable, new DateTimeImmutable('2024-02-29'),
    'NULL', 'null', '0', 'now', 'today', 'tomorrow',
    'CURRENT_TIMESTAMP', 'current_timestamp()', 'CURRENT_TIMESTAMP(6)',
    'CURRENT_DATE', 'CURRENT_DATE()', 'CURRENT_TIME', 'CURRENT_TIME()', 'NOW()',
    '(CURRENT_TIMESTAMP)', 'date("Y-m-d")', "'); phpinfo(); //",
    "2000-01-01'); throw new RuntimeException('must not execute'); //",
];
$validByType = ['date' => $validDates, 'time' => $validTimes, 'datetime' => $validDatetimes];
$invalidByType = ['date' => $invalidDates, 'time' => $invalidTimes, 'datetime' => $invalidDatetimes];
$formats = ['date' => 'Y-m-d', 'time' => 'H:i:s', 'datetime' => 'Y-m-d H:i:s'];
foreach ($formats as $type => $format) {
    $invalid = array_merge($rejectedCommon, $invalidByType[$type]);
    foreach ($validByType as $otherType => $literals) {
        if ($otherType !== $type) {
            // A valid literal of a different temporal type is not coerced.
            $invalid[] = $literals[0];
            $invalid[] = $literals[count($literals) - 1];
        }
    }
    $sample = $validByType[$type][0];
    foreach ([' ', "\t", "\n", "\r", "\0", "\xc2\xa0"] as $whitespace) {
        $invalid[] = $whitespace.$sample;
        $invalid[] = $sample.$whitespace;
    }
    foreach (["'".$sample."'", '"'.$sample.'"', "'".$sample."'::".$type,
        "CAST('".$sample."' AS ".strtoupper($type).')', '('.$sample.')',
        "DATE '".$sample."'", $sample.' -- comment', $sample.'/*comment*/'] as $sql) {
        $invalid[] = $sql;
    }
    foreach ([true, false] as $notnull) {
        foreach ($validByType[$type] as $default) {
            $cases[] = [$type, $default, $type, var_export($default, true), $notnull, $default];
        }
        foreach ($invalid as $default) {
            $expression = !$notnull && $default === null ? null : "date('".$format."')";
            $cases[] = [$type, $default, $type, $expression, $notnull, null];
        }
    }
}

$failures = [];
foreach ($cases as $case) {
    list($type, $default, $field, $expression) = $case;
    $notnull = $case[4];
    $expectedValue = $case[5];
    $label = $type.' default '.var_export($default, true).' notnull '.var_export($notnull, true);
    try {
        $generator = new NullDefaultGenerator([new NullDefaultColumn($type, $default, $notnull)]);
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
        $before = time();
        eval($source);
        $after = time();
        if ($expression === null) {
            if ($form->defaults !== []) {
                throw new RuntimeException('Absent/empty/zero default was not omitted');
            }
        } elseif (isset($formats[$type]) && $expectedValue === null) {
            if ($form->defaults !== [date($formats[$type], $before)]
                && $form->defaults !== [date($formats[$type], $after)]) {
                throw new RuntimeException('Existing current-value scaffolding changed');
            }
        } elseif ($form->defaults !== [$expectedValue]) {
            throw new RuntimeException('Default value or PHP type changed: '.var_export($form->defaults, true));
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
echo 'PASS all '.count($cases).' temporal/default source and runtime-value cases.'.PHP_EOL;
