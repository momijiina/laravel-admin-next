<?php

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Facades\Admin;
use Illuminate\Filesystem\Filesystem;
use Laravel\BrowserKitTesting\TestCase as BrowserKitTestCase;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

class HistoricalBrowserKitApplication extends TestbenchApplication
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', array_replace_recursive(
            require __DIR__.'/../../config/admin.php',
            require __DIR__.'/../config/admin.php'
        ));
        $app['config']->set('filesystems', require __DIR__.'/../config/filesystems.php');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        // Original grid assertions exercise the supported legacy action renderer.
        $app['config']->set('admin.grid_action_class', \Encore\Admin\Grid\Displayers\Actions::class);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class, \Illuminate\Database\Eloquent\LegacyFactoryServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Admin' => Admin::class];
    }
}

abstract class TestCase extends BrowserKitTestCase
{
    protected $baseUrl = 'http://localhost:8000';
    private $fixturePath;
    private static $suiteFixturePath;

    public function createApplication()
    {
        // Keep one pathname per process: Laravel's named migration is require_once'd.
        // Files and the in-memory database are still recreated for every test.
        $this->fixturePath = self::$suiteFixturePath ??= sys_get_temp_dir().'/admin-browserkit-'.bin2hex(random_bytes(8));
        $files = new Filesystem();
        foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'public', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $directory) {
            $files->makeDirectory($this->fixturePath.'/'.$directory, 0755, true);
        }
        $files->copy(__DIR__.'/../../composer.json', $this->fixturePath.'/composer.json');
        return HistoricalBrowserKitApplication::create($this->fixturePath, static function ($app) {
            $app->detectEnvironment(static fn () => 'testing');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('vendor:publish', ['--provider' => AdminServiceProvider::class]);
        $this->artisan('admin:install');
        require_once __DIR__.'/../migrations/2016_11_22_093148_create_test_tables.php';
        (new CreateTestTables())->up();
        require admin_path('routes.php');
        require __DIR__.'/../routes.php';
        require __DIR__.'/../seeds/factory.php';
        // Replaces the laravel/laravel skeleton welcome page used by LaravelTest.
        $this->app['router']->get('/', static fn () => 'Laravel');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->fixturePath) {
                (new Filesystem())->deleteDirectory($this->fixturePath);
            }
        }
    }
}
