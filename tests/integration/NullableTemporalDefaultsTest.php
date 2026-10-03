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

class NullableTemporalDefaultsTest extends TestCase
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
        Schema::create('nullable_temporal_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->date('day')->nullable();
            $table->dateTime('instant')->nullable();
            $table->time('clock')->nullable();
            $table->date('required_day');
            $table->dateTime('required_instant');
            $table->time('required_clock');
            $table->date('literal_day')->nullable()->default('2001-02-03');
            $table->dateTime('literal_instant')->nullable()->default('2001-02-03 04:05:06');
            $table->time('literal_clock')->nullable()->default('04:05:06');
            $table->dateTime('current_instant')->nullable()->useCurrent();
        });
        $this->generatedDirectory = sys_get_temp_dir().'/admin-nullable-temporal-'.bin2hex(random_bytes(8));
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
            [NullableTemporalRecord::class, 'admin:make'],
            [ImmutableNullableTemporalRecord::class, 'admin:make'],
            [NullableTemporalRecord::class, 'admin:controller'],
            [ImmutableNullableTemporalRecord::class, 'admin:controller'],
        ];
    }

    #[DataProvider('generatedModels')]
    public function test_generated_empty_create_preserves_null($model, $command): void
    {
        $url = $this->generateResource($model, $command);
        $inputs = $this->inputs($this->get($url.'/create')->assertOk());
        $this->assertBlankTemporalInputs($inputs);
        // Required fields retain the generator's current-date/time scaffolding.
        foreach (['required_day', 'required_instant', 'required_clock'] as $column) {
            $this->assertNotEmpty($inputs[$column]);
        }
        $inputs['name'] = 'Created with blank optional dates';
        $this->post($url, $inputs)->assertRedirect($url);
        $record = $model::firstOrFail();
        $this->assertNullTemporalStorage($record);
        foreach (['required_day', 'required_instant', 'required_clock'] as $column) {
            $this->assertSame($inputs[$column], $record->getRawOriginal($column));
        }

    }

    #[DataProvider('generatedModels')]
    public function test_generated_unchanged_edit_preserves_existing_null($model, $command): void
    {
        $url = $this->generateResource($model, $command);
        $record = $model::create([
            'name' => 'Existing NULL dates', 'day' => null, 'instant' => null, 'clock' => null,
            'required_day' => '2000-01-02', 'required_instant' => '2000-01-02 03:04:05',
            'required_clock' => '03:04:05', 'literal_day' => '2001-02-03',
            'literal_instant' => '2001-02-03 04:05:06', 'literal_clock' => '04:05:06',
            'current_instant' => '2001-02-03 04:05:06',
        ])->fresh();
        $inputs = $this->inputs($this->get($url.'/'.$record->id.'/edit')->assertOk());
        $this->assertBlankTemporalInputs($inputs);
        $before = $record->getAttributes();
        $this->put($url.'/'.$record->id, $inputs)->assertRedirect($url);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertNullTemporalStorage($record->fresh());
    }

    public function test_explicit_application_field_defaults_still_apply_and_can_be_cleared(): void
    {
        $url = $this->generateResource(NullableTemporalRecord::class, 'admin:make', true);
        $inputs = $this->inputs($this->get($url.'/create')->assertOk());
        $this->assertSame('2001-02-03', $inputs['day']);
        $this->assertSame('2001-02-03 04:05:06', $inputs['instant']);
        $this->assertSame('04:05:06', $inputs['clock']);
        $inputs['name'] = 'Application default control';
        foreach (['day', 'instant', 'clock'] as $column) {
            $inputs[$column] = '';
        }
        $this->post($url, $inputs)->assertRedirect($url);
        $record = NullableTemporalRecord::firstOrFail();
        $this->assertNullTemporalStorage($record);
        $inputs = $this->inputs($this->get($url.'/'.$record->id.'/edit')->assertOk());
        $this->assertSame('2001-02-03', $inputs['day']);
        $this->assertSame('2001-02-03 04:05:06', $inputs['instant']);
        $this->assertSame('04:05:06', $inputs['clock']);
        foreach (['day', 'instant', 'clock'] as $column) {
            $inputs[$column] = '';
        }
        $this->put($url.'/'.$record->id, $inputs)->assertRedirect($url);
        $this->assertNullTemporalStorage($record->fresh());
    }

    private function generateResource($model, $command, $explicitDefaults = false): string
    {
        $namespace = 'App\\NullableTemporal'.bin2hex(random_bytes(8));
        config(['admin.route.namespace' => $namespace]);
        $controller = $command === 'admin:make' ? 'TemporalController' : class_basename($model).'Controller';
        $arguments = $command === 'admin:make' ? ['name' => $controller, '--model' => $model] : ['model' => $model];
        $this->assertSame(0, Artisan::call($command, $arguments), Artisan::output());
        $path = app_path(str_replace('\\', '/', substr($namespace, 4)).'/'.$controller.'.php');
        $source = file_get_contents($path);
        foreach (['day' => 'date', 'instant' => 'datetime', 'clock' => 'time'] as $column => $type) {
            $field = '$form->'.$type."('{$column}', __('".ucfirst($column)."'))";
            $this->assertStringContainsString($field.';', $source);
            if ($explicitDefaults) {
                $defaults = ['day' => '2001-02-03', 'instant' => '2001-02-03 04:05:06', 'clock' => '04:05:06'];
                $source = str_replace($field.';', $field."->default('{$defaults[$column]}');", $source);
            }
        }
        // Explicit database defaults retain existing synthesis; SQL-expression interpretation
        // and honoring temporal database literals are separate generator limitations.
        foreach ([
            'required_day' => ['date', 'Y-m-d'],
            'required_instant' => ['datetime', 'Y-m-d H:i:s'],
            'required_clock' => ['time', 'H:i:s'],
            'literal_day' => ['date', 'Y-m-d'],
            'literal_instant' => ['datetime', 'Y-m-d H:i:s'],
            'literal_clock' => ['time', 'H:i:s'],
            'current_instant' => ['datetime', 'Y-m-d H:i:s'],
        ] as $column => [$type, $format]) {
            $label = ucfirst(str_replace('_', ' ', $column));
            $this->assertStringContainsString('$form->'.$type."('{$column}', __('{$label}'))->default(date('{$format}'));", $source);
        }
        if ($explicitDefaults) {
            file_put_contents($path, $source);
        }
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
        foreach (['name', 'day', 'instant', 'clock', 'required_day', 'required_instant', 'required_clock',
            'literal_day', 'literal_instant', 'literal_clock', 'current_instant'] as $column) {
            $inputs[$column] = $crawler->filter('input[name="'.$column.'"]')->attr('value');
        }

        return $inputs;
    }

    private function assertBlankTemporalInputs(array $inputs): void
    {
        foreach (['day', 'instant', 'clock'] as $column) {
            $this->assertSame('', $inputs[$column], $column);
        }
    }

    private function assertNullTemporalStorage(Model $record): void
    {
        foreach (['day', 'instant', 'clock'] as $column) {
            $this->assertNull($record->getRawOriginal($column), $column);
            $this->assertNull($record->getAttribute($column), $column.' cast');
        }
    }
}

class NullableTemporalRecord extends Model
{
    protected $table = 'nullable_temporal_records';
    protected $guarded = [];
    public $timestamps = false;
    // Laravel has no native time-only cast; that column uses its ordinary string value.
    protected $casts = ['day' => 'date', 'instant' => 'datetime'];
}

class ImmutableNullableTemporalRecord extends NullableTemporalRecord
{
    protected $casts = ['day' => 'immutable_date', 'instant' => 'immutable_datetime'];
}
