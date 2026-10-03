<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class GeneratedControllerCrudTest extends TestCase
{
    private $generatedDirectory;

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        Schema::create('generated_crud_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('note')->nullable();
            $table->integer('quantity');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
        $this->generatedDirectory = sys_get_temp_dir().'/admin-generated-crud-'.bin2hex(random_bytes(8));
        mkdir($this->generatedDirectory, 0700, true);
        $this->app->getNamespace();
        $this->app->useAppPath($this->generatedDirectory);
        config(['admin.directory' => $this->generatedDirectory.'/Admin']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->generatedDirectory) {
                (new Filesystem())->deleteDirectory($this->generatedDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_generated_model_controllers_execute_the_real_http_crud_lifecycle(): void
    {
        foreach ([
            ['admin:make', ['name' => 'MadeCrudController', '--model' => GeneratedCrudRecord::class], 'MadeCrudController', 'made-records'],
            ['admin:controller', ['model' => GeneratedCrudRecord::class], 'GeneratedCrudRecordController', 'model-records'],
        ] as [$command, $arguments, $controller, $resource]) {
            // Fresh namespaces keep repeated executions in one PHP process independent.
            $namespace = 'App\\GeneratedCrud'.bin2hex(random_bytes(8));
            config(['admin.route.namespace' => $namespace]);
            $this->assertSame(0, Artisan::call($command, $arguments), Artisan::output());
            require $this->generatedDirectory.'/'.str_replace('\\', '/', substr($namespace, 4)).'/'.$controller.'.php';
            $this->app['router']->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) use ($resource, $namespace, $controller) {
                $router->resource($resource, $namespace.'\\'.$controller);
            });
            $url = '/admin/'.$resource;
            $this->get($url)->assertRedirect('/admin/auth/login');
            $this->post($url, ['name' => 'Guest attempt'])->assertRedirect('/admin/auth/login');
            $this->assertDatabaseCount('generated_crud_records', 0);
            $this->post('/admin/auth/login', ['username' => 'admin', 'password' => 'admin'])->assertRedirect('/admin');
            $this->assertAuthenticatedAs(Administrator::first(), 'admin');
            $this->get($url)->assertOk();
            $this->get($url.'/create')->assertOk()
                ->assertSee('name="name"', false)->assertSee('name="note"', false)
                ->assertSee('name="quantity"', false)->assertSee('name="amount"', false);
            $attributes = ['name' => 'Generated <record>', 'note' => null, 'quantity' => '7', 'amount' => '12.34'];
            $this->post($url, $attributes)->assertRedirect($url);
            $this->assertDatabaseCount('generated_crud_records', 1);
            $record = GeneratedCrudRecord::firstOrFail();
            $this->assertSame($attributes['name'], $record->name);
            $this->assertNull($record->note);
            $this->assertSame(7, (int) $record->quantity);
            $this->assertEquals(12.34, $record->amount);
            $this->get($url)->assertOk()->assertSee('Generated &lt;record&gt;', false);
            $this->get($url.'/'.$record->id)->assertOk()->assertSee('Generated &lt;record&gt;', false);
            $this->get($url.'/'.$record->id.'/edit')->assertOk()->assertSee('Generated &lt;record&gt;', false);
            $updated = ['name' => 'Updated record', 'note' => 'Now populated', 'quantity' => '0', 'amount' => '0'];
            $this->put($url.'/'.$record->id, $updated)->assertRedirect($url);
            $record->refresh();
            $this->assertSame($updated['name'], $record->name);
            $this->assertSame($updated['note'], $record->note);
            $this->assertSame(0, (int) $record->quantity);
            $this->assertEquals(0, $record->amount);
            $this->get($url.'/'.$record->id)->assertOk()->assertSee('Updated record')->assertSee('Now populated');
            $this->put($url.'/'.$record->id, array_replace($updated, ['note' => '']))->assertRedirect($url);
            $this->assertNull($record->fresh()->note);
            $this->deleteJson($url.'/'.$record->id)->assertOk()->assertJson(['status' => true]);
            $this->assertDatabaseCount('generated_crud_records', 0);
            $this->get('/admin/auth/logout')->assertRedirect('/admin');
        }
    }
}

class GeneratedCrudRecord extends Model
{
    protected $table = 'generated_crud_records';
    protected $guarded = [];
}
