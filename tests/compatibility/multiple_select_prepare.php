<?php

/**
 * Run: php tests/compatibility/multiple_select_prepare.php
 * Only Select is doubled; the production MultipleSelect::prepare runs unchanged.
 * The real inheritance chain is exercised by the separate integration suite.
 */
namespace Encore\Admin\Form\Field {
    class Select
    {
    }
}

namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    require __DIR__.'/../../src/Form/Field/MultipleSelect.php';

    $field = new \Encore\Admin\Form\Field\MultipleSelect();
    $emptyStringable = new class {
        public function __toString(): string { return ''; }
    };
    $zeroStringable = new class {
        public function __toString(): string { return '0'; }
    };
    $values = [null, '', false, true, 0, '0', 0.0, 12, -1, 1.5, ' ', '日本',
        "\0", $emptyStringable, $zeroStringable];
    $cases = $values;
    $cases[] = [];
    $cases[] = $values;
    $cases[] = ['absent' => null, 'blank' => '', 'false' => false,
        'zero' => 0, 'string-zero' => '0', 9 => 'duplicate', 15 => 'duplicate'];
    foreach ($values as $first) {
        foreach ($values as $second) {
            $cases[] = [$first, $second];
        }
    }

    $count = 0;
    foreach ($cases as $input) {
        // Only the intentional legacy oracle may emit the known null deprecation.
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_DEPRECATED && str_contains($message, 'strlen(): Passing null')) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $expected = array_filter((array) $input, 'strlen');
        } finally {
            restore_error_handler();
        }
        if ($field->prepare($input) !== $expected) {
            throw new \RuntimeException('Filtering changed for case '.$count);
        }
        ++$count;
    }

    // Invalid elements must still fail rather than being cast, silently dropped,
    // or flattened. Top-level input retains its original (array) conversion.
    $resource = fopen('php://memory', 'r');
    foreach ([[[]], [['nested']], [new \stdClass()], [$resource]] as $input) {
        foreach ([function () use ($input) { return array_filter($input, 'strlen'); },
            function () use ($field, $input) { return $field->prepare($input); }] as $prepare) {
            try {
                $prepare();
                throw new \RuntimeException('Expected TypeError for invalid element');
            } catch (\TypeError $exception) {
                if (!str_contains($exception->getMessage(), 'strlen()')) {
                    throw $exception;
                }
            }
        }
        ++$count;
    }
    fclose($resource);
    restore_error_handler();
    echo 'MultipleSelect preparation: '.$count." parity/error cases passed without production diagnostics.\n";
}
