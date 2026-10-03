<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form\Field\MultipleSelect;
use Orchestra\Testbench\TestCase;

class MultipleSelectTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    public function test_prepare_removes_null_and_empty_values_without_diagnostics(): void
    {
        $field = new MultipleSelect('roles', 'Roles');

        // Override Laravel's logging handler locally: a regression must fail,
        // even when the framework normally only logs the PHP deprecation.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->assertSame([], $field->prepare(null));
            $this->assertSame([], $field->prepare([null, '', false]));
            $this->assertSame([0], $field->prepare(0));
            $this->assertSame(['0'], $field->prepare('0'));
            $this->assertSame([
                'zero' => 0, 'string-zero' => '0', 8 => 'admin', 13 => 'admin',
                'space' => ' ', 'true' => true,
            ], $field->prepare([
                'null' => null, 'blank' => '', 'false' => false,
                'zero' => 0, 'string-zero' => '0', 8 => 'admin', 13 => 'admin',
                'space' => ' ', 'true' => true,
            ]));
        } finally {
            restore_error_handler();
        }
    }

    public function test_prepare_still_rejects_nested_arrays(): void
    {
        $field = new MultipleSelect('roles', 'Roles');
        $this->expectException(\TypeError::class);
        $field->prepare([['nested']]);
    }
}
