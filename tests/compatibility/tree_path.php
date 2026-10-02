<?php

/**
 * Standalone regression for Tree's public request path.
 *
 * Run in a fresh PHP process, without Composer or a database:
 *     php tests/compatibility/tree_path.php
 */

namespace Illuminate\Contracts\Support {
    interface Renderable
    {
        public function render();
    }
}

namespace {
    error_reporting(E_ALL);

    $treeFile = realpath(__DIR__.'/../../src/Tree.php');

    // All class-loading diagnostics are regressions, including nullability.
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    require $treeFile;
    restore_error_handler();

    class TreePathRequest
    {
        public $path;

        public function getPathInfo()
        {
            return $this->path;
        }
    }

    function request()
    {
        return $GLOBALS['treePathRequest'];
    }

    class TreePathProbe extends \Encore\Admin\Tree
    {
        public function setupTools()
        {
            // Tool rendering is outside this constructor/path regression.
        }
    }

    $runtimeErrors = [];
    $failures = [];

    set_error_handler(function ($severity, $message) use (&$runtimeErrors) {
        $runtimeErrors[] = $message;

        return true;
    });

    $GLOBALS['treePathRequest'] = new TreePathRequest();
    $GLOBALS['treePathRequest']->path = '/admin/auth/menu';
    $callbackPath = null;

    $first = new TreePathProbe(null, function ($tree) use (&$callbackPath) {
        $callbackPath = $tree->path;
    });

    if ($first->path !== '/admin/auth/menu' || $callbackPath !== '/admin/auth/menu') {
        $failures[] = 'The real constructor must expose the request path publicly, including to its callback.';
    }

    $GLOBALS['treePathRequest']->path = '/admin/categories';
    $second = new TreePathProbe();

    if ($second->path !== '/admin/categories' || $first->path !== '/admin/auth/menu') {
        $failures[] = 'New instances must capture independent request paths.';
    }

    $first->path = '/admin/custom-menu';

    if ($first->path !== '/admin/custom-menu' || $second->path !== '/admin/categories') {
        $failures[] = 'Public path reassignment must remain supported and instance-local.';
    }

    if (!property_exists(\Encore\Admin\Tree::class, 'path')) {
        $failures[] = 'Tree::$path must be explicitly declared.';
    } else {
        $property = new \ReflectionProperty(\Encore\Admin\Tree::class, 'path');

        if (!$property->isPublic() || $property->isStatic()) {
            $failures[] = 'Tree::$path must remain a public instance property.';
        }
    }

    restore_error_handler();

    foreach ($runtimeErrors as $message) {
        $failures[] = 'Runtime diagnostic: '.$message;
    }

    foreach ($failures as $failure) {
        echo 'FAIL: '.$failure."\n";
    }

    if ($failures) {
        exit(1);
    }

    echo "PASS: Tree captures independent request paths, preserves public reads/writes, and emits no runtime diagnostics.\n";
}
