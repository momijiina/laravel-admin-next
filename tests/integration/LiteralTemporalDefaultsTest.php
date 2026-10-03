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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class LiteralTemporalDefaultsTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        Schema::create('literal_temporal_records', function ($table) {
            $table->increments('id');
            $table->string('name')->default('Ordinary string default');
            foreach ([false, true] as $nullable) {
                $prefix = $nullable ? 'optional_' : 'required_';
                $table->date($prefix.'day')->nullable($nullable)->default('2000-02-29');
                $table->dateTime($prefix.'instant')->nullable($nullable)->default('2001-02-03 04:05:06');
                $table->time($prefix.'clock')->nullable($nullable)->default('00:00:00');
            }
            $table->date('null_day')->nullable()->default(null);
            $table->dateTime('current_instant')->nullable()->useCurrent();
        });
        $this->generatedDirectory = sys_get_temp_dir().'/admin-literal-temporal-'.bin2hex(random_bytes(8));
        mkdir($this->generatedDirectory, 0700, true);
        $this->app->getNamespace();
        $this->app->useAppPath($this->generatedDirectory);
        config(['admin.directory' => $this->generatedDirectory.'/Admin']);
        $this->app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
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

    public static function generatedModels(): array
    {
        return [
            [LiteralTemporalRecord::class, 'admin:make'],
            [ImmutableLiteralTemporalRecord::class, 'admin:make'],
            [LiteralTemporalRecord::class, 'admin:controller'],
            [ImmutableLiteralTemporalRecord::class, 'admin:controller'],
        ];
    }

    #[DataProvider('generatedModels')]
    public function test_generated_create_persists_literal_defaults($model, $command): void
    {
        $url = $this->generateResource($model, $command);
        $inputs = $this->inputs($this->get($url.'/create')->assertOk());
        // LITERAL_RENDER_START
        foreach (self::literalDefaults() as $column => $default) {
            $this->assertSame($default, $inputs[$column], $column);
        }
        // LITERAL_RENDER_END
        $this->assertSame('Ordinary string default', $inputs['name']);
        $this->assertSame('', $inputs['null_day']);
        $this->assertNotEmpty($inputs['current_instant']);
        $this->post($url, $inputs)->assertRedirect($url);
        $record = $model::firstOrFail();
        $expected = $actual = [];
        foreach (self::literalDefaults() as $column => $default) {
            // Eloquent's native date casts serialize to its default datetime storage format.
            $expected[$column] = substr($column, -3) === 'day' ? $default.' 00:00:00' : $default;
            $actual[$column] = $record->getRawOriginal($column);
        }
        $this->assertSame($expected, $actual);
        $this->assertNull($record->getRawOriginal('null_day'));
        $this->assertSame('Ordinary string default', $record->name);
    }

    #[DataProvider('generatedModels')]
    public function test_generated_unchanged_edit_preserves_distinct_stored_values($model, $command): void
    {
        $url = $this->generateResource($model, $command);
        $values = ['name' => 'Existing row', 'null_day' => null, 'current_instant' => '1999-12-31 23:59:59'];
        foreach (['required_', 'optional_'] as $prefix) {
            $values[$prefix.'day'] = '1999-12-31';
            $values[$prefix.'instant'] = '1999-12-31 23:59:59';
            $values[$prefix.'clock'] = '23:59:59';
        }
        $record = $model::create($values)->fresh();
        $inputs = $this->inputs($this->get($url.'/'.$record->id.'/edit')->assertOk());
        foreach ($values as $column => $value) {
            $this->assertSame($value ?? '', $inputs[$column], $column);
        }
        $before = $record->getAttributes();
        $this->put($url.'/'.$record->id, $inputs)->assertRedirect($url);
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public function test_literal_defaults_keep_field_null_fallback_and_can_be_cleared(): void
    {
        $url = $this->generateResource(LiteralTemporalRecord::class, 'admin:make');
        $values = self::literalDefaults() + ['name' => 'Optional literals cleared', 'null_day' => null];
        foreach (['optional_day', 'optional_instant', 'optional_clock'] as $column) {
            $values[$column] = null;
        }
        $record = LiteralTemporalRecord::create($values)->fresh();
        $inputs = $this->inputs($this->get($url.'/'.$record->id.'/edit')->assertOk());
        foreach (['optional_day', 'optional_instant', 'optional_clock'] as $column) {
            $this->assertSame(self::literalDefaults()[$column], $inputs[$column]);
            $inputs[$column] = '';
        }
        $this->put($url.'/'.$record->id, $inputs)->assertRedirect($url);
        foreach (['optional_day', 'optional_instant', 'optional_clock'] as $column) {
            $this->assertNull($record->fresh()->getRawOriginal($column));
        }
    }

    private static function literalDefaults(): array
    {
        $defaults = [];
        foreach (['required_', 'optional_'] as $prefix) {
            $defaults[$prefix.'day'] = '2000-02-29';
            $defaults[$prefix.'instant'] = '2001-02-03 04:05:06';
            $defaults[$prefix.'clock'] = '00:00:00';
        }

        return $defaults;
    }

    private function generateResource($model, $command): string
    {
        $namespace = 'App\\LiteralTemporal'.bin2hex(random_bytes(8));
        config(['admin.route.namespace' => $namespace]);
        $controller = $command === 'admin:make' ? 'TemporalController' : class_basename($model).'Controller';
        $arguments = $command === 'admin:make' ? ['name' => $controller, '--model' => $model] : ['model' => $model];
        $this->assertSame(0, Artisan::call($command, $arguments), Artisan::output());
        $path = app_path(str_replace('\\', '/', substr($namespace, 4)).'/'.$controller.'.php');
        $source = file_get_contents($path);
        token_get_all($source, TOKEN_PARSE);
        // LITERAL_SOURCE_START
        foreach (self::literalDefaults() as $column => $default) {
            $type = substr($column, -3) === 'day' ? 'date' : (substr($column, -5) === 'clock' ? 'time' : 'datetime');
            $label = ucfirst(str_replace('_', ' ', $column));
            $this->assertStringContainsString('$form->'.$type."('{$column}', __('{$label}'))->default(".var_export($default, true).");", $source);
        }
        // LITERAL_SOURCE_END
        $this->assertStringContainsString("\$form->date('null_day', __('Null day'));", $source);
        $this->assertStringContainsString("\$form->datetime('current_instant', __('Current instant'))->default(date('Y-m-d H:i:s'));", $source);
        $this->assertStringContainsString("\$form->text('name', __('Name'))->default('Ordinary string default');", $source);
        require $path;
        $resource = 'temporal-'.bin2hex(random_bytes(8));
        $this->app['router']->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) use ($resource, $namespace, $controller) {
            $router->resource($resource, $namespace.'\\'.$controller);
        });
        $this->actingAs(Administrator::firstOrFail(), 'admin');

        return '/admin/'.$resource;
    }

    private function inputs($response): array
    {
        $crawler = new Crawler($response->getContent());
        $inputs = [];
        foreach (array_merge(['name', 'null_day', 'current_instant'], array_keys(self::literalDefaults())) as $column) {
            $inputs[$column] = $crawler->filter('input[name="'.$column.'"]')->attr('value');
        }

        return $inputs;
    }
}

class LiteralTemporalRecord extends Model
{
    protected $table = 'literal_temporal_records';
    protected $guarded = [];
    public $timestamps = false;
    // Laravel has no native time-only cast; that column uses its ordinary string value.
    protected $casts = ['required_day' => 'date', 'optional_day' => 'date', 'required_instant' => 'datetime', 'optional_instant' => 'datetime'];
}

class ImmutableLiteralTemporalRecord extends LiteralTemporalRecord
{
    protected $casts = ['required_day' => 'immutable_date', 'optional_day' => 'immutable_date', 'required_instant' => 'immutable_datetime', 'optional_instant' => 'immutable_datetime'];
}
