<?php

namespace Encore\Admin\Console;

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the admin package';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $exitCode = $this->initDatabase();

        // Existing overrides may complete successfully without returning a code.
        if ($exitCode !== null && $exitCode !== 0) {
            return $exitCode;
        }

        $this->initAdminDirectory();

        return 0;
    }

    /**
     * Create tables and seed it.
     *
     * @return int
     */
    public function initDatabase()
    {
        $exitCode = $this->call('migrate');

        if ($exitCode !== 0) {
            return $exitCode;
        }

        $userModel = config('admin.database.users_model');
        $user = new $userModel();

        // Scopes and stale read replicas must not hide accounts from this check.
        if ($user->getConnection()->table($user->getTable())->useWritePdo()->exists()) {
            return 0;
        }

        if ($this->hasExistingAdminData()) {
            $this->error('Cannot seed: existing admin data was found without an account in the configured user table.');
            $this->line('Restore an administrator account or use admin:create-user with the appropriate existing roles, then retry admin:install.');

            return 1;
        }

        return $this->call('db:seed', ['--class' => \Encore\Admin\Auth\Database\AdminTablesSeeder::class]);
    }

    /**
     * Only run the legacy seeder against an unused admin database.
     *
     * @return bool
     */
    protected function hasExistingAdminData()
    {
        $connection = $this->laravel['db']->connection(config('admin.database.connection') ?: null);

        // The seeder uses built-in models, even when different models are configured.
        // Surviving pivots and logs must not become associated with new default IDs.
        foreach (['users', 'roles', 'permissions', 'menu', 'role_users', 'role_permissions', 'user_permissions', 'role_menu', 'operation_log'] as $table) {
            if ($connection->table(config('admin.database.'.$table.'_table'))->useWritePdo()->exists()) {
                return true;
            }
        }

        foreach (['roles', 'permissions', 'menu'] as $name) {
            $class = config('admin.database.'.$name.'_model');
            $model = new $class();

            if ($model->getConnection()->table($model->getTable())->useWritePdo()->exists()) {
                return true;
            }
        }

        // Related configured models can put these pivots on another connection.
        foreach ([(new Administrator())->roles(), (new Administrator())->permissions(), (new Role())->permissions(), (new Menu())->roles()] as $relation) {
            if ($relation->newPivotStatement()->useWritePdo()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Initialize the admAin directory.
     *
     * @return void
     */
    protected function initAdminDirectory()
    {
        $this->directory = config('admin.directory');

        if (is_dir($this->directory)) {
            $this->line("<error>{$this->directory} directory already exists !</error> ");

            return;
        }

        $this->makeDir('/');
        $this->line('<info>Admin directory was created:</info> '.str_replace(base_path(), '', $this->directory));

        $this->makeDir('Controllers');

        $this->createHomeController();
        $this->createAuthController();
        $this->createExampleController();

        $this->createBootstrapFile();
        $this->createRoutesFile();
    }

    /**
     * Create HomeController.
     *
     * @return void
     */
    public function createHomeController()
    {
        $homeController = $this->directory.'/Controllers/HomeController.php';
        $contents = $this->getStub('HomeController');

        $this->laravel['files']->put(
            $homeController,
            str_replace('DummyNamespace', config('admin.route.namespace'), $contents)
        );
        $this->line('<info>HomeController file was created:</info> '.str_replace(base_path(), '', $homeController));
    }

    /**
     * Create AuthController.
     *
     * @return void
     */
    public function createAuthController()
    {
        $authController = $this->directory.'/Controllers/AuthController.php';
        $contents = $this->getStub('AuthController');

        $this->laravel['files']->put(
            $authController,
            str_replace('DummyNamespace', config('admin.route.namespace'), $contents)
        );
        $this->line('<info>AuthController file was created:</info> '.str_replace(base_path(), '', $authController));
    }

    /**
     * Create HomeController.
     *
     * @return void
     */
    public function createExampleController()
    {
        $exampleController = $this->directory.'/Controllers/ExampleController.php';
        $contents = $this->getStub('ExampleController');

        $this->laravel['files']->put(
            $exampleController,
            str_replace('DummyNamespace', config('admin.route.namespace'), $contents)
        );
        $this->line('<info>ExampleController file was created:</info> '.str_replace(base_path(), '', $exampleController));
    }

    /**
     * Create routes file.
     *
     * @return void
     */
    protected function createBootstrapFile()
    {
        $file = $this->directory.'/bootstrap.php';

        $contents = $this->getStub('bootstrap');
        $this->laravel['files']->put($file, $contents);
        $this->line('<info>Bootstrap file was created:</info> '.str_replace(base_path(), '', $file));
    }

    /**
     * Create routes file.
     *
     * @return void
     */
    protected function createRoutesFile()
    {
        $file = $this->directory.'/routes.php';

        $contents = $this->getStub('routes');
        $this->laravel['files']->put($file, str_replace('DummyNamespace', config('admin.route.namespace'), $contents));
        $this->line('<info>Routes file was created:</info> '.str_replace(base_path(), '', $file));
    }

    /**
     * Get stub contents.
     *
     * @param $name
     *
     * @return string
     */
    protected function getStub($name)
    {
        return $this->laravel['files']->get(__DIR__."/stubs/$name.stub");
    }

    /**
     * Make new directory.
     *
     * @param string $path
     */
    protected function makeDir($path = '')
    {
        $this->laravel['files']->makeDirectory("{$this->directory}/$path", 0755, true, true);
    }
}
