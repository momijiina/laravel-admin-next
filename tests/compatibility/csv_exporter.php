<?php

/**
 * Standalone CSV regression: php tests/compatibility/csv_exporter.php
 * No Composer install is needed. PHP 8.4 additionally checks the deprecated
 * default escape argument. The runner requires proc_open (not disabled).
 *
 * The child runs the real CsvExporter::export() and its streaming closure,
 * including the terminating exit. Only the framework/column/data seams are
 * stubbed; this is not a Laravel integration or column-transformation test.
 */

namespace Encore\Admin\Grid\Exporters {
    abstract class AbstractExporter
    {
        protected $grid;
    }
}

namespace Encore\Admin\Grid {
    class Column
    {
        public static function setOriginalGridModels($collection)
        {
        }
    }
}

namespace {
    class CsvFixtureCollection
    {
        private $records;

        public function __construct(array $records = [])
        {
            $this->records = $records;
        }

        public function toArray()
        {
            return $this->records;
        }

        public function map(callable $callback)
        {
            // No display transformations are needed for already-shaped rows.
        }
    }

    class CsvFixtureGrid
    {
        public function getColumns()
        {
            return new CsvFixtureCollection();
        }
    }

    class CsvFixtureResponse
    {
        private $callback;

        public function stream($callback, $status, array $headers)
        {
            if ($status !== 200 || $headers !== [
                'Content-Encoding'    => 'UTF-8',
                'Content-Type'        => 'text/csv;charset=UTF-8',
                'Content-Disposition' => 'attachment;filename="fixture.csv"',
            ]) {
                throw new \RuntimeException('Unexpected CSV response headers');
            }

            $this->callback = $callback;

            return $this;
        }

        public function send()
        {
            call_user_func($this->callback);
        }
    }

    function response()
    {
        return new CsvFixtureResponse();
    }

    if (isset($argv[1]) && $argv[1] === '--export') {
        error_reporting(E_ALL);
        set_error_handler(function ($severity, $message, $file, $line) {
            // Keep CSV bytes intact while making every diagnostic fail the parent.
            fwrite(STDERR, $message.' at '.basename($file).':'.$line."\n");

            return true;
        });

        require __DIR__.'/../../src/Grid/Exporters/CsvExporter.php';

        class CsvFixtureExporter extends \Encore\Admin\Grid\Exporters\CsvExporter
        {
            private $empty;

            public function __construct($empty)
            {
                $this->grid = new CsvFixtureGrid();
                $this->empty = $empty;
            }

            public function chunk(callable $callback, $count = 100)
            {
                if (!$this->empty) {
                    // Separate chunks also check that the header is emitted once.
                    $callback(new CsvFixtureCollection([
                        ['value', 'comma,value', 'quote"value', 'slash\\"value', "line\nvalue", '日本語☕', '', null],
                    ]));
                    $callback(new CsvFixtureCollection([
                        [0, 'comma,again', '""', 'C:\\tmp\\file', "\r\n", 'é', '', null],
                    ]));
                }
            }

            protected function getVisiableTitles()
            {
                return ['plain', 'comma,title', 'quote"title', 'slash\\"title', "line\ntitle", '日本語', 'empty', 'null'];
            }

            public function getVisiableFields(array $value, array $original): array
            {
                return $value;
            }
        }

        $exporter = new CsvFixtureExporter($argv[2] === 'empty');
        $exporter->filename('fixture')->export();
        fwrite(STDERR, "CsvExporter::export() unexpectedly returned instead of exiting\n");
        exit(1);
    }

    if (!function_exists('proc_open')) {
        fwrite(STDERR, "FAIL: proc_open is required for the isolated exporter process\n");
        exit(1);
    }

    // Literal expected bytes deliberately do not call fputcsv: changing the
    // escape to an empty string would alter the slash+quote fields and fail.
    $expected = "\xEF\xBB\xBF".<<<'CSV'
plain,"comma,title","quote""title","slash\"title","line
title",日本語,empty,null
value,"comma,value","quote""value","slash\"value","line
value",日本語☕,,
0,"comma,again","""""","C:\tmp\file",
CSV
    ."\"\r\n\",é,,\n";

    $failed = false;
    foreach (['populated' => $expected, 'empty' => "\xEF\xBB\xBF"] as $scenario => $expectedCsv) {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --export '.escapeshellarg($scenario);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, "FAIL: could not start exporter subprocess\n");
            exit(1);
        }

        fclose($pipes[0]);
        $actualCsv = stream_get_contents($pipes[1]);
        $diagnostics = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($actualCsv !== $expectedCsv) {
            fwrite(STDERR, 'FAIL: '.$scenario." CSV bytes changed\nExpected hex: ".bin2hex($expectedCsv)."\nActual hex:   ".bin2hex($actualCsv)."\n");
            $failed = true;
        } else {
            echo 'PASS: '.$scenario.' CSV bytes ('.strlen($actualCsv)." bytes, including UTF-8 BOM)\n";
        }

        if ($exitCode !== 0 || $diagnostics !== '') {
            fwrite(STDERR, 'FAIL: '.$scenario.' exporter diagnostics (exit '.$exitCode.")\n".$diagnostics);
            $failed = true;
        } else {
            echo 'PASS: '.$scenario." exporter has no diagnostics\n";
        }
    }

    exit($failed ? 1 : 0);
}
