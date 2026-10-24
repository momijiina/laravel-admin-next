<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class PermissionEvaluationTest extends TestCase
{
    private int $permissionNumber = 0;

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
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
        foreach (['admin', 'staff/tools', ''] as $prefix) {
            $router->group(['prefix' => $prefix, 'middleware' => ['web', 'admin']], function ($router) {
                $response = fn () => response()->json(['permission_evaluation_reached' => true]);
                $router->any('/', $response);
                $router->any('{zero}', $response)->where('zero', '0');
                $router->get('permission-evaluation/zero-slug', $response)
                    ->middleware('admin.permission:check,0');
                $router->match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'REPORT', '0', '00'],
                    'permission-evaluation/{path}', $response)->where('path', '.*');
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        // The existing checker aborts with 403 for AJAX requests without PJAX.
        // Its normal HTML/PJAX response uses send()+exit and is outside this test.
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    public function test_seeded_administrator_and_restricted_user_have_distinct_access(): void
    {
        $this->actingAs(Administrator::first(), 'admin');
        $this->getJson('/admin/permission-evaluation/unrelated')->assertOk()
            ->assertJsonPath('permission_evaluation_reached', true);
        $this->actingAs($this->restrictedUser(), 'admin');
        $this->getJson('/admin/permission-evaluation/unrelated')->assertForbidden();
    }

    public function test_direct_and_role_permissions_keep_method_and_wildcard_limits(): void
    {
        $user = $this->restrictedUser();
        $permission = $this->permission('/permission-evaluation/widgets/*', ['GET']);
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $role->permissions()->attach($permission);

        foreach (['direct', 'role'] as $assignment) {
            $user->permissions()->sync($assignment === 'direct' ? [$permission->id] : []);
            $user->roles()->sync($assignment === 'role' ? [$role->id] : []);
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson('/admin/permission-evaluation/widgets/12')->assertOk();
            $this->deleteJson('/admin/permission-evaluation/widgets/12')->assertForbidden();
            $this->getJson('/admin/permission-evaluation/unrelated')->assertForbidden();
            $this->assertInstanceOf(Permission::class, $user->fresh()->allPermissions()->first());
        }
    }

    public function test_zero_path_is_scoped_to_zero_when_admin_has_no_prefix(): void
    {
        config(['admin.route.prefix' => '']);
        $user = $this->restrictedUser();
        foreach ([0, '0'] as $path) {
            $permission = $this->permission($path);
            $user->permissions()->sync([$permission->id]);
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson('/0')->assertOk();
            $this->postJson('/0')->assertOk();
            $this->getJson('/permission-evaluation/unrelated')->assertForbidden();
        }
    }

    public function test_zero_path_cannot_grant_all_prefixed_routes(): void
    {
        $user = $this->restrictedUser();
        $permission = $this->permission('0');
        $user->permissions()->attach($permission);
        $this->actingAs($user->fresh(), 'admin');
        $this->getJson('/admin/permission-evaluation/unrelated')->assertForbidden();

        // A prefixed path uses the documented leading slash.
        $permission->update(['http_path' => '/0']);
        $this->actingAs($user->fresh(), 'admin');
        $this->getJson('/admin/0')->assertOk();
        $this->getJson('/admin/permission-evaluation/unrelated')->assertForbidden();
    }

    public function test_zero_slug_requires_a_direct_or_role_permission(): void
    {
        $user = $this->restrictedUser();
        $permission = $this->permission('/permission-evaluation/zero-slug', ['GET'], '0');
        $this->assertFalse($user->can('0'));
        $this->assertFalse($user->can(0));
        $this->assertTrue($user->cannot('0'));
        $this->actingAs($user, 'admin');
        $this->getJson('/admin/permission-evaluation/zero-slug')->assertForbidden();

        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $role->permissions()->attach($permission);
        foreach (['direct', 'role'] as $assignment) {
            $user->permissions()->sync($assignment === 'direct' ? [$permission->id] : []);
            $user->roles()->sync($assignment === 'role' ? [$role->id] : []);
            $current = $user->fresh();
            $this->assertTrue($current->can('0'));
            $this->assertTrue($current->can(0));
            $this->assertFalse($current->cannot('0'));
            $this->actingAs($current, 'admin');
            $this->getJson('/admin/permission-evaluation/zero-slug')->assertOk();
        }
    }

    public function test_zero_slug_controls_the_shipped_menu_partial(): void
    {
        $user = $this->restrictedUser();
        $item = ['title' => 'Zero permission menu', 'icon' => 'fa-lock',
            'uri' => 'permission-evaluation/zero-slug', 'permission' => '0'];
        $this->actingAs($user, 'admin');
        $this->assertStringNotContainsString('Zero permission menu',
            view('admin::partials.menu', compact('item'))->render());
        $user->permissions()->attach($this->permission('/permission-evaluation/zero-slug', ['GET'], '0'));
        $this->actingAs($user->fresh(), 'admin');
        $this->assertStringContainsString('Zero permission menu',
            view('admin::partials.menu', compact('item'))->render());
    }

    public function test_numeric_looking_slugs_do_not_satisfy_a_zero_ability(): void
    {
        $user = $this->restrictedUser();
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $exact = $this->permission('/permission-evaluation/zero-slug', ['GET'], '0');
        foreach (['00', '0e123'] as $slug) {
            $different = $this->permission('/permission-evaluation/zero-slug', ['GET'], $slug);
            foreach (['direct', 'role'] as $assignment) {
                $role->permissions()->sync([$different->id]);
                $user->permissions()->sync($assignment === 'direct' ? [$different->id] : []);
                $user->roles()->sync($assignment === 'role' ? [$role->id] : []);
                $current = $user->fresh();
                $this->assertTrue($current->can($slug));
                $this->assertFalse($current->can('0'));
                $this->assertFalse($current->can(0));
                $this->actingAs($current, 'admin');
                $this->getJson('/admin/permission-evaluation/zero-slug')->assertForbidden();

                if ($assignment === 'direct') {
                    $user->permissions()->attach($exact);
                } else {
                    $role->permissions()->attach($exact);
                }
                $current = $user->fresh();
                $this->assertTrue($current->can('0'));
                $this->assertTrue($current->can(0));
                $this->actingAs($current, 'admin');
                $this->getJson('/admin/permission-evaluation/zero-slug')->assertOk();
            }
        }
    }

    public function test_existing_empty_abilities_and_administrator_bypass_are_preserved(): void
    {
        $user = $this->restrictedUser();
        foreach ([null, '', false, [], 0.0] as $ability) {
            $this->assertTrue($user->can($ability));
        }
        $administrator = Administrator::first();
        $this->assertTrue($administrator->can('0'));
        $this->assertTrue($administrator->can(0));
        $this->assertTrue($administrator->can('unassigned'));
    }

    public function test_path_method_overrides_apply_before_default_custom_and_empty_prefixes(): void
    {
        $user = $this->restrictedUser();
        $user->permissions()->attach($this->permission(
            "get,post:/permission-evaluation/widgets/*\r\n/permission-evaluation/inherited\r\nREPORT:/permission-evaluation/custom-method",
            ['DELETE']
        ));
        foreach (['admin', 'staff/tools', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            $base = '/'.($prefix === '' ? '' : $prefix.'/').'permission-evaluation/';
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson($base.'widgets/12')->assertOk();
            $this->postJson($base.'widgets/12')->assertOk();
            $this->deleteJson($base.'widgets/12')->assertForbidden();
            $this->deleteJson($base.'inherited')->assertOk();
            $this->getJson($base.'inherited')->assertForbidden();
            $this->json('REPORT', $base.'custom-method')->assertOk();
            $this->deleteJson($base.'custom-method')->assertForbidden();
            $this->getJson($base.'unrelated')->assertForbidden();
        }
    }

    public function test_literal_colons_are_preserved_in_paths_and_method_overrides(): void
    {
        $user = $this->restrictedUser();
        $user->permissions()->attach($this->permission(
            "/permission-evaluation/literal:2026:10\nGET:/permission-evaluation/override:2026:10", ['POST']
        ));
        foreach (['admin', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            $base = '/'.($prefix === '' ? '' : $prefix.'/').'permission-evaluation/';
            $this->actingAs($user->fresh(), 'admin');
            $this->postJson($base.'literal:2026:10')->assertOk();
            $this->getJson($base.'literal:2026:10')->assertForbidden();
            $this->getJson($base.'override:2026:10')->assertOk();
            $this->postJson($base.'override:2026:10')->assertForbidden();
            $this->getJson($base.'override')->assertForbidden();
        }
    }

    public function test_zero_http_method_field_is_literal(): void
    {
        $user = $this->restrictedUser();
        foreach ([0, '0'] as $method) {
            $permission = $this->permission('/permission-evaluation/method', [$method]);
            $user->permissions()->sync([$permission->id]);
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson('/admin/permission-evaluation/method')->assertForbidden();
            $this->postJson('/admin/permission-evaluation/method')->assertForbidden();
            $this->json('0', '/admin/permission-evaluation/method')->assertOk();
            $this->json('00', '/admin/permission-evaluation/method')->assertForbidden();
        }
    }

    public function test_zero_per_path_http_method_is_literal(): void
    {
        $user = $this->restrictedUser();
        $user->permissions()->attach($this->permission('0:/permission-evaluation/method'));
        foreach (['admin', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            $uri = '/'.($prefix === '' ? '' : $prefix.'/').'permission-evaluation/method';
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson($uri)->assertForbidden();
            $this->postJson($uri)->assertForbidden();
            $this->json('0', $uri)->assertOk();
            $this->json('00', $uri)->assertForbidden();
        }
    }

    public function test_zero_method_can_share_a_rule_with_get(): void
    {
        $user = $this->restrictedUser();
        foreach ([['/permission-evaluation/method', ['0', 'GET']],
            ['0,GET:/permission-evaluation/method', []]] as [$path, $methods]) {
            $user->permissions()->sync([$this->permission($path, $methods)->id]);
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson('/admin/permission-evaluation/method')->assertOk();
            $this->json('0', '/admin/permission-evaluation/method')->assertOk();
            $this->postJson('/admin/permission-evaluation/method')->assertForbidden();
            $this->json('00', '/admin/permission-evaluation/method')->assertForbidden();
        }
    }

    public function test_empty_method_marker_and_other_empty_values_keep_their_meaning(): void
    {
        $user = $this->restrictedUser();
        $user->permissions()->attach($this->permission(':/permission-evaluation/method', ['DELETE']));
        foreach (['admin', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            $uri = '/'.($prefix === '' ? '' : $prefix.'/').'permission-evaluation/method';
            $this->actingAs($user->fresh(), 'admin');
            $this->getJson($uri)->assertOk();
            $this->postJson($uri)->assertOk();
            $this->json('0', $uri)->assertOk();

            foreach ([null, '', [], [null, '', false, 0.0]] as $methods) {
                $permission = (new Permission())->setRawAttributes([
                    'http_path' => '/permission-evaluation/method', 'http_method' => $methods,
                ]);
                $this->assertTrue($permission->shouldPassThrough(Request::create($uri, 'GET')));
            }
        }
    }

    public function test_nullable_and_blank_paths_keep_their_existing_meaning_without_deprecations(): void
    {
        $user = $this->restrictedUser();
        $diagnostics = [];
        set_error_handler(function ($severity, $message) use (&$diagnostics) {
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                $diagnostics[] = $message;

                return true;
            }

            return false;
        });
        try {
            foreach ([null, ''] as $path) {
                $permission = $this->permission($path);
                $user->permissions()->sync([$permission->id]);
                $this->actingAs($user->fresh(), 'admin');
                $this->getJson('/admin/permission-evaluation/unrelated')->assertOk();
                $this->postJson('/admin/permission-evaluation/unrelated')->assertOk();
                $permission->update(['http_method' => ['GET']]);
                $this->actingAs($user->fresh(), 'admin');
                $this->getJson('/admin')->assertOk();
                $this->postJson('/admin')->assertForbidden();
                $this->getJson('/admin/permission-evaluation/unrelated')->assertForbidden();
            }
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $diagnostics);
    }

    public function test_configured_permission_and_role_models_keep_their_custom_matcher(): void
    {
        config(['admin.database.permissions_model' => EvaluationCustomPermission::class,
            'admin.database.roles_model' => EvaluationCustomRole::class]);
        $user = $this->restrictedUser();
        $permission = $this->permission('/different-path', ['GET']);
        $role = EvaluationCustomRole::create(['name' => 'Custom editor', 'slug' => 'custom-editor']);
        $role->permissions()->attach($permission);
        foreach (['direct', 'role'] as $assignment) {
            $user->permissions()->sync($assignment === 'direct' ? [$permission->id] : []);
            $user->roles()->sync($assignment === 'role' ? [$role->id] : []);
            $this->actingAs($user->fresh(), 'admin');
            $this->assertInstanceOf(EvaluationCustomPermission::class, $user->fresh()->allPermissions()->first());
            $this->patchJson('/admin/permission-evaluation/custom-model')->assertOk();
            $this->getJson('/admin/permission-evaluation/custom-model')->assertForbidden();
            $this->patchJson('/admin/permission-evaluation/unrelated')->assertForbidden();
        }
    }

    private function restrictedUser(): Administrator
    {
        return Administrator::create([
            'username' => 'restricted', 'name' => 'Restricted user', 'password' => bcrypt('fixture-password'),
        ]);
    }

    private function permission($path, array $methods = [], ?string $slug = null): Permission
    {
        $model = config('admin.database.permissions_model');
        $name = 'permission-'.++$this->permissionNumber;

        return $model::create([
            'name' => $name, 'slug' => $slug ?? $name, 'http_path' => $path, 'http_method' => $methods,
        ]);
    }
}

class EvaluationCustomPermission extends Permission
{
    public function shouldPassThrough(Request $request): bool
    {
        return $request->is('admin/permission-evaluation/custom-model') && $request->isMethod('PATCH');
    }
}

class EvaluationCustomRole extends Role
{
}
