<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin as AdminFacade;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class OperationLogRenderingTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
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
        $router->post('/admin/integration/log-input/{tail?}', function (Request $request) {
            return response()->json($request->all());
        })->where('tail', '.*')->middleware(['web', 'admin']);
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs', 'baseCss', 'minifyIgnores'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
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

    public function test_http_logged_input_remains_redacted_and_literal_in_rendered_json(): void
    {
        config(['admin.operation_log.redact_fields' => ['private_note']]);
        $user = Administrator::firstOrFail();
        $user->update(['name' => 'Administrator &amp; <b>literal</b>']);
        $this->actingAs($user, 'admin');
        $input = [
            'note' => 'ACME &amp; &quot; &#34; &#10; <b>literal</b> 注文 café',
            'key &quot; <i>' => "First\nSecond\r\nThird\\end",
            'password' => 'new-synthetic-password',
            'nested' => [
                'ACCESS_TOKEN' => 'new-synthetic-token',
                'private_note' => ['secret' => 'new-synthetic-private-note'],
                'values' => [0, '0', false, null, '', ['label' => '&lt;b&gt; &amp;']],
            ],
            '_token' => 'new-synthetic-csrf',
            '_pjax' => '#pjax-container',
            '_method' => 'POST',
            '_previous_' => '/admin/previous',
        ];
        // Laravel normalizes empty strings before the admin logging middleware.
        $normalized = $input;
        $normalized['nested']['values'][4] = null;
        $this->postJson('/admin/integration/log-input', $input)->assertOk()->assertExactJson($normalized);
        $this->assertDatabaseCount('admin_operation_log', 1);
        $log = OperationLog::firstOrFail();
        $stored = $normalized;
        $stored['password'] = $stored['nested']['ACCESS_TOKEN'] = $stored['nested']['private_note'] = $stored['_token'] = '[REDACTED]';
        $this->assertSame($stored, json_decode($log->input, true, 512, JSON_THROW_ON_ERROR));

        $row = $this->renderRow($log);
        $pre = $row->filter('pre');
        $this->assertCount(1, $pre);
        $visible = $stored;
        unset($visible['_token'], $visible['_pjax'], $visible['_method'], $visible['_previous_']);
        $text = $pre->text(null, false);
        $this->assertSame($visible, json_decode($text, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(json_encode($visible, JSON_PRETTY_PRINT | JSON_HEX_TAG), $text);
        $this->assertCount(0, $pre->children());
        $this->assertSame($user->name, trim($row->filter('td')->eq(2)->text(null, false)));
        $this->assertCount(0, $row->filter('td')->eq(2)->children());
        foreach (['new-synthetic-password', 'new-synthetic-token', 'new-synthetic-private-note', 'new-synthetic-csrf'] as $secret) {
            $this->assertStringNotContainsString($secret, $row->html());
        }
    }

    public function test_real_http_path_is_literal_inside_its_existing_label(): void
    {
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        $uri = '/admin/integration/log-input/path&copy;<b>literal</b>';
        $this->postJson($uri, [])->assertOk()->assertExactJson([]);
        $log = OperationLog::firstOrFail();
        $this->assertSame(ltrim($uri, '/'), $log->path);
        $row = $this->renderRow($log);
        $this->assertLiteralElement($row, '.label-info', $log->path, 'label label-info');
        $this->assertLiteralElement($row, '.label-primary', '127.0.0.1', 'label label-primary');
        $this->assertLiteralElement($row, '.badge', 'POST', 'badge bg-yellow');
        $this->assertSame('{}', $row->filter('code')->text());
    }

    public static function methods(): iterable
    {
        yield 'GET' => ['GET', 'green'];
        yield 'POST' => ['POST', 'yellow'];
        yield 'PUT' => ['PUT', 'blue'];
        yield 'DELETE' => ['DELETE', 'red'];
        yield 'unmapped method' => ['PATCH', 'grey'];
        yield 'imported markup' => ['<i>M</i>', 'grey'];
        yield 'imported entity' => ['&copy;', 'grey'];
    }

    #[DataProvider('methods')]
    public function test_imported_metadata_is_literal_and_keeps_method_colors(string $method, string $color): void
    {
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        $log = $this->createLog('{}', [
            'method' => $method,
            'path' => 'admin/imported/<b>Path</b>&amp;',
            'ip' => '<em>IP</em> &quot; &amp;',
        ]);
        $row = $this->renderRow($log);
        $this->assertLiteralElement($row, '.badge', $method, 'badge bg-'.$color);
        $this->assertLiteralElement($row, '.label-info', $log->path, 'label label-info');
        $this->assertLiteralElement($row, '.label-primary', $log->ip, 'label label-primary');
    }

    public static function historicalInputs(): iterable
    {
        yield 'malformed JSON' => ['{broken &quot; <b>literal</b>', '{broken &quot; <b>literal</b>', 'pre'];
        yield 'empty storage' => ['', '', 'pre'];
        yield 'JSON null' => ['null', 'null', 'pre'];
        yield 'JSON false' => ['false', 'false', 'pre'];
        yield 'JSON true' => ['true', 'true', 'pre'];
        yield 'JSON zero' => ['0', '0', 'pre'];
        yield 'JSON empty string' => ['""', '""', 'pre'];
        yield 'JSON string' => ['"&quot; <b>literal</b>"', '"&quot; \\u003Cb\\u003Eliteral\\u003C\\/b\\u003E"', 'pre'];
        yield 'JSON array' => ['["&quot;",0,false,null]', "[\n    \"&quot;\",\n    0,\n    false,\n    null\n]", 'pre'];
        yield 'empty array' => ['[]', '{}', 'code'];
        yield 'empty object' => ['{}', '{}', 'code'];
        yield 'only hidden metadata' => ['{"_token":"historical-csrf","_pjax":"#pjax-container","_method":"POST","_previous_":"/admin"}', '{}', 'code'];
    }

    #[DataProvider('historicalInputs')]
    public function test_historical_input_shapes_render_without_crashing_or_creating_markup(string $input, string $expected, string $tag): void
    {
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        $log = $this->createLog($input);
        $row = $this->renderRow($log);
        $element = $row->filter('pre, code');
        $this->assertCount(1, $element);
        $this->assertSame($tag, $element->nodeName());
        $this->assertSame($expected, $element->text(null, false));
        $this->assertCount(0, $element->children());
    }

    public function test_display_escaping_does_not_rewrite_or_redact_historical_secrets(): void
    {
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        $input = ['password' => 'historical-synthetic-password', 'note' => '&quot; &amp;'];
        $log = $this->createLog(json_encode($input, JSON_THROW_ON_ERROR));
        $row = $this->renderRow($log);
        $this->assertSame($input, json_decode($row->filter('pre')->text(null, false), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('historical-synthetic-password', $row->text());
        $this->assertStringNotContainsString('[REDACTED]', $row->text());
    }

    public function test_log_page_keeps_guest_authentication_protection(): void
    {
        $this->get('/admin/auth/logs')->assertRedirect('/admin/auth/login');
        $this->assertDatabaseCount('admin_operation_log', 0);
    }

    private function createLog(string $input, array $metadata = []): OperationLog
    {
        return OperationLog::create($metadata + [
            'user_id' => Administrator::firstOrFail()->id,
            'method' => 'POST', 'path' => 'admin/imported', 'ip' => '127.0.0.1', 'input' => $input,
        ]);
    }

    private function renderRow(OperationLog $log): Crawler
    {
        $stored = $log->fresh()->getAttributes();
        $count = OperationLog::count();
        $response = $this->get('/admin/auth/logs')->assertOk();
        $this->assertSame($stored, $log->fresh()->getAttributes());
        $this->assertSame($count, OperationLog::count());
        $crawler = new Crawler($response->getContent());
        $this->assertCount(1, $crawler->filter('table.grid-table'));
        $row = $crawler->filter('table.grid-table > tbody > tr[data-key="'.$log->id.'"]');
        $this->assertCount(1, $row);

        return $row;
    }

    private function assertLiteralElement(Crawler $row, string $selector, string $text, string $class): void
    {
        $element = $row->filter($selector);
        $this->assertCount(1, $element);
        $this->assertSame($text, $element->text(null, false));
        $this->assertSame($class, $element->attr('class'));
        $this->assertCount(0, $element->children());
    }
}
