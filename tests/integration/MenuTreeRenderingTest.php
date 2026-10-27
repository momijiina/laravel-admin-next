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
        yield 'mixed percent escapes' => ['reports/%22%20%2522%2520%2F?keep=%26+%2B&raw=" literal'];
        yield 'percent escapes only' => ['reports/%22%20%2522%2520%2F?keep=%26+%2B', true];
    }

    #[DataProvider('uris')]
    public function test_saved_menu_values_remain_literal_on_full_and_pjax_pages(string $uri, bool $alreadyEncoded = false): void
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
            $expectedHref = $resolvedUri;
            if ($headers) {
                // Prove the PJAX middleware filtered the page rather than falling back.
                $response->assertHeader('X-PJAX-URL', '/admin/auth/menu');
                if (!class_exists(\Masterminds\HTML5::class)) {
                    // A safely constructed control captures this libxml's URI spelling.
                    // It does not interpolate or reuse the controller's HTML escaping.
                    $document = new \DOMDocument('1.0', 'UTF-8');
                    $control = $document->appendChild($document->createElement('a'));
                    $control->setAttribute('href', $resolvedUri);
                    $expectedHref = (new Crawler($document->saveHTML($control)))->filter('a')->attr('href');
                }
            } else {
                $response->assertHeaderMissing('X-PJAX-URL');
            }
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
            $this->assertSame($expectedHref, $link->attr('href'));
            if ($alreadyEncoded) {
                // Already encoded delimiters must also match without a serialization oracle.
                $this->assertSame($resolvedUri, $link->attr('href'));
            }
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

    public static function nullableAndCompatibleUris(): iterable
    {
        yield 'blank form value' => ['', null, 'http://localhost/admin', false];
        yield 'explicit null form value' => [null, null, 'http://localhost/admin', false];
        yield 'whitespace form value' => ['   ', null, 'http://localhost/admin', false];
        yield 'historical empty string' => ['', '', 'http://localhost/admin', false, true];
        yield 'root' => ['/', '/', 'http://localhost/admin', false];
        yield 'zero' => ['0', '0', 'http://localhost/admin/0', false];
        yield 'relative' => ['reports?filter=active&sort=asc', 'reports?filter=active&sort=asc',
            'http://localhost/admin/reports?filter=active&sort=asc', false];
        yield 'absolute' => ['https://example.test/reports?filter=active&sort=asc',
            'https://example.test/reports?filter=active&sort=asc',
            'https://example.test/reports?filter=active&sort=asc', true];
        yield 'protocol relative' => ['//example.test/reports', '//example.test/reports', '//example.test/reports', true];
        yield 'fragment' => ['#reports', '#reports', '#reports', true];
        yield 'mailto' => ['mailto:fixture@example.test', 'mailto:fixture@example.test', 'mailto:fixture@example.test', true];
    }

    #[DataProvider('nullableAndCompatibleUris')]
    public function test_nullable_menu_uris_keep_tree_sidebar_and_search_links_without_deprecations(
        ?string $submittedUri,
        ?string $storedUri,
        string $expectedHref,
        bool $opensNewWindow,
        bool $historical = false
    ): void
    {
        $this->assertWithoutDeprecations(function () use ($submittedUri, $storedUri, $expectedHref, $opensNewWindow, $historical) {
            $values = ['parent_id' => 0, 'title' => 'URI compatibility leaf', 'icon' => 'fa-bars', 'uri' => $submittedUri];
            if ($historical) {
                // Normal form middleware converts blanks to NULL; retain a legacy stored blank separately.
                $menu = Menu::create($values)->fresh();
            } else {
                $this->post('/admin/auth/menu', array_replace($values, ['parent_id' => '0']))
                    ->assertRedirect('/admin/auth/menu');
                $menu = Menu::orderByDesc('id')->firstOrFail();
            }
            $this->assertSame($storedUri, $menu->getRawOriginal('uri'));
            $stored = $menu->getAttributes();

            foreach ([[], ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#pjax-container']] as $headers) {
                $response = $this->get('/admin/auth/menu', $headers)->assertOk();
                $page = new Crawler($response->getContent());
                $link = $this->branch($page, $menu->id)->filter('a.dd-nodrag');
                $this->assertCount(1, $link);
                $this->assertSame($expectedHref, $link->attr('href'));
                $this->assertSame($expectedHref, $link->text(null, false));
                $this->assertFalse($link->getNode(0)->hasAttribute('target'));

                if ($headers) {
                    $response->assertHeader('X-PJAX-URL', '/admin/auth/menu');
                    $this->assertCount(0, $page->filter('.main-sidebar'));
                } else {
                    $response->assertHeaderMissing('X-PJAX-URL');
                    $sidebar = $page->filter('.sidebar-menu a')->reduce(
                        fn (Crawler $node) => $node->text() === $values['title']
                    );
                    $this->assertCount(1, $sidebar);
                    $this->assertSame($expectedHref, $sidebar->attr('href'));
                    $this->assertSame($opensNewWindow ? '_blank' : '', $sidebar->getNode(0)->getAttribute('target'));

                    $search = $page->filter('.sidebar-form .dropdown-menu a')->reduce(
                        fn (Crawler $node) => $node->text() === $values['title']
                    );
                    $this->assertCount(1, $search);
                    $this->assertSame($expectedHref, $search->attr('href'));
                    $this->assertFalse($search->getNode(0)->hasAttribute('target'));
                }
                $this->assertSame($stored, $menu->fresh()->getAttributes());
            }
        });
    }

    public function test_blank_uri_parent_keeps_its_group_and_child_without_deprecations(): void
    {
        $this->assertWithoutDeprecations(function () {
            $this->post('/admin/auth/menu', [
                'parent_id' => '0', 'title' => 'Blank URI parent', 'icon' => 'fa-folder', 'uri' => '',
            ])->assertRedirect('/admin/auth/menu');
            $parent = Menu::orderByDesc('id')->firstOrFail();
            $this->assertNull($parent->getRawOriginal('uri'));
            $child = $this->createThroughForm([
                'parent_id' => $parent->id, 'title' => 'Blank URI group child', 'icon' => 'fa-bars', 'uri' => 'auth/roles',
            ]);

            foreach ([[], ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#pjax-container']] as $headers) {
                $response = $this->get('/admin/auth/menu', $headers)->assertOk();
                $page = new Crawler($response->getContent());
                $this->assertCount(0, $this->branch($page, $parent->id)->filter('a.dd-nodrag'));
                $this->assertCount(1, $page->filter('.dd-item[data-id="'.$parent->id.'"] > ol.dd-list > .dd-item[data-id="'.$child->id.'"]'));
                $this->assertSame('http://localhost/admin/auth/roles',
                    $this->branch($page, $child->id)->filter('a.dd-nodrag')->attr('href'));
                if ($headers) {
                    $response->assertHeader('X-PJAX-URL', '/admin/auth/menu');
                } else {
                    $group = $page->filter('.sidebar-menu li.treeview > a')->reduce(
                        fn (Crawler $node) => $node->text() === $parent->title
                    );
                    $this->assertCount(1, $group);
                    $this->assertSame('#', $group->attr('href'));
                    $this->assertSame('http://localhost/admin/auth/roles',
                        $group->ancestors()->first()->filter('ul.treeview-menu > li > a')->attr('href'));
                }
                $this->assertNull($parent->fresh()->getRawOriginal('uri'));
            }
        });
    }

    public function test_nullable_url_helpers_preserve_root_zero_and_prefixes_without_deprecations(): void
    {
        $this->assertWithoutDeprecations(function () {
            foreach (['admin' => '/admin', '' => '', '/team/admin/' => '/team/admin'] as $prefix => $expectedPrefix) {
                config(['admin.route.prefix' => $prefix]);
                foreach ([[null, ''], ['', ''], ['/', ''], ['0', '/0'], [0, '/0'], ['reports', '/reports'], ['/reports/', '/reports']] as [$path, $suffix]) {
                    $expectedPath = $expectedPrefix.$suffix;
                    $this->assertSame($expectedPath ?: '/', admin_base_path($path));
                    $this->assertSame('http://localhost'.$expectedPath, admin_url($path));
                }
                $this->assertSame('https://example.test/reports', admin_url('https://example.test/reports'));
            }
        });
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

    private function assertWithoutDeprecations(callable $callback): void
    {
        $diagnostics = [];
        set_error_handler(function ($severity, $message, $file, $line) use (&$diagnostics) {
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                $diagnostics[] = $message.' at '.$file.':'.$line;

                return true;
            }

            return false;
        });
        try {
            $callback();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $diagnostics);
    }
}
