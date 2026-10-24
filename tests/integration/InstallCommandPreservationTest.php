<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstallCommandPreservationTest extends TestCase
{
    private $fixturePath;
    private $seedWasProhibited;

    protected function getApplicationBasePath()
    {
        $this->fixturePath ??= sys_get_temp_dir().'/admin-install-preservation-'.bin2hex(random_bytes(8));

        if (!is_dir($this->fixturePath)) {
            $files = new Filesystem();
            foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'public', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $directory) {
                $files->makeDirectory($this->fixturePath.'/'.$directory, 0700, true);
            }
            // Testbench can cache defaults from a different application fixture.
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
        foreach (['testing', 'admin_test', 'model_test'] as $connection) {
            $app['config']->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function setUp(): void
    {
        $this->seedWasProhibited = (new \ReflectionProperty(SeedCommand::class, 'prohibitedFromRunning'))->getValue();
        SeedCommand::prohibit(false);

        parent::setUp();
        // Share the named migration with the rest of the integration suite.
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

    public static function survivingTables(): iterable
    {
        foreach (['roles', 'permissions', 'menu', 'role_users', 'role_permissions', 'user_permissions', 'role_menu', 'operation_log'] as $table) {
            yield $table.' only' => [$table];
        }
    }

    #[DataProvider('survivingTables')]
    public function test_each_surviving_table_prevents_seeding_with_no_users(string $table): void
    {
        $this->migrate();
        $this->insertSurvivor($table);
        $before = $this->snapshot();

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertRefused($status, $output);
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public function test_partial_installation_preserves_all_data_and_existing_application_files(): void
    {
        $this->migrate();
        foreach (self::survivingTables() as [$table]) {
            $this->insertSurvivor($table);
        }
        $before = $this->snapshot();
        $directory = config('admin.directory');
        (new Filesystem())->makeDirectory($directory.'/Controllers', 0700, true);
        $controller = "<?php // Existing consumer controller.\n";
        file_put_contents($directory.'/Controllers/HomeController.php', $controller);

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertRefused($status, $output);
        $this->assertSame($controller, file_get_contents($directory.'/Controllers/HomeController.php'));
        $this->assertFileDoesNotExist($directory.'/routes.php');
        $this->assertStringNotContainsString('directory already exists', $output);
    }

    public function test_custom_table_names_and_admin_connection_are_checked(): void
    {
        $this->configureSeparateAdminDatabase();
        $this->migrate();
        $this->insertSurvivor('operation_log');
        $before = $this->snapshot();
        $this->assertFalse($this->app['db']->connection('testing')->getSchemaBuilder()->hasTable(config('admin.database.users_table')));

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertRefused($status, $output);
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public function test_fresh_install_and_repeat_work_on_renamed_tables_and_separate_admin_connection(): void
    {
        $this->configureSeparateAdminDatabase();

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertTrue(Administrator::firstOrFail()->isAdministrator());
        $this->assertSame(1, Role::count());
        $this->assertSame(5, Permission::count());
        $this->assertSame(7, Menu::count());
        $this->assertFileExists(config('admin.directory').'/routes.php');
        $this->assertFalse($this->app['db']->connection('testing')->getSchemaBuilder()->hasTable(config('admin.database.users_table')));
        $before = $this->snapshot();
        SeedCommand::prohibit(true);

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('prohibited', $output);
    }

    public static function configuredModels(): iterable
    {
        yield 'roles' => ['roles', InstallPreservationExternalRole::class];
        yield 'permissions' => ['permissions', InstallPreservationExternalPermission::class];
        yield 'menu' => ['menu', InstallPreservationExternalMenu::class];
        yield 'roles hidden by an empty read replica' => ['roles', InstallPreservationExternalRole::class, true];
    }

    #[DataProvider('configuredModels')]
    public function test_configured_models_use_their_own_tables_and_connections_without_scopes(string $name, string $class, bool $emptyReplica = false): void
    {
        $this->migrate();
        config(['admin.database.'.$name.'_model' => $class]);
        $model = new $class();
        $model->getConnection()->getSchemaBuilder()->create($model->getTable(), function ($table) {
            $table->increments('id');
            $table->string('marker');
        });
        $model->getConnection()->table($model->getTable())->insert(['id' => 41, 'marker' => 'Existing model data']);
        $this->assertSame(0, $class::count(), 'The configured model hides this row through a global scope.');
        if ($emptyReplica) {
            $this->useEmptyReadReplica($model->getConnection());
        }
        $before = $this->snapshot();

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertSame('Existing model data', $model->getConnection()->table($model->getTable())->useWritePdo()->value('marker'));
        $this->assertRefused($status, $output);
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public function test_scoped_existing_users_skip_seeding(): void
    {
        $this->migrate();
        config(['admin.database.users_model' => InstallPreservationHiddenUser::class]);
        $this->insertSurvivor('users');
        $this->assertSame(0, InstallPreservationHiddenUser::count());
        $before = $this->snapshot();
        SeedCommand::prohibit(true);

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists(config('admin.directory').'/routes.php');
        $this->assertStringNotContainsString('prohibited', $output);
    }

    public function test_existing_custom_users_skip_seeding_on_their_own_table_and_connection(): void
    {
        $this->migrate();
        config(['admin.database.users_model' => InstallPreservationExternalUser::class]);
        $model = new InstallPreservationExternalUser();
        $model->getConnection()->getSchemaBuilder()->create($model->getTable(), function ($table) {
            $table->increments('id');
        });
        $model->getConnection()->table($model->getTable())->insert(['id' => 41]);
        $before = $this->snapshot();
        SeedCommand::prohibit(true);

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([41], $model->getConnection()->table($model->getTable())->pluck('id')->all());
        $this->assertFileExists(config('admin.directory').'/routes.php');
        $this->assertStringNotContainsString('prohibited', $output);
    }

    public function test_empty_custom_users_do_not_allow_the_builtin_user_table_to_be_reset(): void
    {
        $this->migrate();
        $this->insertSurvivor('users');
        config(['admin.database.users_model' => InstallPreservationExternalUser::class]);
        $model = new InstallPreservationExternalUser();
        $model->getConnection()->getSchemaBuilder()->create($model->getTable(), function ($table) {
            $table->increments('id');
        });
        $before = $this->snapshot();

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertRefused($status, $output);
        $this->assertSame(0, $model->newQueryWithoutScopes()->count());
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public static function relatedModelPivots(): iterable
    {
        yield 'role assignments' => ['roles', InstallPreservationExternalRole::class, 'role_users'];
        yield 'menu roles' => ['roles', InstallPreservationExternalRole::class, 'role_menu'];
        yield 'role permissions' => ['permissions', InstallPreservationExternalPermission::class, 'role_permissions'];
        yield 'user permissions' => ['permissions', InstallPreservationExternalPermission::class, 'user_permissions'];
        yield 'role assignments hidden by an empty read replica' => ['roles', InstallPreservationExternalRole::class, 'role_users', true];
    }

    #[DataProvider('relatedModelPivots')]
    public function test_orphaned_pivots_on_configured_related_model_connections_prevent_seeding(string $name, string $class, string $survivingPivot, bool $emptyReplica = false): void
    {
        $this->migrate();
        config(['admin.database.'.$name.'_model' => $class]);
        $model = new $class();
        $connection = $model->getConnection();
        $connection->getSchemaBuilder()->create($model->getTable(), function ($table) {
            $table->increments('id');
        });
        $pivots = $name === 'roles'
            ? ['role_users' => ['role_id', 'user_id'], 'role_menu' => ['role_id', 'menu_id']]
            : ['role_permissions' => ['role_id', 'permission_id'], 'user_permissions' => ['user_id', 'permission_id']];
        foreach ($pivots as $pivot => $columns) {
            $connection->getSchemaBuilder()->create(config('admin.database.'.$pivot.'_table'), function ($table) use ($columns) {
                foreach ($columns as $column) {
                    $table->integer($column);
                }
            });
        }
        $row = array_fill_keys($pivots[$survivingPivot], 1);
        $connection->table(config('admin.database.'.$survivingPivot.'_table'))->insert($row);
        if ($emptyReplica) {
            $this->useEmptyReadReplica($connection);
        }
        $before = $this->snapshot();

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertRefused($status, $output);
        foreach ($pivots as $pivot => $columns) {
            $this->assertSame($pivot === $survivingPivot ? [$row] : [], $connection->table(config('admin.database.'.$pivot.'_table'))->useWritePdo()->get()->map(static fn ($record) => (array) $record)->all());
        }
        $this->assertSame(0, $model->newQueryWithoutScopes()->count());
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public static function writerTables(): iterable
    {
        yield 'existing account' => ['users', 0];
        yield 'partial role data' => ['roles', 1];
    }

    #[DataProvider('writerTables')]
    public function test_empty_read_replicas_cannot_hide_existing_writer_data(string $table, int $expectedStatus): void
    {
        $this->migrate();
        $this->insertSurvivor($table);
        $before = $this->snapshot();
        $this->useEmptyReadReplica($this->database());
        $this->assertSame(0, $this->database()->table(config('admin.database.'.$table.'_table'))->count());

        [$status, $output] = $this->runInstall();

        $this->assertSame($before, $this->snapshot(), $output);
        $this->assertSame($expectedStatus, $status, $output);
        if ($expectedStatus === 0) {
            $this->assertFileExists(config('admin.directory').'/routes.php');
        } else {
            $this->assertRefused($status, $output);
            $this->assertDirectoryDoesNotExist(config('admin.directory'));
        }
    }

    public function test_unqueryable_configured_model_stops_before_seeding_or_scaffolding(): void
    {
        $this->migrate();
        config(['admin.database.roles_model' => InstallPreservationExternalRole::class]);
        $before = $this->snapshot();

        try {
            $this->runInstall();
            $this->fail('An unqueryable admin model must not be treated as an empty table.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('model_admin_roles', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryDoesNotExist(config('admin.directory'));
    }

    public function test_empty_tables_still_seed_when_the_admin_directory_already_exists(): void
    {
        $directory = config('admin.directory');
        (new Filesystem())->makeDirectory($directory, 0700, true);
        file_put_contents($directory.'/bootstrap.php', "<?php // Existing bootstrap.\n");

        [$status, $output] = $this->runInstall();

        $this->assertSame(0, $status, $output);
        $this->assertTrue(Administrator::firstOrFail()->isAdministrator());
        $this->assertSame(1, Administrator::count());
        $this->assertSame(1, Role::count());
        $this->assertSame(5, Permission::count());
        $this->assertSame(7, Menu::count());
        $this->assertSame("<?php // Existing bootstrap.\n", file_get_contents($directory.'/bootstrap.php'));
        $this->assertStringContainsString('directory already exists', $output);
    }

    private function migrate(): void
    {
        $this->assertSame(0, $this->app[Kernel::class]->call('migrate'));
    }

    private function configureSeparateAdminDatabase(): void
    {
        config(['admin.database.connection' => 'admin_test']);
        foreach ($this->tableNames() as $name => $table) {
            config(['admin.database.'.$name.'_table' => 'tenant_'.$table]);
        }
    }

    private function tableNames(): array
    {
        $tables = [];
        foreach (['users', 'roles', 'permissions', 'menu', 'role_users', 'role_permissions', 'user_permissions', 'role_menu', 'operation_log'] as $name) {
            $tables[$name] = config('admin.database.'.$name.'_table');
        }

        return $tables;
    }

    private function database()
    {
        return $this->app['db']->connection(config('admin.database.connection') ?: null);
    }

    private function useEmptyReadReplica($connection): void
    {
        $replica = new \PDO('sqlite::memory:');
        $tables = $connection->getPdo()->query("select name, sql from sqlite_master where type = 'table'")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            if (!str_starts_with($table['name'], 'sqlite_')) {
                $replica->exec($table['sql']);
            }
        }

        $connection->setReadPdo($replica);
    }

    private function insertSurvivor(string $table): void
    {
        $rows = [
            'users'            => ['id' => 1, 'username' => 'existing-owner', 'password' => 'Existing password hash', 'name' => 'Existing owner'],
            'roles'            => ['id' => 17, 'name' => 'Existing editor', 'slug' => 'existing-editor'],
            'permissions'      => ['id' => 29, 'name' => 'Existing reports permission', 'slug' => 'existing-reports', 'http_method' => 'GET', 'http_path' => '/reports'],
            'menu'             => ['id' => 41, 'parent_id' => 0, 'order' => 13, 'title' => 'Existing reports', 'icon' => 'fa-file', 'uri' => 'reports'],
            'role_users'       => ['role_id' => 17, 'user_id' => 1],
            'role_permissions' => ['role_id' => 17, 'permission_id' => 29],
            'user_permissions' => ['user_id' => 1, 'permission_id' => 29],
            'role_menu'        => ['role_id' => 17, 'menu_id' => 41],
            'operation_log'    => ['id' => 53, 'user_id' => 1, 'path' => 'admin/reports', 'method' => 'GET', 'ip' => '127.0.0.1', 'input' => '{"report":"existing"}'],
        ];

        $this->database()->table(config('admin.database.'.$table.'_table'))->insert($rows[$table] + [
            'created_at' => '2020-01-02 03:04:05', 'updated_at' => '2020-06-07 08:09:10',
        ]);
    }

    private function snapshot(): array
    {
        $data = [];
        foreach ($this->tableNames() as $name => $table) {
            $data[$name] = $this->database()->table($table)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
        }

        return $data;
    }

    private function assertRefused(int $status, string $output): void
    {
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('existing admin data', $output);
        $this->assertStringContainsString('admin:create-user', $output);
        $this->assertStringNotContainsString('Seeding database.', $output);
    }

    private function runInstall(): array
    {
        $kernel = $this->app[Kernel::class];
        $status = $kernel->call('admin:install', ['--no-interaction' => true]);

        return [$status, $kernel->output()];
    }
}

trait InstallPreservationHiddenRows
{
    protected static function booted()
    {
        static::addGlobalScope('hidden-fixture', static fn ($query) => $query->whereRaw('0 = 1'));
    }
}

trait InstallPreservationExternalStorage
{
    use InstallPreservationHiddenRows;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection('model_test');
        $this->setTable('model_'.$this->getTable());
    }
}

class InstallPreservationHiddenUser extends Administrator
{
    use InstallPreservationHiddenRows;
}

class InstallPreservationExternalUser extends Administrator
{
    use InstallPreservationExternalStorage;
}

class InstallPreservationExternalRole extends Role
{
    use InstallPreservationExternalStorage;
}

class InstallPreservationExternalPermission extends Permission
{
    use InstallPreservationExternalStorage;
}

class InstallPreservationExternalMenu extends Menu
{
    use InstallPreservationExternalStorage;
}
