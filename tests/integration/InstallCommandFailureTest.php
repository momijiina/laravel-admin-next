<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Console\InstallCommand;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstallCommandFailureTest extends TestCase
{
    private $fixturePath;
    private $seedWasProhibited;

    protected function getApplicationBasePath()
    {
        $this->fixturePath ??= sys_get_temp_dir().'/admin-install-command-'.bin2hex(random_bytes(8));

        if (!is_dir($this->fixturePath)) {
            $files = new Filesystem();
            foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'public', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $directory) {
                $files->makeDirectory($this->fixturePath.'/'.$directory, 0700, true);
            }
            // Testbench caches framework defaults across classes. Give the
            // disposable app its own complete config regardless of test order.
            $files->copyDirectory(\Orchestra\Testbench\default_skeleton_path('config'), $this->fixturePath.'/config');
            $files->put($this->fixturePath.'/composer.json', json_encode([
                'autoload' => ['psr-4' => ['App\\' => 'app/']],
            ]));
        }

        return $this->fixturePath;
    }

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function setUp(): void
    {
        $this->seedWasProhibited = (new \ReflectionProperty(SeedCommand::class, 'prohibitedFromRunning'))->getValue();
        SeedCommand::prohibit(false);

        parent::setUp();
        // Use the source path shared with other integration tests: copying this
        // named migration would redeclare CreateAdminTables in a combined run.
        $this->app['migrator']->path(realpath(__DIR__.'/../../database/migrations'));
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            SeedCommand::prohibit($this->seedWasProhibited);

            if ($this->fixturePath) {
                (new Filesystem())->deleteDirectory($this->fixturePath);
            }
        }
    }

    public static function productionSchemas(): iterable
    {
        yield 'fresh database' => [false];
        yield 'migrated but unseeded database' => [true];
    }

    #[DataProvider('productionSchemas')]
    public function test_declined_production_migration_stops_before_queries_seed_or_scaffolding(bool $migrated): void
    {
        if ($migrated) {
            $this->assertSame(0, $this->app[Kernel::class]->call('migrate', ['--force' => true]));
        }

        $queries = [];
        $this->app['db']->listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->app->detectEnvironment(static fn () => 'production');

        [$status, $output] = $this->runInstall();

        $this->assertSame(1, $status, $output);
        $this->assertSame(1, substr_count($output, 'Command cancelled.'), $output);
        $this->assertSame([], $queries);
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
        $this->assertStringNotContainsString('Seeding database.', $output);
    }

    public function test_prohibited_seeding_stops_after_real_migration_without_scaffolding(): void
    {
        SeedCommand::prohibit(true);

        [$status, $output] = $this->runInstall();

        $this->assertSame(1, $status, $output);
        $this->assertTrue($this->app['db']->getSchemaBuilder()->hasTable('admin_users'));
        $this->assertSame(0, Administrator::count());
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
        $this->assertStringContainsString('This command is prohibited from running in this environment.', $output);
    }

    public static function failedChildCommands(): iterable
    {
        yield 'migration status' => ['migrate', 7];
        yield 'seeder status' => ['db:seed', 23];
    }

    #[DataProvider('failedChildCommands')]
    public function test_preserves_the_exact_child_failure_status(string $failedCommand, int $failureStatus): void
    {
        $kernel = $this->app[Kernel::class];
        $this->assertSame(0, $kernel->call('migrate'));

        $seed = new InstallationStatusCommand('db:seed {--class=}', $failedCommand === 'db:seed' ? $failureStatus : 0);
        $kernel->registerCommand($seed);
        if ($failedCommand === 'migrate') {
            $kernel->registerCommand(new InstallationStatusCommand('migrate', $failureStatus));
        }

        [$status, $output] = $this->runInstall();

        $this->assertSame($failureStatus, $status, $output);
        $this->assertSame($failedCommand === 'db:seed' ? 1 : 0, $seed->calls);
        $this->assertSame(0, Administrator::count());
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public function test_successful_install_and_repeat_preserve_the_account_and_existing_files(): void
    {
        [$status, $output] = $this->runInstall();
        $this->assertSame(0, $status, $output);
        $this->assertSame(1, Administrator::count());
        $this->assertSame(1, Role::count());
        $this->assertSame(7, Menu::count());
        $user = Administrator::firstOrFail();
        $this->assertTrue($user->isAdministrator());

        $directory = config('admin.directory');
        foreach (['Controllers/HomeController.php', 'Controllers/AuthController.php', 'Controllers/ExampleController.php', 'bootstrap.php', 'routes.php'] as $file) {
            $this->assertFileExists($directory.'/'.$file);
            token_get_all(file_get_contents($directory.'/'.$file), TOKEN_PARSE);
        }

        file_put_contents($directory.'/Controllers/HomeController.php', "<?php // Consumer-owned controller.\n");
        $user->name = 'Existing administrator';
        $user->save();
        Role::create(['name' => 'Existing editor', 'slug' => 'existing-editor']);
        Menu::create(['title' => 'Existing menu', 'icon' => 'fa-home', 'uri' => 'existing']);
        SeedCommand::prohibit(true);

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('directory already exists', $output);
        $this->assertStringNotContainsString('prohibited', $output);
        $this->assertSame(1, Administrator::count());
        $this->assertSame($user->getAttributes(), $user->fresh()->getAttributes());
        $this->assertSame(2, Role::count());
        $this->assertSame(8, Menu::count());
        $this->assertSame("<?php // Consumer-owned controller.\n", file_get_contents($directory.'/Controllers/HomeController.php'));
    }

    public static function legacyDatabaseInitializers(): iterable
    {
        yield 'implicit null return' => [InstallationNullDatabaseCommand::class];
        yield 'declared void return' => [InstallationVoidDatabaseCommand::class];
    }

    #[DataProvider('legacyDatabaseInitializers')]
    public function test_legacy_database_overrides_without_an_exit_status_still_scaffold(string $command): void
    {
        $this->app[Kernel::class]->registerCommand(new $command());

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertFileExists(config('admin.directory').'/Controllers/HomeController.php');
        $this->assertFileExists(config('admin.directory').'/routes.php');
        $this->assertFalse($this->app['db']->getSchemaBuilder()->hasTable('admin_users'));
    }

    private function runInstall(): array
    {
        $kernel = $this->app[Kernel::class];
        $status = $kernel->call('admin:install', ['--no-interaction' => true]);

        return [$status, $kernel->output()];
    }
}

class InstallationStatusCommand extends Command
{
    public $calls = 0;
    private $exitStatus;

    public function __construct(string $signature, int $exitStatus)
    {
        $this->signature = $signature;
        $this->exitStatus = $exitStatus;

        parent::__construct();
    }

    public function handle()
    {
        ++$this->calls;

        return $this->exitStatus;
    }
}

class InstallationNullDatabaseCommand extends InstallCommand
{
    public function initDatabase()
    {
    }
}

class InstallationVoidDatabaseCommand extends InstallCommand
{
    public function initDatabase(): void
    {
    }
}
