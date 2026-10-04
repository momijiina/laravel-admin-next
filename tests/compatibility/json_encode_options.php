<?php

/** Run: php tests/compatibility/json_encode_options.php */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__.'/../../src/helpers.php';

$count = 0;
$check = function ($expected, $actual, $label) use (&$count) {
    if ($expected !== $actual) {
        throw new RuntimeException($label.' differs: '.var_export($actual, true));
    }
    ++$count;
};

// JSON-compatible data retains native encoding, including types, keys and escaping.
$ordinary = [[], [null, false, true, 0, '0', 0.0, -1, 1.5, '', '日本語', "a\nb\t\0", 'a/b', '"quoted"', '\\'],
    ['null' => null, 'nested' => ['empty' => [], 7 => false, 'zero' => 0]],
    ['function() {}' => 'literal key', 'space' => ' function() {}', 'named' => 'function named() {}',
        'spaced' => 'function () {}', 'arrow' => '() => 1', 'body' => 'text function() {}'],
];
foreach ($ordinary as $options) {
    $check(json_encode($options), json_encode_options($options), 'Ordinary JSON');
    $check(['original' => [], 'toReplace' => [], 'options' => $options], prepare_options($options), 'Preparation without callbacks');
}

$letters = 'function(chrs){return /^[A-Z]$/.test(chrs);}';
$digits = 'function(chrs){return /^[0-9]$/.test(chrs);}';
$options = ['definitions' => ['X' => ['validator' => $letters], 'Y' => ['validator' => $digits]]];
$copy = $options;
$check('{"definitions":{"X":{"validator":'.$letters.'},"Y":{"validator":'.$digits.'}}}', json_encode_options($options), 'Repeated nested leaf names');
$check($copy, $options, 'Caller options are unchanged');
$check('{"first":['.$letters.'],"second":['.$digits.'],"0":'.$letters.'}',
    json_encode_options(['first' => [$letters], 'second' => [$digits], 0 => $letters]), 'Repeated numeric keys and callback text');

// Both values and object keys that resemble old or new internal markers stay literal.
$literals = ['%validator%', '%0%', '%__laravel_admin_callback_0__%', '%__laravel_admin_callback_1__%',
    '"%__laravel_admin_callback_2__%"', '日本語/%validator%'];
$options = ['validator' => $letters, 'other' => ['validator' => $digits], 'literals' => $literals,
    '%__laravel_admin_callback_2__%' => 'ordinary key', '%validator%' => 'old ordinary key'];
$check('{"validator":'.$letters.',"other":{"validator":'.$digits.'},"literals":'.json_encode($literals).',"%__laravel_admin_callback_2__%":"ordinary key","%validator%":"old ordinary key"}',
    json_encode_options($options), 'Literal marker-like values and keys');
$prepared = prepare_options($options);
$check(['original', 'toReplace', 'options'], array_keys($prepared), 'Public preparation result shape');
$check([$letters, $digits], $prepared['original'], 'Callback traversal order');
$check(2, count(array_unique($prepared['toReplace'])), 'Distinct callback markers');
foreach ($prepared['toReplace'] as $marker) {
    $check(1, substr_count(json_encode($prepared['options']), $marker), 'Only one reserved marker occurrence');
}
$check(json_encode_options($options), strtr(json_encode($prepared['options']), array_combine($prepared['toReplace'], $prepared['original'])), 'Preparation composes with encoding');

$first = 'function(){return "%__laravel_admin_callback_1__%";}';
$second = 'function(){return "%validator%";}';
$check('{"first":'.$first.',"second":'.$second.'}', json_encode_options(['first' => $first, 'second' => $second]), 'Inserted callbacks are not processed again');
$check('{"validator":'.$digits.'}', json_encode_options(['validator' => $digits]), 'Separate calls have no shared state');


// JSON object leaves remain data, even when their output equals callback markers.
$marker = '%__laravel_admin_callback_0__%';
$object = (object) [$marker => $marker, 'literal' => 'function(){return "data";}', 'empty' => (object) []];
$options = ['callback' => $letters, 'object' => $object, 'after' => ['callback' => $digits]];
$check('{"callback":'.$letters.',"object":'.json_encode($object).',"after":{"callback":'.$digits.'}}', json_encode_options($options), 'Ordinary object literal strings and keys');
$object->nested = (object) ['values' => [null, false, 0, $marker, ['braces' => '{[,:]}', 'quoted' => '"\\']]];
$check('{"object":'.json_encode($object).',"callback":'.$letters.'}', json_encode_options(['object' => $object, 'callback' => $letters]), 'Object subtree preceding callback');

foreach ([null, false, 0, 1.5, $marker, 'function(){return "data";}', [], (object) [],
    [$marker, ['literal' => $marker]], $object] as $output) {
    $serializable = new class($output) implements JsonSerializable {
        public $calls = 0;
        private $output;
        public function __construct($output) { $this->output = $output; }
        public function jsonSerialize(): mixed { ++$this->calls; return $this->output; }
    };
    $expected = '{"before":'.$letters.',"data":'.json_encode($output).',"after":'.$digits.'}';
    $check($expected, json_encode_options(['before' => $letters, 'data' => $serializable, 'after' => $digits]), 'JsonSerializable leaf output');
    $check(1, $serializable->calls, 'JsonSerializable invoked exactly once');
}

// The tokenizer must retain native key escaping, list/object shape and empty values.
$options = ['"\\日本語' => ['empty' => [], 'literal' => 'a\\"b{[,:]}', 7 => $letters],
    3 => [2 => $digits], 'object' => (object) [], 'last' => [[], $letters]];
$check('{'.json_encode('"\\日本語').':{"empty":[],"literal":'.json_encode('a\\"b{[,:]}').',"7":'.$letters.'},"3":{"2":'.$digits.'},"object":{},"last":[[],'.$letters.']}',
    json_encode_options($options), 'Escaped keys and mixed structural shapes');


foreach ([str_repeat('x', 10000), str_repeat('日本語"\\\n{[,:]}', 10000)] as $literal) {
    $check('{"callback":'.$letters.',"label":'.json_encode($literal).',"after":'.$digits.'}',
        json_encode_options(['callback' => $letters, 'label' => $literal, 'after' => $digits]), 'Long literal string token');
}

$cyclic = new stdClass();
$cyclic->self = $cyclic;
$check('', json_encode_options(['callback' => $letters, 'data' => $cyclic]), 'Native cyclic object failure return');
$check(JSON_ERROR_RECURSION, json_last_error(), 'Native cyclic object failure code');
unset($cyclic->self);


$stringable = new class {
    public $label = '%__laravel_admin_callback_0__%';
    public function __toString(): string { throw new RuntimeException('Object must not be coerced into callback text'); }
};
$check('{"callback":'.$letters.',"data":'.json_encode($stringable).'}',
    json_encode_options(['callback' => $letters, 'data' => $stringable]), 'Stringable object remains native JSON data');
$failure = new RuntimeException('Serializer failed');
$throwing = new class($failure) implements JsonSerializable {
    public $calls = 0;
    private $failure;
    public function __construct($failure) { $this->failure = $failure; }
    public function jsonSerialize(): mixed { ++$this->calls; throw $this->failure; }
};
try {
    json_encode_options(['callback' => $letters, 'data' => $throwing]);
    throw new LogicException('Expected serializer exception');
} catch (RuntimeException $caught) {
    $check($failure, $caught, 'Serializer exception identity');
    $check(1, $throwing->calls, 'Throwing serializer invoked exactly once');
}
$deep = ['callback' => $letters];
for ($level = 0; $level < 512; ++$level) {
    $deep = [$deep];
}
$check('', json_encode_options($deep), 'Native excessive-depth failure return');
$check(JSON_ERROR_DEPTH, json_last_error(), 'Native excessive-depth failure code');

// Keep json_encode's default failure policy: helper returns the legacy empty string.
foreach ([['invalid' => "\xB1"], ['number' => INF], ['number' => NAN], ['callback' => $letters, 'invalid' => "\xB1"]] as $options) {
    $expected = json_encode($options);
    $error = json_last_error();
    $check(false, $expected, 'Native JSON rejects invalid data');
    $check('', json_encode_options($options), 'Legacy helper failure return');
    $check($error, json_last_error(), 'Native JSON failure code');
}
restore_error_handler();
echo 'Widget option serialization: '.$count." checks passed without diagnostics.\n";
