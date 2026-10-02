<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

class AdminLifecycleTest extends TestCase
{
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
        // Published config exists before service provider registration in a consumer.
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
        Admin::routes();
        $router->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) {
            $router->get('/', function () {
                return response()->json(['username' => Admin::user()->username]);
            });
            $router->post('integration/input', function (Request $request) {
                return response()->json($request->all());
            });
            $router->post('integration/invalid', function (Request $request) {
                $request->validate(['name' => 'required']);
                return response()->json(['accepted' => true]);
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
    }

    public function test_provider_configures_real_admin_guard_and_middleware(): void
    {
        $this->assertSame('session', config('auth.guards.admin.driver'));
        $this->assertSame(Administrator::class, config('auth.providers.admin.model'));
        $this->assertSame(\Encore\Admin\Middleware\LogOperation::class, $this->app['router']->getMiddleware()['admin.log']);
        $this->assertContains('admin.log', $this->app['router']->getMiddlewareGroups()['admin']);
        $this->assertDatabaseHas('admin_users', ['username' => 'admin']);
    }

    public function test_guest_login_page_and_protected_route(): void
    {
        $this->get('/admin/auth/login')->assertOk()->assertSee('Laravel-admin');
        $this->get('/admin')->assertRedirect('/admin/auth/login');
        $this->assertGuest('admin');
        $this->assertDatabaseCount('admin_operation_log', 0);
    }

    public function test_login_authenticated_request_and_logout_lifecycle(): void
    {
        $this->post('/admin/auth/login', ['username' => 'admin', 'password' => 'admin'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
        $this->get('/admin')->assertOk()->assertJson(['username' => 'admin']);
        $this->assertGreaterThan(0, config('integration.bootstrap_calls'));
        $this->get('/admin/auth/logout')->assertRedirect('/admin');
        $this->assertGuest('admin');
        $this->get('/admin')->assertRedirect('/admin/auth/login');
    }

    public function test_invalid_credentials_do_not_authenticate_or_log_password(): void
    {
        $this->from('/admin/auth/login')->post('/admin/auth/login', [
            'username' => 'admin', 'password' => 'incorrect-test-password',
        ])->assertRedirect('/admin/auth/login')->assertSessionHasErrors('username');
        $this->assertGuest('admin');
        $this->assertDatabaseCount('admin_operation_log', 0);
    }

    public function test_authenticated_log_is_redacted_without_mutating_controller_input(): void
    {
        $this->actingAs(Administrator::first(), 'admin');
        $input = ['name' => 'Visible', 'password' => 'test-secret', 'nested' => [
            'ACCESS_TOKEN' => 'nested-test-token', 'note' => 'Kept',
        ]];
        $this->postJson('/admin/integration/input', $input)->assertOk()->assertExactJson($input);
        $this->assertDatabaseCount('admin_operation_log', 1);
        $log = OperationLog::firstOrFail();
        $this->assertSame((int) Administrator::first()->id, (int) $log->user_id);
        $this->assertSame('POST', $log->method);
        $this->assertSame('admin/integration/input', $log->path);
        $this->assertSame([
            'name' => 'Visible', 'password' => '[REDACTED]',
            'nested' => ['ACCESS_TOKEN' => '[REDACTED]', 'note' => 'Kept'],
        ], json_decode($log->input, true));
    }

    public function test_validation_failure_is_logged_with_redaction(): void
    {
        $this->actingAs(Administrator::first(), 'admin');
        $this->postJson('/admin/integration/invalid', ['password' => 'invalid-test-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('admin_operation_log', 1);
        $this->assertSame(['password' => '[REDACTED]'], json_decode(OperationLog::firstOrFail()->input, true));
    }

    public function test_custom_redaction_fields_extend_defaults(): void
    {
        config(['admin.operation_log.redact_fields' => ['private_note']]);
        $this->actingAs(Administrator::first(), 'admin');
        $this->postJson('/admin/integration/input', ['password' => 'secret', 'private_note' => 'secret', 'name' => 'Kept'])->assertOk();
        $this->assertSame(['password' => '[REDACTED]', 'private_note' => '[REDACTED]', 'name' => 'Kept'], json_decode(OperationLog::firstOrFail()->input, true));
    }

    public function test_disabled_logging_and_excluded_routes_do_not_persist_input(): void
    {
        $this->actingAs(Administrator::first(), 'admin');
        config(['admin.operation_log.enable' => false]);
        $this->postJson('/admin/integration/input', ['name' => 'disabled'])->assertOk();
        config(['admin.operation_log.enable' => true, 'admin.operation_log.except' => ['POST:admin/integration/input']]);
        $this->postJson('/admin/integration/input', ['name' => 'excluded'])->assertOk();
        $this->assertDatabaseCount('admin_operation_log', 0);
    }

    public function test_real_artisan_blank_controller(): void
    {
        $path = sys_get_temp_dir().'/admin-generator-'.bin2hex(random_bytes(5));
        mkdir($path, 0700, true);
        // Resolve the real skeleton namespace before relocating only its app directory.
        $this->app->getNamespace();
        $this->app->useAppPath($path);
        config(['admin.directory' => $path.'/Admin', 'admin.route.namespace' => 'App\\Admin\\Controllers']);
        try {
            $code = Artisan::call('admin:make', ['name' => 'BlankController']);
            $this->assertSame(0, $code, Artisan::output());
            $file = $path.'/Admin/Controllers/BlankController.php';
            $this->assertFileExists($file);
            $content = file_get_contents($file);
            token_get_all($content, TOKEN_PARSE);
            $this->assertStringContainsString('namespace App\\Admin\\Controllers;', $content);
            $this->assertStringNotContainsString('Dummy', $content);
            $this->assertStringNotContainsString('use ;', $content);
        } finally {
            if (file_exists($path.'/Admin/Controllers/BlankController.php')) {
                unlink($path.'/Admin/Controllers/BlankController.php');
            }
            if (is_dir($path.'/Admin/Controllers')) {
                rmdir($path.'/Admin/Controllers');
            }
            if (is_dir($path.'/Admin')) {
                rmdir($path.'/Admin');
            }
            rmdir($path);
        }
    }

    public function test_real_artisan_output_requires_model(): void
    {
        $code = Artisan::call('admin:make', ['name' => 'OutputController', '--output' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('model', Artisan::output());
    }
}
