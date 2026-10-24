<?php

// Create only a new, disposable official application; never modify an existing app.
$skeletons = [
    '12' => ['v12.12.2', '945f4e5a9fd3695dc0ee512f497c650fb82cfbb8'],
    '13' => ['v13.11.0', '06d016a364a37430eec9cbc52209adce3d7667ce'],
];

$major = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if (!isset($skeletons[$major]) || $destination === '' || $destination[0] !== '/' || file_exists($destination)) {
    fwrite(STDERR, "Usage: php provision.php <12|13> <new-absolute-app-directory>\n");
    exit(1);
}

[$tag, $reference] = $skeletons[$major];
$repository = realpath(__DIR__.'/../..');
$package = [
    'type' => 'package',
    'package' => [
        'name' => 'laravel/laravel',
        'version' => $tag,
        'dist' => [
            'type' => 'zip',
            'url' => 'https://api.github.com/repos/laravel/laravel/zipball/'.$reference,
            'reference' => $reference,
        ],
    ],
];

function run(array $command, string $directory): void
{
    echo '+ '.implode(' ', array_map('escapeshellarg', $command)).PHP_EOL;
    $process = proc_open($command, [STDIN, ['pipe', 'w'], ['redirect', 1]], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: '.$command[0]);
    }
    while (!feof($pipes[1])) {
        fwrite(STDOUT, fread($pipes[1], 8192));
    }
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Command failed: '.$command[0]);
    }
}

try {
    run([
        'composer', 'create-project', '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist',
        '--repository='.json_encode($package, JSON_THROW_ON_ERROR),
        'laravel/laravel', $destination, $tag,
    ], __DIR__);

    $app = realpath($destination);
    $manifest = json_decode(file_get_contents($app.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['require']['encore/laravel-admin'] = 'dev-under-test';
    $manifest['repositories'] = [[
        'type' => 'path',
        'url' => $repository,
        'options' => ['symlink' => true, 'versions' => ['encore/laravel-admin' => 'dev-under-test']],
    ]];
    file_put_contents($app.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

    // Keep the official database cache/session drivers and session serialization.
    $environment = preg_replace('/^APP_DEBUG=.*$/m', 'APP_DEBUG=false', file_get_contents($app.'/.env.example'));
    $environment = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://127.0.0.1', $environment);
    file_put_contents($app.'/.env', $environment);
    touch($app.'/database/database.sqlite');
    file_put_contents($app.'/.fresh-app-smoke.json', json_encode([
        'framework_major' => $major,
        'skeleton_tag' => $tag,
        'skeleton_reference' => $reference,
        'package_path' => $repository,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

    run(['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress'], $app);
    run(['composer', 'check-platform-reqs', '--no-dev'], $app);
    run(['composer', 'audit', '--locked', '--no-dev'], $app);
    foreach ([
        ['key:generate'],
        ['vendor:publish', '--provider=Encore\\Admin\\AdminServiceProvider'],
        ['admin:install'],
        ['config:cache'],
        ['route:cache'],
        ['view:cache'],
    ] as $arguments) {
        run(array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction', '--no-ansi']), $app);
    }
    run([PHP_BINARY, __DIR__.'/verify.php', $app], $app);
    echo 'Fresh application ready: '.$app.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
