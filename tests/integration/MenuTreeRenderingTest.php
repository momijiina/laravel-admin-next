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
use Encore\Admin\MenuUrl;
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
        yield 'relative' => ['reports/" data-menu-uri="injected?x=<b>&amp;\'', 'http://localhost/admin/'];
        yield 'absolute' => ['https://example.test/" data-menu-uri="injected?x=<b>&amp;\''];
        yield 'protocol relative' => ['//example.test/path?x="&amp;<b>'];
        yield 'fragment' => ['#literal-&amp;-<b>-"'];
        yield 'mailto' => ['mailto:fixture@example.test?subject="&amp;<b>'];
        yield 'mixed percent escapes' => ['reports/%22%20%2522%2520%2F?keep=%26+%2B&raw=" literal', 'http://localhost/admin/'];
        yield 'percent escapes only' => ['reports/%22%20%2522%2520%2F?keep=%26+%2B', 'http://localhost/admin/', true];
    }

    #[DataProvider('uris')]
    public function test_saved_menu_values_remain_literal_on_full_and_pjax_pages(
        string $uri,
        string $expectedPrefix = '',
        bool $alreadyEncoded = false
    ): void
    {
        $values = [
            'parent_id' => 0,
            'title' => '菜单 <b>literal</b> &amp; &#34; "\'',
            'icon' => 'fa-bars\' data-menu-icon=\'injected "&amp;',
            'uri' => $uri,
        ];
        $menu = $this->createThroughForm($values);
        $stored = $menu->getAttributes();
        $resolvedUri = $expectedPrefix.$uri;

        foreach ([[], ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#pjax-container']] as $headers) {
            $response = $this->get('/admin/auth/menu', $headers)->assertOk();
            $expectedHref = $resolvedUri;
            if ($headers) {
                // Prove the PJAX middleware filtered the page rather than falling back.
                $response->assertHeader('X-PJAX-URL', '/admin/auth/menu');
                $expectedHref = $this->pjaxHref($resolvedUri);
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
        yield 'http' => ['http://example.test/reports', 'http://example.test/reports', 'http://example.test/reports', true];
        yield 'tel' => ['tel:+12025550123', 'tel:+12025550123', 'tel:+12025550123', true];
        yield 'sms' => ['sms:+12025550123?body=fixture', 'sms:+12025550123?body=fixture', 'sms:+12025550123?body=fixture', true];
        yield 'mixed case https' => ['HtTpS://example.test/reports', 'HtTpS://example.test/reports', 'HtTpS://example.test/reports', true];
        yield 'mixed case mailto' => ['MaIlTo:fixture@example.test', 'MaIlTo:fixture@example.test', 'MaIlTo:fixture@example.test', true];
        yield 'mixed case tel' => ['TeL:+12025550123', 'TeL:+12025550123', 'TeL:+12025550123', true];
        yield 'mixed case sms' => ['SmS:+12025550123', 'SmS:+12025550123', 'SmS:+12025550123', true];
        yield 'query only' => ['?view=all', '?view=all', 'http://localhost/admin?view=all', false];
        yield 'colon after path separator' => ['reports/javascript:literal', 'reports/javascript:literal',
            'http://localhost/admin/reports/javascript:literal', false];
        yield 'percent encoded colon' => ['javascript%3Afixture', 'javascript%3Afixture',
            'http://localhost/admin/javascript%3Afixture', false];
        yield 'entity looking colon' => ['javascript&#58;fixture', 'javascript&#58;fixture',
            'http://localhost/admin/javascript&#58;fixture', false];
        yield 'leading backslash' => ['\\example.test/reports', '\\example.test/reports',
            'http://localhost/admin/\\example.test/reports', false];
        yield 'slash then backslash' => ['/\\example.test/reports', '/\\example.test/reports',
            'http://localhost/admin/\\example.test/reports', false];
        yield 'https with backslash' => ['https:\\example.test/reports', 'https:\\example.test/reports',
            'https:\\example.test/reports', true];
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
                $this->assertSame($headers ? $this->pjaxHref($expectedHref) : $expectedHref, $link->attr('href'));
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
                    $this->assertFalse($sidebar->getNode(0)->hasAttribute('aria-disabled'));

                    $search = $page->filter('.sidebar-form .dropdown-menu a')->reduce(
                        fn (Crawler $node) => $node->text() === $values['title']
                    );
                    $this->assertCount(1, $search);
                    $this->assertSame($expectedHref, $search->attr('href'));
                    $this->assertFalse($search->getNode(0)->hasAttribute('target'));
                    $this->assertFalse($search->getNode(0)->hasAttribute('aria-disabled'));
                }
                $this->assertSame($stored, $menu->fresh()->getAttributes());
            }
        });
    }

    public static function rejectedMenuUris(): iterable
    {
        yield 'javascript with authority' => ['javascript://example.test/%0Aalert(1)'];
        yield 'plain javascript' => ['javascript:alert(1)'];
        yield 'mixed case javascript' => ['JaVaScRiPt://example.test/%0Aalert(1)'];
        yield 'data with literal markup' => ['data:text/html,<b data-menu-uri="injected">literal</b>&amp;'];
        yield 'data with authority' => ['data://example.test/payload'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'vbscript with authority' => ['vbscript://example.test/payload'];
        yield 'ftp' => ['ftp://example.test/reports'];
        yield 'file' => ['file:///tmp/menu-fixture'];
        yield 'unknown scheme with punctuation' => ['custom+menu.v1-item://example.test/reports'];
        yield 'tab within scheme' => ["java\tscript:fixture", false, 'java script:fixture'];
        yield 'line feed within scheme' => ["java\nscript:fixture", false, 'java script:fixture'];
        yield 'carriage return within scheme' => ["java\rscript:fixture", false, 'java script:fixture'];
        yield 'combined ignored whitespace' => ["ja\tva\r\nscript:fixture", false, 'ja va script:fixture'];
        yield 'historical leading whitespace' => [" \tJaVaScRiPt:fixture\r\n", true, 'JaVaScRiPt:fixture'];
    }

    #[DataProvider('rejectedMenuUris')]
    public function test_rejected_menu_uris_keep_literal_labels_without_navigation(
        string $uri,
        bool $historical = false,
        ?string $normalizedLabel = null
    ): void
    {
        $this->assertWithoutDeprecations(function () use ($uri, $historical, $normalizedLabel) {
            $values = [
                'parent_id' => 0,
                'title' => 'Rejected <b>literal</b> & "菜单"',
                'icon' => 'fa-bars" data-menu-icon="injected',
                'uri' => $uri,
            ];
            // Historical edge whitespace must reach rendering without form trimming.
            $menu = $historical ? Menu::create($values)->fresh() : $this->createThroughForm($values);
            $this->assertSame($uri, $menu->getRawOriginal('uri'));
            $stored = $menu->getAttributes();

            foreach ([[], ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#pjax-container']] as $headers) {
                $response = $this->get('/admin/auth/menu', $headers)->assertOk();
                $page = new Crawler($response->getContent());
                $branch = $this->branch($page, $menu->id);
                $title = $branch->filter('strong');
                $this->assertSame($values['title'], $title->text(null, false));
                $this->assertCount(0, $title->children());
                $this->assertSame('fa '.$values['icon'], $branch->filter('i')->first()->attr('class'));
                $this->assertCount(0, $branch->filter('[data-menu-icon], [data-menu-uri]'));
                $this->assertCount(0, $branch->filter('a.dd-nodrag'));
                $label = $branch->filter('span.dd-nodrag:not(.pull-right)');
                $this->assertCount(1, $label);
                // HTML parsers normalize CR/LF; ordinary URI labels remain byte exact.
                $this->assertSame($normalizedLabel ?? $uri, $label->text(null, $normalizedLabel !== null));
                $this->assertCount(0, $label->children());
                $this->assertFalse($label->getNode(0)->hasAttribute('href'));
                $this->assertSame('http://localhost/admin/auth/menu/'.$menu->id.'/edit',
                    $branch->filter('.pull-right a')->first()->attr('href'));
                $this->assertSame((string) $menu->id, $branch->filter('.tree_branch_delete')->attr('data-id'));

                if ($headers) {
                    $response->assertHeader('X-PJAX-URL', '/admin/auth/menu');
                    $this->assertCount(0, $page->filter('.main-sidebar'));
                } else {
                    $response->assertHeaderMissing('X-PJAX-URL');
                    foreach (['.sidebar-menu a', '.sidebar-form .dropdown-menu a'] as $selector) {
                        $entry = $page->filter($selector)->reduce(
                            fn (Crawler $node) => $node->text() === $values['title']
                        );
                        $this->assertCount(1, $entry, $selector);
                        $this->assertSame('true', $entry->attr('aria-disabled'));
                        $this->assertFalse($entry->getNode(0)->hasAttribute('href'));
                        $this->assertFalse($entry->getNode(0)->hasAttribute('target'));
                        $this->assertCount(0, $entry->filter('b, [data-menu-icon], [data-menu-uri]'));
                        $this->assertSame('fa '.$values['icon'], $entry->filter('i')->attr('class'));
                    }
                }
                $this->assertSame($stored, $menu->fresh()->getAttributes());
            }
        });
    }

    public static function menuUrlResolutions(): iterable
    {
        yield 'null' => [null, 'http://localhost/admin', null];
        yield 'empty' => ['', 'http://localhost/admin', null];
        yield 'integer zero' => [0, 'http://localhost/admin/0', null];
        yield 'string zero' => ['0', 'http://localhost/admin/0', null];
        yield 'root' => ['/', 'http://localhost/admin', null];
        yield 'allowed scheme control spelling' => [" \x01Ht\tTp\nS\r://example.test/path\x02 ",
            " \x01Ht\tTp\nS\r://example.test/path\x02 ", '_blank'];
        yield 'protocol relative control spelling' => ["\x01/\t/example.test/path\r\n",
            "\x01/\t/example.test/path\r\n", '_blank'];
        yield 'fragment control spelling' => ["\x02\t#javascript:fixture\r\n", "\x02\t#javascript:fixture\r\n", '_blank'];
        yield 'scheme punctuation' => ['custom+menu.v1-item:fixture', null, null];
        yield 'unknown scheme after trimming' => [" \x01CUSTOM:fixture\r\n", null, null];
        yield 'colon after relative slash' => ['./javascript:fixture', 'http://localhost/admin/./javascript:fixture', null];
        yield 'colon in query' => ['?next=javascript:fixture', 'http://localhost/admin?next=javascript:fixture', null];
        yield 'percent encoded scheme letter' => ['%6aavascript:fixture', 'http://localhost/admin/%6aavascript:fixture', null];
        yield 'double encoded colon' => ['javascript%253Afixture', 'http://localhost/admin/javascript%253Afixture', null];
        yield 'entity looking scheme letter' => ['&#106;avascript:fixture', 'http://localhost/admin/&#106;avascript:fixture', null];
        yield 'space within apparent scheme' => ['java script:fixture', 'http://localhost/admin/java script:fixture', null];
        yield 'internal vertical tab is a path' => ["java\vscript:fixture", "http://localhost/admin/java\vscript:fixture", null];
        yield 'leading DEL is a path' => ["\x7fjavascript:fixture", "http://localhost/admin/\x7fjavascript:fixture", null];
        yield 'leading nonbreaking space is a path' => ["\u{00a0}javascript:fixture", "http://localhost/admin/\u{00a0}javascript:fixture", null];
    }

    #[DataProvider('menuUrlResolutions')]
    public function test_menu_url_resolver_classifies_without_rewriting_literal_values(
        string|int|null $uri,
        ?string $expectedHref,
        ?string $expectedTarget
    ): void
    {
        $this->assertWithoutDeprecations(function () use ($uri, $expectedHref, $expectedTarget) {
            $this->assertSame(['href' => $expectedHref, 'target' => $expectedTarget], MenuUrl::resolve($uri));
        });
    }

    public function test_menu_url_resolver_rejects_all_browser_ignored_leading_ascii_controls(): void
    {
        $this->assertWithoutDeprecations(function () {
            for ($code = 0; $code <= 0x20; $code++) {
                $uri = chr($code).'JaVaScRiPt:fixture'.chr($code);
                $this->assertSame(['href' => null, 'target' => null], MenuUrl::resolve($uri), sprintf('ASCII 0x%02X', $code));
            }
            foreach (["java\tscript", "java\nscript", "java\rscript", "ja\tva\nsc\rript"] as $scheme) {
                $this->assertSame(['href' => null, 'target' => null], MenuUrl::resolve($scheme.':fixture'));
            }
        });
    }

    public function test_menu_url_resolver_uses_configured_admin_prefix_and_secure_urls(): void
    {
        $this->assertWithoutDeprecations(function () {
            config(['admin.https' => true]);
            foreach (['' => '', '/team/admin/' => '/team/admin'] as $prefix => $expectedPrefix) {
                config(['admin.route.prefix' => $prefix]);
                foreach ([[null, ''], ['', ''], ['0', '/0'], ['reports', '/reports']] as [$uri, $suffix]) {
                    $this->assertSame(['href' => 'https://localhost'.$expectedPrefix.$suffix, 'target' => null],
                        MenuUrl::resolve($uri));
                }
                $this->assertSame(['href' => 'http://example.test/reports', 'target' => '_blank'],
                    MenuUrl::resolve('http://example.test/reports'));
            }
            // This policy belongs to menu links; general package URL helpers retain their contract.
            $this->assertSame('custom+tool://example.test/path', admin_url('custom+tool://example.test/path'));
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

    private function pjaxHref(string $uri): string
    {
        if (class_exists(\Masterminds\HTML5::class)) {
            return $uri;
        }

        // A safely constructed control captures this libxml's URI spelling.
        // It does not interpolate or reuse the controller's HTML escaping.
        $document = new \DOMDocument('1.0', 'UTF-8');
        $control = $document->appendChild($document->createElement('a'));
        $control->setAttribute('href', $uri);

        return (new Crawler($document->saveHTML($control)))->filter('a')->attr('href');
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
