<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Middleware\Permission as PermissionMiddleware;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    private Administrator $user;

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Admin' => Admin::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
        $router->aliasMiddleware('permission.alias', PermissionMiddleware::class);
        $router->middlewareGroup('permission.editor', [
            'admin.permission:allow,editor',
            'permission.alias:check,edit-records',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        $this->user = Administrator::create([
            'username' => 'restricted', 'name' => 'Restricted account',
            'password' => bcrypt('fixture-password'),
        ]);
        // Checker::error() aborts with 403 for AJAX; ordinary HTML uses send/exit.
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function route(array $rules = [], string $path = 'rules', array $base = ['web', 'admin']): Route
    {
        return $this->app['router']->get('/admin/middleware/'.$path, function () {
            return response()->json(['reached' => true]);
        })->middleware(array_merge($base, $rules));
    }

    private function assignRole(string $slug): Role
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $this->user->roles()->attach($role);

        return $role;
    }

    private function assignPermission(string $slug = 'edit-records', string $path = '/elsewhere'): Permission
    {
        $permission = Permission::create([
            'name' => $slug, 'slug' => $slug, 'http_method' => ['GET'], 'http_path' => $path,
        ]);
        $this->user->permissions()->attach($permission);

        return $permission;
    }

    private function login(): void
    {
        $this->post('/admin/auth/login', [
            'username' => 'restricted', 'password' => 'fixture-password',
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($this->user, 'admin');
    }

    public function test_later_permission_rule_cannot_be_bypassed_by_an_allowed_role(): void
    {
        $this->route(['admin.permission:allow,editor', 'admin.permission:check,edit-records']);
        $this->assignRole('editor');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_later_role_rule_cannot_be_bypassed_by_an_assigned_permission(): void
    {
        $this->route(['admin.permission:check,edit-records', 'admin.permission:allow,editor']);
        $this->assignPermission();
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_all_satisfied_rules_override_the_default_path_permission_check(): void
    {
        $this->route(['admin.permission:allow,editor', 'admin.permission:check,edit-records']);
        $role = $this->assignRole('editor');
        $permission = $this->assignPermission();
        // Exercise a role permission as well as the direct assignment used elsewhere.
        $this->user->permissions()->detach($permission);
        $role->permissions()->attach($permission);
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_nested_route_group_rules_are_all_enforced(): void
    {
        $this->app['router']->group(['middleware' => 'admin.permission:allow,editor'], function () {
            $this->route(['admin.permission:check,edit-records']);
        });
        $this->assignRole('editor');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_named_middleware_groups_resolve_every_explicit_rule(): void
    {
        $this->route(['permission.editor']);
        $this->assignRole('editor');
        $this->assignPermission('path-access', '/middleware/rules');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_class_names_and_custom_aliases_enforce_later_rules(): void
    {
        $this->route([PermissionMiddleware::class.':allow,editor', 'permission.alias:check,edit-records']);
        $this->assignRole('editor');
        // A path grant must not override the explicit missing slug.
        $this->assignPermission('path-access', '/middleware/rules');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_duplicate_aliases_and_class_names_keep_explicit_overrides(): void
    {
        $this->route([
            PermissionMiddleware::class,
            'admin.permission:allow,editor',
            PermissionMiddleware::class.':allow,editor',
            'permission.alias:check,edit-records',
            'admin.permission:check,edit-records',
        ]);
        $this->assignRole('editor');
        $this->assignPermission();
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_parameterized_middleware_enforces_rules_without_the_admin_group(): void
    {
        $this->route(['permission.alias:check,edit-records'], 'alias', ['web', 'admin.auth']);
        $this->route([PermissionMiddleware::class.':check,edit-records'], 'class', ['web', 'admin.auth']);
        $this->login();

        $this->getJson('/admin/middleware/alias')->assertForbidden();
        $this->getJson('/admin/middleware/class')->assertForbidden();
    }

    public function test_explicit_aliases_classes_and_named_groups_allow_assigned_permissions(): void
    {
        $this->route(['permission.alias:check,edit-records'], 'alias', ['web', 'admin.auth']);
        $this->route([PermissionMiddleware::class.':check,edit-records'], 'class', ['web', 'admin.auth']);
        $this->route([PermissionMiddleware::class.':check,edit-records'], 'grouped');
        $this->route(['permission.editor'], 'named-group');
        $this->assignRole('editor');
        $this->assignPermission();
        $this->login();

        $this->getJson('/admin/middleware/alias')->assertOk()->assertExactJson(['reached' => true]);
        $this->getJson('/admin/middleware/class')->assertOk()->assertExactJson(['reached' => true]);
        $this->getJson('/admin/middleware/grouped')->assertOk()->assertExactJson(['reached' => true]);
        $this->getJson('/admin/middleware/named-group')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_free_does_not_cancel_a_later_permission_rule(): void
    {
        $this->route(['admin.permission:free'], 'free');
        $this->route(['admin.permission:free', 'admin.permission:check,edit-records']);
        $this->login();

        $this->getJson('/admin/middleware/free')->assertOk()->assertExactJson(['reached' => true]);
        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_allow_and_deny_keep_their_role_membership_semantics(): void
    {
        $rules = ['admin.permission:allow,reviewer,editor'];
        $this->route(array_merge($rules, ['admin.permission:deny,suspended,operator']));
        $this->route(array_merge($rules, ['admin.permission:deny,developer,operator']), 'allowed');
        $this->assignRole('editor');
        $this->assignRole('suspended');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
        $this->getJson('/admin/middleware/allowed')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_check_still_requires_every_slug_in_one_rule(): void
    {
        $this->route(['admin.permission:check,edit-records,publish-records']);
        $this->route(['admin.permission:check,edit-records'], 'single');
        $this->assignPermission();
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
        $this->getJson('/admin/middleware/single')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_invalid_later_method_fails_before_the_controller_runs(): void
    {
        $this->route(['admin.permission:free', 'admin.permission:unknown,edit-records']);
        $this->login();
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid permission method [unknown].');

        $this->getJson('/admin/middleware/rules');
    }

    public function test_controller_middleware_is_included_in_the_permission_rules(): void
    {
        $this->app['router']->get('/admin/middleware/controller', [PermissionRulesController::class, 'index'])
            ->middleware(['web', 'admin']);
        $this->assignRole('editor');
        $this->assignPermission('path-access', '/middleware/controller');
        $this->login();

        $this->getJson('/admin/middleware/controller')->assertForbidden();
    }

    public function test_route_without_explicit_rules_still_checks_path_permissions(): void
    {
        $this->route([], 'allowed');
        $this->route([], 'denied');
        $this->assignPermission('path-access', '/middleware/allowed');
        $this->login();

        $this->getJson('/admin/middleware/allowed')->assertOk()->assertExactJson(['reached' => true]);
        $this->getJson('/admin/middleware/denied')->assertForbidden();
    }

    public function test_excluded_explicit_middleware_does_not_bypass_path_permissions(): void
    {
        $this->route(['admin.permission:free'])->withoutMiddleware('admin.permission:free');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertForbidden();
    }

    public function test_disabled_permission_checks_still_bypass_all_explicit_rules(): void
    {
        $this->route(['admin.permission:check,edit-records', 'admin.permission:unknown']);
        config(['admin.check_route_permission' => false]);
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_auth_excepts_still_bypass_all_explicit_rules(): void
    {
        $this->route(['admin.permission:check,edit-records', 'admin.permission:unknown']);
        config(['admin.auth.excepts' => array_merge(config('admin.auth.excepts'), ['middleware/rules'])]);
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_administrator_role_still_bypasses_valid_role_and_permission_checks(): void
    {
        $this->route(['admin.permission:allow,editor', 'admin.permission:deny,administrator', 'admin.permission:check,edit-records']);
        $this->assignRole('administrator');
        $this->login();

        $this->getJson('/admin/middleware/rules')->assertOk()->assertExactJson(['reached' => true]);
    }

    public function test_guest_is_redirected_by_the_authentication_middleware(): void
    {
        $this->route(['admin.permission:free', 'admin.permission:check,edit-records']);

        $this->getJson('/admin/middleware/rules')->assertRedirect('/admin/auth/login');
        $this->assertGuest('admin');
    }
}

class PermissionRulesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['admin.permission:allow,editor', 'admin.permission:check,edit-records']);
    }

    public function index()
    {
        return response()->json(['reached' => true]);
    }
}
