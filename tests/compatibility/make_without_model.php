<?php

/**
 * Standalone command regression, without Composer or a database.
 * Runs the real MakeCommand and ResourceGenerator with a small GeneratorCommand
 * and Doctrine-compatible schema double. Real Artisan/file IO is separate.
 *
 * Run: php tests/compatibility/make_without_model.php
 */

namespace Illuminate\Console {
    class GeneratorCommand
    {
        public $options = [];
        public $messages = [];
        public $generated;
        public $parentResult;
        protected $type;

        public function option($name) { return isset($this->options[$name]) ? $this->options[$name] : null; }
        public function argument($name) { return 'BlankController'; }
        public function error($message) { $this->messages[] = 'error: '.$message; }
        public function line($message) { $this->messages[] = $message; }
        public function comment($message) { $this->messages[] = $message; }
        public function info($message) { $this->messages[] = $message; }
        public function alert($message) { $this->messages[] = $message; }
        protected function qualifyClass($name) { return $this->getDefaultNamespace('App').'\\'.$name; }
        protected function replaceClass($stub, $name) { return str_replace('DummyClass', $name, $stub); }
        public function handle()
        {
            if ($this->parentResult === false) {
                return false;
            }
            $stub = str_replace('DummyNamespace', $this->getDefaultNamespace('App'), file_get_contents($this->getStub()));
            $this->generated = $this->replaceClass($stub, $this->getNameInput());
        }
    }
}

namespace Illuminate\Support {
    class Str
    {
        public static function kebab($value) { return strtolower($value); }
        public static function plural($value) { return $value.'s'; }
    }
}

namespace Illuminate\Database\Eloquent {
    class Model
    {
        public static $connections = 0;
        public function getConnection() { ++self::$connections; return new \MakeSchema(); }
        public function getTable() { return 'widgets'; }
        public function getKeyName() { return 'id'; }
        public function getCreatedAtColumn() { return 'created_at'; }
        public function getUpdatedAtColumn() { return 'updated_at'; }
    }
}

namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    function config($key) { return 'App\\Admin\\Controllers'; }
    function __($text) { return $text; }
    function class_basename($name) { return basename(str_replace('\\', '/', $name)); }

    class Widget extends \Illuminate\Database\Eloquent\Model {}
    class MakeSchema
    {
        public function isDoctrineAvailable() { return true; }
        public function getTablePrefix() { return ''; }
        public function getDoctrineSchemaManager($table) { return $this; }
        public function getDatabasePlatform() { return $this; }
        public function registerDoctrineTypeMapping($from, $to) {}
        public function listTableColumns($table, $database) { return [new MakeColumn()]; }
    }
    class MakeColumn
    {
        public function getName() { return 'name'; }
        public function getType() { return new MakeType(); }
        public function getDefault() { return ''; }
    }
    class MakeType { public function getName() { return 'string'; } }

    require __DIR__.'/../../src/Console/ResourceGenerator.php';
    require __DIR__.'/../../src/Console/MakeCommand.php';

    $failures = [];
    function check($condition, $message)
    {
        if (!$condition) { $GLOBALS['failures'][] = $message; }
    }
    function runCommand($options = [], $parentResult = null)
    {
        $command = new \Encore\Admin\Console\MakeCommand();
        $command->options = $options;
        $command->parentResult = $parentResult;
        try {
            $result = $command->handle();
        } catch (\Throwable $error) {
            $GLOBALS['failures'][] = json_encode($options).': '.get_class($error).': '.$error->getMessage();
            $result = null;
        }
        return [$command, $result];
    }

    foreach ([[], ['model' => ''], ['namespace' => 'Custom\\Controllers', 'title' => 'Unused']] as $options) {
        list($command) = runCommand($options);
        $namespace = isset($options['namespace']) ? $options['namespace'] : 'App\\Admin\\Controllers';
        $expected = str_replace(['DummyNamespace', 'DummyClass'], [$namespace, 'BlankController'], file_get_contents(__DIR__.'/../../src/Console/stubs/blank.stub'));
        check($command->generated === $expected, 'No-model generation must render the exact blank stub and namespace.');
        check(!count($command->messages), 'No-model generation must not suggest an empty resource route.');
    }
    check(\Illuminate\Database\Eloquent\Model::$connections === 0, 'Blank generation must not inspect database schema.');

    $custom = tempnam(sys_get_temp_dir(), 'admin-make-');
    file_put_contents($custom, '<?php namespace DummyNamespace; class DummyClass {}');
    list($command) = runCommand(['stub' => $custom]);
    check($command->generated === '<?php namespace App\\Admin\\Controllers; class BlankController {}', 'Custom model-less stub must render normally.');
    unlink($custom);

    list($command, $result) = runCommand(['output' => true]);
    check($result === 1 && $command->generated === null, 'Model-less --output must return a nonzero status without generating a file.');
    check($command->messages === ['error: The --output option requires a model.'], 'Model-less --output must explain the required model.');

    list($command, $result) = runCommand(['model' => 'MissingModel']);
    check($result === false && $command->messages === ['error: Model does not exists !'], 'Invalid-model validation must be preserved.');
    list($command, $result) = runCommand(['stub' => __DIR__.'/missing.stub']);
    check($result === false && $command->messages === ['error: The stub file dose not exist.'], 'Missing-stub validation must be preserved.');

    list($command) = runCommand(['model' => Widget::class, 'title' => 'Inventory']);
    check(strpos($command->generated, "protected \$title = 'Inventory';") !== false, 'Model title substitution must be preserved.');
    check(strpos($command->generated, 'new Widget()') !== false && strpos($command->generated, 'Dummy') === false, 'Model controller substitutions must be preserved.');
    check(in_array("    \$router->resource('widgets', BlankController::class);", $command->messages, true), 'Model-backed resource route must be preserved.');

    list($command) = runCommand(['model' => Widget::class, 'output' => true]);
    check($command->generated === null && count($command->messages) === 4, 'Model-backed output must print three snippets without generating a file.');
    check(strpos($command->messages[1], "\$grid->column('name'") !== false, 'Model-backed output must retain its generated grid.');
    list($command) = runCommand(['model' => Widget::class], false);
    check(!count($command->messages), 'Failed parent generation must not suggest a resource route.');

    restore_error_handler();
    if ($failures) {
        fwrite(STDERR, implode("\n", $failures)."\n");
        exit(1);
    }
    echo "PASS: model-less and model-backed MakeCommand modes\n";
}
