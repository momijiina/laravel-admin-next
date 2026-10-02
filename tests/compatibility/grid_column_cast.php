<?php

/**
 * Standalone regression for Column's legacy public cast property.
 *
 * Run without Composer or a database:
 *     php tests/compatibility/grid_column_cast.php
 *
 * Only Arr::get and the sorter model are doubled. The real Column constructor,
 * traits, cast(), sortable(), and Sorter constructor run; rendering is not tested.
 */

namespace Illuminate\Contracts\Support {
    interface Renderable
    {
        public function render();
    }
}

namespace Illuminate\Support {
    class Arr
    {
        public static function get($array, $key, $default = null)
        {
            // This fixture uses only simple column names, without dot notation.
            return array_key_exists($key, $array) ? $array[$key] : $default;
        }
    }
}

namespace {
    error_reporting(E_ALL);
    $diagnostics = [];
    $failures = [];

    set_error_handler(function ($severity, $message) use (&$diagnostics) {
        $diagnostics[] = $message;

        return true;
    });

    require __DIR__.'/../../src/Grid/Column/HasHeader.php';
    require __DIR__.'/../../src/Grid/Column/InlineEditing.php';
    require __DIR__.'/../../src/Grid/Column/ExtendDisplay.php';
    require __DIR__.'/../../src/Grid/Column/Sorter.php';
    require __DIR__.'/../../src/Grid/Column.php';

    class ColumnCastModel
    {
        public function getSortName()
        {
            return '_sort';
        }
    }

    class ColumnCastGrid
    {
        public function model()
        {
            return new ColumnCastModel();
        }
    }

    class ColumnCastProbe extends \Encore\Admin\Grid\Column
    {
        public function prepareSorter()
        {
            $this->grid = new ColumnCastGrid();
        }

        public function sorterCasts()
        {
            $property = new \ReflectionProperty(\Encore\Admin\Grid\Column\Sorter::class, 'cast');
            $property->setAccessible(true);

            return array_map(function ($sorter) use ($property) {
                return $property->getValue($sorter);
            }, $this->headers);
        }
    }

    $first = new ColumnCastProbe('amount', 'Amount');
    $second = new ColumnCastProbe('count', 'Count');

    if ($first->cast('SIGNED') !== $first || $first->cast !== 'SIGNED') {
        $failures[] = 'cast() must store its argument publicly and return the same column.';
    }

    $second->cast('UNSIGNED');
    $first->cast('DECIMAL');

    if ($first->cast !== 'DECIMAL' || $second->cast !== 'UNSIGNED') {
        $failures[] = 'Repeated cast() calls must replace only the current instance value.';
    }

    $first->cast = 'CHAR';

    if ($first->cast !== 'CHAR' || $second->cast !== 'UNSIGNED') {
        $failures[] = 'Public writes must remain supported and instance-local.';
    }

    if ($first->cast(null) !== $first || $first->cast !== null) {
        $failures[] = 'cast(null) must reset the value and remain fluent.';
    }

    $value = new \stdClass();
    $first->cast($value);

    if ($first->cast !== $value) {
        $failures[] = 'The legacy property must preserve untyped values without coercion.';
    }

    // The old API stores a value; it does not supply a default to sortable().
    $first->cast('SIGNED');
    $first->prepareSorter();

    if ($first->sortable() !== $first || $first->sortable('DECIMAL') !== $first) {
        $failures[] = 'sortable() must remain fluent.';
    }

    if ($first->sorterCasts() !== [null, 'DECIMAL'] || $first->cast !== 'SIGNED') {
        $failures[] = 'sortable() must keep using its own argument independently of legacy cast().';
    }

    if (!property_exists(\Encore\Admin\Grid\Column::class, 'cast')) {
        $failures[] = 'Column::$cast must be explicitly declared.';
    } else {
        $property = new \ReflectionProperty(\Encore\Admin\Grid\Column::class, 'cast');

        if (!$property->isPublic() || $property->isStatic()) {
            $failures[] = 'Column::$cast must remain a public instance property.';
        }
    }

    restore_error_handler();

    foreach ($diagnostics as $message) {
        $failures[] = 'Diagnostic: '.$message;
    }

    foreach ($failures as $failure) {
        echo 'FAIL: '.$failure."\n";
    }

    if ($failures) {
        exit(1);
    }

    echo "PASS: Column cast preserves fluent/public access, resets, instance isolation, and sorter arguments without diagnostics.\n";
}
