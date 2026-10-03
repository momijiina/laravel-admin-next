<?php

$loader = require (getenv('BROWSERKIT_AUTOLOAD') ?: __DIR__.'/vendor/autoload.php');
$loader->addPsr4('Tests\\Models\\', __DIR__.'/../models');
$loader->addPsr4('Tests\\Controllers\\', __DIR__.'/../controllers');

// admin:install generates these consumer controllers inside the disposable app.
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'App\\Admin\\')) {
        $file = app_path(str_replace('\\', '/', substr($class, 4)).'.php');
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__.'/fixtures/Controller.php';
require __DIR__.'/TestCase.php';
