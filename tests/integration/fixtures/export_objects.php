<?php

// The real exporter sends a streamed response and exits; isolate that lifecycle.
$loader = require $argv[1];
$loader->setPsr4('Encore\\Admin\\', $argv[2]);
require __DIR__.'/object_display.php';
try {
    (new LaravelAdminNext\Integration\ObjectDisplayFixture('exportFixture'))->exportFixture($argv[3]);
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
