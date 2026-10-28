<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class MenuSearchVisibilityTest extends TestCase
{
    private Administrator $reader;
    private Role $readerRole;
    private Role $otherRole;
    private Permission $allowedPermission;
    private int $menuOrder = 0;

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
        $app['config']->set('admin.enable_menu_search', true);
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
        $router->get('/admin/menu-search-visibility', fn () => view('admin::partials.sidebar'))
            ->middleware(['web', 'admin']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->reader = Administrator::create([
            'username' => 'menu-reader', 'name' => 'Menu reader', 'password' => bcrypt('password'),
        ]);
        $this->readerRole = Role::create(['name' => 'Menu reader', 'slug' => 'menu-reader']);
        $this->otherRole = Role::create(['name' => 'Other menu role', 'slug' => 'menu-other']);
        $this->reader->roles()->attach($this->readerRole);
        // Permit the fixture page through the real middleware without granting its menu abilities.
        $this->reader->permissions()->attach($this->permission('menu.page', '/menu-search-visibility'));
        $this->allowedPermission = $this->permission('menu.allowed');
        $this->permission('menu.denied');
    }

    public static function permissionAssignments(): iterable
    {
        yield 'direct permission, role checks enabled' => [false, true];
        yield 'role permission, role checks enabled' => [true, true];
        yield 'direct permission, role checks disabled' => [false, false];
        yield 'role permission, role checks disabled' => [true, false];
    }

    #[DataProvider('permissionAssignments')]
    public function test_search_and_sidebar_show_the_same_allowed_leaves_and_hide_blocked_subtrees(
        bool $throughRole,
        bool $checkRoles
    ): void {
        config(['admin.check_menu_roles' => $checkRoles]);
        $this->grant($this->allowedPermission, $throughRole);
        $this->actingAs($this->reader->fresh(), 'admin');
        $menus = $this->createVisibilityTree();

        $expected = $checkRoles
            ? ['public', 'empty', 'role_allowed', 'permission_allowed', 'both_allowed',
                'public_child', 'allowed_child', 'visible_grandchild', 'last']
            : ['public', 'empty', 'role_allowed', 'role_denied', 'permission_allowed',
                'both_allowed', 'both_role_denied', 'public_child', 'allowed_child',
                'role_denied_child', 'visible_grandchild', 'trapped_role_child', 'last'];

        $this->assertMenuPage($menus, $expected);
    }

    public function test_administrator_sees_all_leaves_in_the_same_depth_first_order(): void
    {
        $administrator = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $this->reader->roles()->attach($administrator);
        $this->actingAs($this->reader->fresh(), 'admin');
        $menus = $this->createVisibilityTree();

        $this->assertMenuPage($menus, [
            'public', 'empty', 'role_allowed', 'role_denied', 'permission_allowed',
            'permission_denied', 'both_allowed', 'both_role_denied', 'both_permission_denied',
            'public_child', 'allowed_child', 'role_denied_child', 'permission_denied_child',
            'visible_grandchild', 'trapped_grandchild', 'trapped_role_child',
            'trapped_permission_child', 'last',
        ]);
    }

    public static function explicitMenuContexts(): iterable
    {
        yield 'restricted user' => ['reader', true];
        yield 'explicit roles still apply when database role loading is disabled' => ['reader', false];
        yield 'administrator bypass' => ['administrator', true];
        yield 'guest helper compatibility' => ['guest', true];
    }

    #[DataProvider('explicitMenuContexts')]
    public function test_explicit_menu_argument_preserves_leaf_shape_and_order(string $user, bool $checkRoles): void
    {
        config(['admin.check_menu_roles' => $checkRoles]);
        $this->grant($this->allowedPermission, false);
        if ($user === 'administrator') {
            $this->reader->roles()->attach(Role::create(['name' => 'Administrator', 'slug' => 'administrator']));
        }
        if ($user !== 'guest') {
            $this->actingAs($this->reader->fresh(), 'admin');
        }
        $this->menu('database_menu_must_not_replace_explicit_argument');

        $leaf = fn (string $title, array $extra = []) => [
            'title' => $title, 'uri' => 'custom/'.$title, 'icon' => 'fa-link',
        ] + $extra;
        $menu = [
            30 => $leaf('first', ['extra' => 'discard this metadata']),
            4 => $leaf('allowed_parent', [
                'roles' => [$this->readerRole->toArray()], 'permission' => 'menu.allowed',
                'children' => [
                    'nested' => $leaf('nested_parent', ['children' => [$leaf('nested_leaf')]]),
                    8 => $leaf('denied_leaf', ['permission' => 'menu.denied']),
                ],
            ]),
            22 => $leaf('role_denied_parent', [
                'roles' => [$this->otherRole->toArray()],
                'children' => [$leaf('role_parent_child', ['permission' => 'menu.allowed'])],
            ]),
            5 => $leaf('permission_denied_parent', [
                'permission' => 'menu.denied', 'children' => [$leaf('permission_parent_child')],
            ]),
            19 => $leaf('last', ['roles' => [], 'permission' => null]),
        ];
        $expected = $user === 'reader'
            ? ['first', 'nested_leaf', 'last']
            : ['first', 'nested_leaf', 'denied_leaf', 'role_parent_child', 'permission_parent_child', 'last'];

        $this->assertSame(array_map($leaf, $expected), Admin::menuLinks($menu));
    }

    public static function zeroPermissionAssignments(): iterable
    {
        yield 'direct zero permission' => [false];
        yield 'role zero permission' => [true];
    }

    #[DataProvider('zeroPermissionAssignments')]
    public function test_zero_permission_is_checked_on_every_call_in_search_and_sidebar(bool $throughRole): void
    {
        $menus = ['public' => $this->menu('public'), 'zero' => $this->menu('zero', ['permission' => '0'])];
        $this->grant($this->permission('00'), $throughRole);
        $this->actingAs($this->reader->fresh(), 'admin');
        $integerZero = [['title' => 'Integer zero', 'uri' => 'custom/zero', 'icon' => 'fa-lock', 'permission' => 0]];
        $this->assertMenuPage($menus, ['public']);
        $this->assertSame([], Admin::menuLinks($integerZero));

        $zero = $this->permission('0');
        $this->grant($zero, $throughRole);
        $this->actingAs($this->reader->fresh(), 'admin');
        $this->assertMenuPage($menus, ['public', 'zero']);
        $this->assertSame([
            ['title' => 'Integer zero', 'uri' => 'custom/zero', 'icon' => 'fa-lock'],
        ], Admin::menuLinks($integerZero));

        ($throughRole ? $this->readerRole : $this->reader)->permissions()->detach($zero);
        $this->actingAs($this->reader->fresh(), 'admin');
        $this->assertMenuPage($menus, ['public']);
        $this->assertSame([], Admin::menuLinks($integerZero));
    }

    private function permission(string $slug, string $path = '/menu-search-targets/*'): Permission
    {
        return Permission::create([
            'name' => 'Permission '.$slug, 'slug' => $slug, 'http_method' => ['GET'], 'http_path' => $path,
        ]);
    }

    private function grant(Permission $permission, bool $throughRole): void
    {
        ($throughRole ? $this->readerRole : $this->reader)->permissions()->attach($permission);
    }

    private function menu(string $key, array $attributes = []): Menu
    {
        return Menu::create($attributes + [
            'parent_id' => 0, 'order' => ++$this->menuOrder, 'title' => str_replace('_', ' ', $key),
            'icon' => 'fa-file', 'uri' => 'menu-search-targets/'.$key,
        ]);
    }

    private function createVisibilityTree(): array
    {
        // Each row specifies its parent, role and permission independently.
        $definitions = [
            'public' => [null, null, null],
            'empty' => [null, null, ''],
            'role_allowed' => [null, $this->readerRole, null],
            'role_denied' => [null, $this->otherRole, null],
            'permission_allowed' => [null, null, 'menu.allowed'],
            'permission_denied' => [null, null, 'menu.denied'],
            'both_allowed' => [null, $this->readerRole, 'menu.allowed'],
            'both_role_denied' => [null, $this->otherRole, 'menu.allowed'],
            'both_permission_denied' => [null, $this->readerRole, 'menu.denied'],
            'visible_parent' => [null, $this->readerRole, 'menu.allowed'],
            'public_child' => ['visible_parent', null, null],
            'allowed_child' => ['visible_parent', $this->readerRole, 'menu.allowed'],
            'role_denied_child' => ['visible_parent', $this->otherRole, 'menu.allowed'],
            'permission_denied_child' => ['visible_parent', $this->readerRole, 'menu.denied'],
            'visible_nested_parent' => ['visible_parent', null, null],
            'visible_grandchild' => ['visible_nested_parent', $this->readerRole, 'menu.allowed'],
            'denied_nested_parent' => ['visible_parent', null, 'menu.denied'],
            'trapped_grandchild' => ['denied_nested_parent', null, null],
            'role_denied_parent' => [null, $this->otherRole, 'menu.allowed'],
            'trapped_role_child' => ['role_denied_parent', $this->readerRole, 'menu.allowed'],
            'permission_denied_parent' => [null, $this->readerRole, 'menu.denied'],
            'trapped_permission_child' => ['permission_denied_parent', null, null],
            'last' => [null, null, null],
        ];
        $menus = [];
        foreach ($definitions as $key => [$parent, $role, $permission]) {
            $menus[$key] = $this->menu($key, [
                'parent_id' => $parent === null ? 0 : $menus[$parent]->id, 'permission' => $permission,
            ]);
            if ($role !== null) {
                $menus[$key]->roles()->attach($role);
            }
        }

        return $menus;
    }

    private function assertMenuPage(array $menus, array $expectedKeys): void
    {
        $response = $this->get('/admin/menu-search-visibility')->assertOk();
        $this->assertAuthenticatedAs($this->reader, 'admin');
        $page = new Crawler($response->getContent());
        $expected = array_map(fn ($key) => [
            'title' => $menus[$key]->title,
            'href' => admin_url($menus[$key]->uri),
            'icon' => 'fa '.$menus[$key]->icon,
        ], $expectedKeys);
        $links = fn (Crawler $anchor) => [
            'title' => trim($anchor->text()), 'href' => $anchor->attr('href'),
            'icon' => $anchor->filter('i')->attr('class'),
        ];

        $this->assertSame($expected, $page->filter('.sidebar-menu li:not(.treeview) > a')->each($links),
            'The shipped sidebar must show exactly the expected leaves.');
        $this->assertSame($expected, $page->filter('.sidebar-form .dropdown-menu > li > a')->each($links),
            'Menu search must contain the same visible leaf links in the same order.');
        $this->assertEquals(array_map(fn ($key) => [
            'title' => $menus[$key]->title, 'uri' => $menus[$key]->uri, 'icon' => $menus[$key]->icon,
        ], $expectedKeys), Admin::menuLinks());
    }
}
