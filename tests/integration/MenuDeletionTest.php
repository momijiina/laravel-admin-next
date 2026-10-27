<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin as AdminFacade;
use Encore\Admin\Form;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MenuDeletionTest extends TestCase
{
    private array $packageState = [];

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Admin' => AdminFacade::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('admin.database.connection', 'admin_testing');
        $app['config']->set('database.default', 'testing');
        foreach (['testing', 'admin_testing'] as $connection) {
            $app['config']->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        AdminFacade::routes();
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs', 'baseCss', 'minifyIgnores'],
            Form::class => ['collectedAssets', 'snakeAttributes', 'initCallbacks'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            $this->assertSame(0, $this->app[Kernel::class]->call('migrate', [
                '--database' => 'admin_testing',
                '--path' => realpath(__DIR__.'/../../database/migrations'),
                '--realpath' => true,
            ]));
            $this->seed(AdminTablesSeeder::class);
            $this->actingAs(Administrator::firstOrFail(), 'admin');
            $this->database()->getSchemaBuilder()->create('menu_deletion_audit', function (Blueprint $table) {
                $table->string('event');
                $table->integer('menu_id');
            });
        } catch (\Throwable $exception) {
            $this->restorePackageState();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            // Testbench clears the application dispatcher and booted Eloquent models.
            parent::tearDown();
        } finally {
            $this->restorePackageState();
        }
    }

    private function restorePackageState(): void
    {
        foreach ($this->packageState as [$property, $value]) {
            $property->setValue(null, $value);
        }
    }

    public static function deletionEntryPoints(): iterable
    {
        yield 'direct model' => [false];
        yield 'authenticated tree endpoint' => [true];
    }

    #[DataProvider('deletionEntryPoints')]
    public function test_deleting_a_four_level_subtree_removes_its_pivots_and_runs_each_model_event_once(bool $http): void
    {
        $tree = $this->createTree();
        $before = $this->databaseState();
        $events = [];
        $successCalls = 0;
        Form::init(function (Form $form) use (&$successCalls) {
            $form->deleted(function () use (&$successCalls) {
                $successCalls++;
            });
        });
        Menu::deleting(function (Menu $menu) use (&$events) {
            $events[] = ['deleting', $menu->id];
        });
        Menu::deleted(function (Menu $menu) use (&$events) {
            $events[] = ['deleted', $menu->id];
        });

        if ($http) {
            $this->deleteJson('/admin/auth/menu/'.$tree['root']->id)->assertOk()->assertJsonPath('status', true);
        } else {
            $this->assertTrue($tree['root']->delete());
        }

        $this->assertSubtreeDeleted($before, $tree);
        $this->assertSame($http ? 1 : 0, $successCalls);
        $expectedEvents = [];
        foreach ($tree as $menu) {
            $expectedEvents[] = ['deleting', $menu->id];
            $expectedEvents[] = ['deleted', $menu->id];
        }
        $this->assertEqualsCanonicalizing($expectedEvents, $events);
        foreach ($tree as $menu) {
            $this->assertLessThan(
                array_search(['deleted', $menu->id], $events, true),
                array_search(['deleting', $menu->id], $events, true)
            );
        }
        foreach (['child' => 'root', 'grandchild' => 'child', 'leaf' => 'grandchild', 'sibling' => 'root'] as $child => $parent) {
            $this->assertLessThan(
                array_search(['deleting', $tree[$parent]->id], $events, true),
                array_search(['deleted', $tree[$child]->id], $events, true)
            );
        }
    }

    public static function deletionVetoes(): iterable
    {
        yield 'direct root veto' => ['root', false];
        yield 'direct deep descendant veto' => ['leaf', false];
        yield 'HTTP root veto' => ['root', true];
        yield 'HTTP deep descendant veto' => ['leaf', true];
    }

    #[DataProvider('deletionVetoes')]
    public function test_a_model_veto_restores_every_menu_and_pivot_and_never_calls_the_form_success_hook(string $vetoed, bool $http): void
    {
        $tree = $this->createTree();
        $before = $this->databaseState();
        $successCalls = 0;
        Form::init(function (Form $form) use (&$successCalls) {
            $form->deleted(function () use (&$successCalls) {
                $successCalls++;
            });
        });
        // Register after boot so Menu's own role-detach listener runs before the veto.
        Menu::deleting(function (Menu $menu) use ($tree, $vetoed) {
            return $menu->id === $tree[$vetoed]->id ? false : null;
        });

        if ($http) {
            $this->deleteJson('/admin/auth/menu/'.$tree['root']->id)->assertOk()->assertJsonPath('status', false);
        } else {
            $this->assertFalse($tree['root']->delete());
        }

        $this->assertSame(0, $successCalls);
        $this->assertSame($before, $this->databaseState());
        $this->assertSame(0, $this->database()->transactionLevel());
    }

    public static function observerFailures(): iterable
    {
        yield 'exception' => [\RuntimeException::class];
        yield 'non-Exception throwable' => [\Error::class];
    }

    #[DataProvider('observerFailures')]
    public function test_an_observer_failure_after_a_prior_child_was_deleted_rolls_back_rows_pivots_and_observer_writes(string $exceptionClass): void
    {
        $tree = $this->createTree();
        $before = $this->databaseState();
        $deletedChildren = [];
        $failure = new $exceptionClass('Stop after a second child was deleted');
        Menu::deleted(function (Menu $menu) use ($tree, &$deletedChildren, $failure) {
            $menu->getConnection()->table('menu_deletion_audit')->insert(['event' => 'deleted', 'menu_id' => $menu->id]);
            if ($menu->parent_id === $tree['root']->id) {
                $deletedChildren[] = $menu->id;
                if (count($deletedChildren) === 2) {
                    $this->assertSame(0, $menu->newQuery()->whereKey($deletedChildren)->count());
                    throw $failure;
                }
            }
        });

        $caught = null;
        try {
            $tree['root']->delete();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertCount(2, $deletedChildren);
        $this->assertSame($before, $this->databaseState());
        $this->assertSame(0, $this->database()->table('menu_deletion_audit')->count());
        $this->assertSame(0, $this->database()->transactionLevel());
    }

    public static function outerTransactionOutcomes(): iterable
    {
        yield 'successful subtree deletion' => [false];
        yield 'vetoed subtree deletion' => [true];
    }

    #[DataProvider('outerTransactionOutcomes')]
    public function test_deletion_uses_the_admin_connection_and_preserves_the_callers_outer_transaction(bool $veto): void
    {
        $tree = $this->createTree();
        $before = $this->databaseState();
        $database = $this->database();
        $default = $this->app['db']->connection();
        $this->assertNotSame($default, $database);
        $this->assertSame('testing', $default->getName());
        $this->assertSame('admin_testing', $tree['root']->getConnectionName());
        $this->assertFalse($default->getSchemaBuilder()->hasTable(config('admin.database.menu_table')));
        if ($veto) {
            Menu::deleting(fn (Menu $menu) => $menu->id === $tree['root']->id ? false : null);
        }

        $database->beginTransaction();
        try {
            $database->table('menu_deletion_audit')->insert(['event' => 'outer transaction', 'menu_id' => $tree['root']->id]);
            $this->assertSame(!$veto, $tree['root']->delete());
            $this->assertSame(1, $database->transactionLevel());
            $this->assertSame(0, $default->transactionLevel());
            $this->assertSame(1, $database->table('menu_deletion_audit')->count());
            if ($veto) {
                $this->assertSame($before, $this->databaseState());
            } else {
                $this->assertSubtreeDeleted($before, $tree);
            }
        } finally {
            $database->rollBack(0);
        }

        $this->assertSame(0, $database->transactionLevel());
        $this->assertSame($before, $this->databaseState());
        $this->assertSame(0, $database->table('menu_deletion_audit')->count());
    }

    private function createTree(): array
    {
        $create = fn (string $title, int $parentId = 0) => Menu::create([
            'parent_id' => $parentId, 'title' => $title, 'icon' => 'fa-bars', 'uri' => '',
        ]);
        $root = $create('Delete this subtree');
        $child = $create('Child', $root->id);
        $grandchild = $create('Grandchild', $child->id);
        $leaf = $create('Fourth level', $grandchild->id);
        $sibling = $create('Sibling child', $root->id);
        $unrelated = $create('Keep this subtree');
        $unrelatedChild = $create('Keep this child', $unrelated->id);
        $role = Role::create(['name' => 'Menu reader', 'slug' => 'menu-reader']);
        $tree = compact('root', 'child', 'grandchild', 'leaf', 'sibling');
        foreach ([...array_values($tree), $unrelated, $unrelatedChild] as $menu) {
            $menu->roles()->attach($role->id);
        }
        $root->roles()->attach(Role::firstOrFail()->id);
        $leaf->roles()->attach(Role::firstOrFail()->id);

        return $tree;
    }

    private function database(): Connection
    {
        return $this->app['db']->connection('admin_testing');
    }

    private function databaseState(): array
    {
        $state = [];
        foreach (['menu' => ['id'], 'role_menu' => ['menu_id', 'role_id'], 'roles' => ['id']] as $table => $columns) {
            $query = $this->database()->table(config('admin.database.'.$table.'_table'));
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $state[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function assertSubtreeDeleted(array $before, array $tree): void
    {
        $ids = array_map(fn (Menu $menu) => $menu->id, array_values($tree));
        foreach (['menu' => 'id', 'role_menu' => 'menu_id'] as $table => $column) {
            $before[$table] = array_values(array_filter($before[$table], fn ($row) => !in_array($row[$column], $ids, true)));
        }
        $this->assertSame($before, $this->databaseState());
    }
}
