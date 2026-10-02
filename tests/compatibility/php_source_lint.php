<?php

// Run each production file in a separate lint process: compile-time deprecations
// cannot be caught with set_error_handler() around require. No vendor is needed.
$root = dirname(__DIR__, 2);
$files = [];
foreach (['src', 'config', 'database', 'resources/lang'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root.'/'.$directory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && substr($file->getFilename(), -10) !== '.blade.php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
$failures = 0;
foreach ($files as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY).' -d error_reporting=-1 -d display_errors=stderr -d log_errors=0 -l '.escapeshellarg($file).' 2>&1', $output, $status);
    $expected = 'No syntax errors detected in '.$file;
    if ($status !== 0 || implode("\n", $output) !== $expected) {
        fwrite(STDERR, implode("\n", $output)."\n");
        ++$failures;
    }
}
if ($failures) {
    fwrite(STDERR, "$failures production files emitted lint errors or diagnostics.\n");
    exit(1);
}
echo count($files)." production PHP files lint cleanly without diagnostics on PHP ".PHP_VERSION.".\n";
