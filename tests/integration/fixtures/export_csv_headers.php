<?php

// Exercise the HTTP kernel and actual CSV stream/exit in an isolated process.
$loader = require $argv[1];
$loader->setPsr4('Encore\\Admin\\', $argv[2]);
require __DIR__.'/csv_headers.php';
try {
    (new LaravelAdminNext\Integration\CsvHeadersFixture('exportFixture'))
        ->exportFixture(json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR), $argv[4]);
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
