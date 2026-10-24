<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin as AdminFacade;
use Encore\Admin\Form;
use Encore\Admin\Tree;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class MenuTreeRenderingTest extends TestCase
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
        AdminFacade::routes();
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs', 'baseCss', 'minifyIgnores'],
            Form::class => ['collectedAssets', 'snakeAttributes'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            foreach (['script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs'] as $name) {
                (new \ReflectionProperty(Admin::class, $name))->setValue(null, []);
            }
            require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
            (new \CreateAdminTables())->up();
            $this->seed(AdminTablesSeeder::class);
            $this->actingAs(Administrator::firstOrFail(), 'admin');
        } catch (\Throwable $exception) {
            $this->restorePackageState();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
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

    public static function uris(): iterable
    {
        yield 'relative' => ['reports/" data-menu-uri="injected?x=<b>&amp;\''];
        yield 'absolute' => ['https://example.test/" data-menu-uri="injected?x=<b>&amp;\''];
        yield 'protocol relative' => ['//example.test/path?x="&amp;<b>'];
        yield 'fragment' => ['#literal-&amp;-<b>-"'];
        yield 'mailto' => ['mailto:fixture@example.test?subject="&amp;<b>'];
    }

    #[DataProvider('uris')]
    public function test_saved_menu_values_remain_literal_on_full_and_pjax_pages(string $uri): void
    {
        $values = [
            'parent_id' => 0,
            'title' => '菜单 <b>literal</b> &amp; &#34; "\'',
            'icon' => 'fa-bars\' data-menu-icon=\'injected "&amp;',
            'uri' => $uri,
        ];
        $menu = $this->createThroughForm($values);
        $stored = $menu->getAttributes();
        $resolvedUri = url()->isValidUrl($uri) ? $uri : admin_url($uri);

        foreach ([[], ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#pjax-container']] as $headers) {
            $response = $this->get('/admin/auth/menu', $headers)->assertOk();
            $tree = new Crawler($response->getContent());
            $branch = $this->branch($tree, $menu->id);
            $title = $branch->filter('strong');
            $this->assertSame($values['title'], $title->text(null, false));
            $this->assertCount(0, $title->children());
            $icon = $branch->filter('i')->first();
            $this->assertSame('fa '.$values['icon'], $icon->attr('class'));
            $this->assertFalse($icon->getNode(0)->hasAttribute('data-menu-icon'));
            $link = $branch->filter('a.dd-nodrag');
            $this->assertCount(1, $link);
            $this->assertSame($resolvedUri, $link->attr('href'));
            $this->assertSame($resolvedUri, $link->text(null, false));
            $this->assertCount(0, $link->children());
            $this->assertFalse($link->getNode(0)->hasAttribute('data-menu-uri'));
            $this->assertSame($stored, $menu->fresh()->getAttributes());
            $this->assertSame(admin_url('auth/menu/'.$menu->id.'/edit'), $branch->filter('span a')->first()->attr('href'));
            $this->assertSame((string) $menu->id, $branch->filter('.tree_branch_delete')->attr('data-id'));
        }
    }

    public function test_parent_branches_keep_icons_and_children_without_a_uri_link(): void
    {
        $parent = $this->createThroughForm([
            'parent_id' => 0, 'title' => 'Parent &amp; <i>literal</i>', 'icon' => 'fa-folder', 'uri' => 'unused-parent-uri',
        ]);
        $child = $this->createThroughForm([
            'parent_id' => $parent->id, 'title' => 'Child', 'icon' => 'fa-bars', 'uri' => 'auth/roles',
        ]);
        $response = $this->get('/admin/auth/menu')->assertOk();
        $tree = new Crawler($response->getContent());
        $parentBranch = $this->branch($tree, $parent->id);
        $this->assertSame($parent->title, $parentBranch->filter('strong')->text(null, false));
        $this->assertCount(0, $parentBranch->filter('strong')->children());
        $this->assertSame('fa fa-folder', $parentBranch->filter('i')->first()->attr('class'));
        $this->assertCount(0, $parentBranch->filter('a.dd-nodrag'));
        $this->assertCount(1, $tree->filter('.dd-item[data-id="'.$parent->id.'"] > ol.dd-list > .dd-item[data-id="'.$child->id.'"]'));
        $childBranch = $this->branch($tree, $child->id);
        $this->assertSame('fa fa-bars', $childBranch->filter('i')->first()->attr('class'));
        $this->assertSame(admin_url('auth/roles'), $childBranch->filter('a.dd-nodrag')->attr('href'));
    }

    public function test_custom_tree_branch_callbacks_still_render_their_own_html(): void
    {
        $tree = new Tree(new Menu());
        $tree->branch(fn ($branch) => '<em class="custom-branch">'.e($branch['title']).'</em>');
        $rendered = new Crawler($tree->render());
        $this->assertSame(Menu::count(), $rendered->filter('.dd-handle > em.custom-branch')->count());
        $this->assertSame(Menu::findOrFail(1)->title,
            $rendered->filter('.dd-item[data-id="1"] > .dd-handle > em.custom-branch')->text());
    }

    private function createThroughForm(array $values): Menu
    {
        $this->post('/admin/auth/menu', array_replace($values, ['parent_id' => (string) $values['parent_id']]))
            ->assertRedirect('/admin/auth/menu');
        $menu = Menu::orderByDesc('id')->firstOrFail();
        foreach ($values as $key => $value) {
            $this->assertSame($value, $menu->$key);
        }

        return $menu;
    }

    private function branch(Crawler $tree, int $id): Crawler
    {
        $branch = $tree->filter('.dd-item[data-id="'.$id.'"] > .dd-handle');
        $this->assertCount(1, $branch);

        return $branch;
    }
}
