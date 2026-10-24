<?php

$appPath = $argv[1] ?? '';
$metadata = json_decode(file_get_contents($appPath.'/.fresh-app-smoke.json'), true, 512, JSON_THROW_ON_ERROR);
require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$checks = [
    'official framework major' => str_starts_with($app->version(), $metadata['framework_major'].'.'),
    'automatic provider discovery' => isset($app->getLoadedProviders()[Encore\Admin\AdminServiceProvider::class]),
    'configuration cache' => $app->configurationIsCached(),
    'route cache' => $app->routesAreCached(),
    'SQLite database' => config('database.default') === 'sqlite',
    'database sessions' => config('session.driver') === 'database',
    'database cache' => config('cache.default') === 'database',
    'official session serialization' => config('session.serialization', 'php') === ($metadata['framework_major'] === '13' ? 'json' : 'php'),
    'seeded administrator' => Encore\Admin\Auth\Database\Administrator::where('username', 'admin')->count() === 1,
    'generated admin route' => app('router')->has('admin.home'),
    'production dependencies only' => !class_exists(Orchestra\Testbench\TestCase::class),
];
foreach ($checks as $description => $passed) {
    if (!$passed) {
        throw new RuntimeException('Fresh application check failed: '.$description);
    }
    echo 'PASS '.$description.PHP_EOL;
}
echo 'Laravel '.$app->version().' / PHP '.PHP_VERSION.PHP_EOL;
