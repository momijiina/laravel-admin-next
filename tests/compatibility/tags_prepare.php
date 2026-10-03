<?php

/**
 * Run: php tests/compatibility/tags_prepare.php
 * Only Field and Arr are doubled; production Tags::prepare runs unchanged.
 * The separate integration suite exercises the real inheritance and HTTP path.
 */
namespace Encore\Admin\Form {
    class Field {}
}
namespace Illuminate\Support {
    class Arr {
        public static function isAssoc(array $value) { return !array_is_list($value); }
    }
}
namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    require __DIR__.'/../../src/Form/Field/Tags.php';

    $emptyStringable = new class {
        public function __toString(): string { return ''; }
    };
    $zeroStringable = new class {
        public function __toString(): string { return '0'; }
    };
    $values = [null, '', false, true, 0, '0', 0.0, 12, -1, 1.5, ' ', '日本',
        "\0", 'red,blue', 'red;blue', $emptyStringable, $zeroStringable];
    $cases = [[], $values, ['null' => null, 'empty' => '', 'false' => false,
        'zero' => 0, 'string-zero' => '0', 9 => 'duplicate', 15 => 'duplicate']];
    foreach ($values as $first) {
        $cases[] = [$first];
        foreach ($values as $second) {
            $cases[] = [$first, $second];
        }
    }

    $count = 0;
    foreach ($cases as $input) {
        // Suppress only the known legacy-oracle deprecation, never production diagnostics.
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_DEPRECATED && str_contains($message, 'strlen(): Passing null')) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $filtered = array_filter($input, 'strlen');
            $expected = array_is_list($filtered) ? implode(',', $filtered) : $filtered;
        } finally {
            restore_error_handler();
        }
        foreach ([[], ['|'], [';', ' ']] as $separators) {
            $field = (new \Encore\Admin\Form\Field\Tags())->separators($separators);
            if ($field->prepare($input) !== $expected) {
                throw new \RuntimeException('Ordinary preparation changed for case '.$count);
            }
            $field->pluck('name', 'id');
            if ($field->prepare($input) !== $filtered) {
                throw new \RuntimeException('Key-as-value preparation changed for case '.$count);
            }
            $field->saving(function ($value) use ($filtered) {
                if ($value !== $filtered) {
                    throw new \RuntimeException('Saving callback received changed keys/values');
                }
                return ['saved' => $value];
            });
            if ($field->prepare($input) !== ['saved' => $filtered]) {
                throw new \RuntimeException('Saving callback result changed');
            }
            ++$count;
        }
    }

    $resource = fopen('php://memory', 'r');
    // Unlike MultipleSelect, Tags never accepted scalar or null top-level input.
    foreach ([null, '', false, 0, new \stdClass(), $resource, [[]], [['nested']], [new \stdClass()], [$resource]] as $input) {
        $field = new \Encore\Admin\Form\Field\Tags();
        foreach ([function () use ($input) { return array_filter($input, 'strlen'); },
            function () use ($field, $input) { return $field->prepare($input); }] as $prepare) {
            try {
                $prepare();
                throw new \RuntimeException('Expected TypeError for invalid input');
            } catch (\TypeError $exception) {
                $operation = is_array($input) ? 'strlen()' : 'array_filter()';
                if (!str_contains($exception->getMessage(), $operation)) {
                    throw $exception;
                }
            }
        }
        ++$count;
    }
    $failure = new \RuntimeException('String conversion failed');
    $throwingStringable = new class($failure) {
        private $failure;
        public function __construct($failure) { $this->failure = $failure; }
        public function __toString(): string { throw $this->failure; }
    };
    foreach ([function () use ($throwingStringable) { return array_filter([$throwingStringable], 'strlen'); },
        function () use ($field, $throwingStringable) { return $field->prepare([$throwingStringable]); }] as $prepare) {
        try {
            $prepare();
            throw new \LogicException('Expected original conversion failure');
        } catch (\RuntimeException $exception) {
            if ($exception !== $failure) {
                throw $exception;
            }
        }
    }
    ++$count;
    fclose($resource);
    restore_error_handler();
    echo 'Tags preparation: '.$count." parity/error cases passed without production diagnostics.\n";
}
