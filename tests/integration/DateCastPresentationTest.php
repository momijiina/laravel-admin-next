<?php

namespace LaravelAdminNext\Integration;

use DateTimeInterface;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Date;
use Encore\Admin\Form\Field\Datetime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class DateCastPresentationTest extends TestCase
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

    protected function getApplicationTimezone($app)
    {
        // Testbench applies this only to the disposable test application.
        $app['config']->set('app.timezone', $this->providedData()[0] ?? 'Asia/Tokyo');

        return parent::getApplicationTimezone($app);
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
        Schema::create('date_cast_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->date('anniversary')->nullable();
            $table->dateTime('starts_at')->nullable();
        });
        $this->generatedDirectory = sys_get_temp_dir().'/admin-date-casts-'.bin2hex(random_bytes(8));
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

    public static function nativeCasts(): array
    {
        $cases = [];
        foreach (['UTC', 'Asia/Tokyo', 'America/Los_Angeles'] as $timezone) {
            foreach ([DateCastRecord::class, ImmutableDateCastRecord::class] as $model) {
                $cases[$timezone.' '.$model] = [$timezone, $model, '2026-10-03'];
            }
        }

        foreach (['2026-03-08', '2026-11-01'] as $day) {
            $cases['Los Angeles DST '.$day] = ['America/Los_Angeles', DateCastRecord::class, $day];
        }

        return $cases;
    }

    #[DataProvider('nativeCasts')]
    public function test_generated_edit_and_ordinary_save_preserve_local_dates_and_times($timezone, $model, $day): void
    {
        $this->assertSame($timezone, config('app.timezone'));
        $namespace = 'App\\DateCasts'.bin2hex(random_bytes(8));
        config(['admin.route.namespace' => $namespace]);
        $this->assertSame(0, Artisan::call('admin:make', ['name' => 'DateController', '--model' => $model]), Artisan::output());
        require $this->generatedDirectory.'/'.str_replace('\\', '/', substr($namespace, 4)).'/DateController.php';
        $this->app['router']->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) use ($namespace) {
            $router->resource('date-records', $namespace.'\\DateController');
        });
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        // Early/late clocks cover UTC conversions crossing either calendar boundary.
        foreach (['00:34:56', '03:34:56', '23:34:56'] as $clock) {
            $record = $model::create(['name' => 'Local dates', 'anniversary' => $day, 'starts_at' => $day.' '.$clock])->fresh();
            $before = $record->getRawOriginal();
            $serialized = $record->toArray();
            $url = '/admin/date-records/'.$record->id;
            $response = $this->get($url.'/edit')->assertOk();
            $crawler = new Crawler($response->getContent());
            $values = [$crawler->filter('input[name="anniversary"]')->attr('value'), $crawler->filter('input[name="starts_at"]')->attr('value')];
            $parsed = $this->pickerValues($values);
            $this->put($url, ['name' => 'Local dates', 'anniversary' => $parsed[0], 'starts_at' => $parsed[1]])->assertRedirect('/admin/date-records');
            $this->assertSame($before, $record->fresh()->getRawOriginal());
            $this->assertSame($serialized, $record->fresh()->toArray());
            $this->assertSame([$day, $day.' '.$clock], $values);
            $this->assertSame($values, $parsed);
        }
    }

    public function test_nulls_old_input_and_explicit_values_are_preserved(): void
    {
        $model = new DateCastRecord(['anniversary' => null, 'starts_at' => null]);
        $this->assertSame('', $this->fieldValue(new Date('anniversary'), $model));
        $this->assertSame('', $this->fieldValue(new Datetime('starts_at'), $model));
        $model->anniversary = '2026-10-03';
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        $this->app['session']->flashInput(['anniversary' => '2026-11-05']);
        $this->assertSame('2026-11-05', $this->fieldValue(new Date('anniversary'), $model));
        $this->app['session']->flashInput(['anniversary' => '']);
        $this->assertSame('', $this->fieldValue(new Date('anniversary'), $model));
        $this->app['session']->flashInput([]);
        $field = new Date('anniversary');
        $field->attribute('value', '2026-12-06');
        $this->assertSame('2026-12-06', $this->fieldValue($field, $model));
    }

    public function test_custom_formats_are_applied_only_to_native_serialization_without_model_mutation(): void
    {
        foreach ([DateCastRecord::class, ImmutableDateCastRecord::class, StorageFormattedDateCastRecord::class] as $class) {
            $model = new $class(['anniversary' => '2026-10-03', 'starts_at' => '2026-10-03 12:34:56']);
            $before = $model->toArray();
            $raw = $model->getAttributes();
            $timezone = $model->starts_at->getTimezone()->getName();
            $date = (new Date('anniversary'))->format('DD/MM/YYYY');
            $datetime = (new Datetime('starts_at'))->format('DD/MM/YYYY [at] HH:mm');
            $datetime->options(['locale' => 'en', 'useCurrent' => false, 'stepping' => 5]);
            $this->assertSame('03/10/2026', $this->fieldValue($date, $model));
            $this->assertSame('03/10/2026 at 12:34', $this->fieldValue($datetime, $model));
            $zoned = (new Datetime('starts_at'))->format('YYYY-MM-DD[T]HH:mm:ss Z ZZ');
            $this->assertSame('2026-10-03T12:34:56 +09:00 +0900', $this->fieldValue($zoned, $model));
            $localized = (new Date('anniversary'))->format('LL')->options(['locale' => 'fr']);
            $this->assertSame('3 octobre 2026', $this->fieldValue($localized, $model));
            $this->assertStringContainsString('"useCurrent":false', $datetime->getScript());
            $this->assertStringContainsString('"stepping":5', $datetime->getScript());
            $this->assertSame($before, $model->toArray());
            $this->assertSame($raw, $model->getAttributes());
            $this->assertSame($timezone, $model->starts_at->getTimezone()->getName());
        }
    }

    public function test_ordinary_zoned_and_custom_serialized_strings_are_not_reformatted(): void
    {
        foreach (['2026-10-03', '03/10/2026', '2026-10-03T12:34:56+09:00', '2026-10-03T03:34:56.000000Z'] as $value) {
            $plain = new PlainDateRecord(['starts_at' => $value]);
            $this->assertSame($value, $this->fieldValue(new Datetime('starts_at'), $plain));
            $standalone = (new Datetime('starts_at'))->value($value);
            $this->assertSame($value, $this->renderedValue($standalone));
        }
        foreach ([FormattedDateCastRecord::class, SerializedDateCastRecord::class] as $class) {
            $model = new $class(['anniversary' => '2026-10-03', 'starts_at' => '2026-10-03 12:34:56']);
            $serialized = $model->toArray();
            $this->assertSame($serialized['anniversary'], $this->fieldValue(new Date('anniversary'), $model));
            $this->assertSame($serialized['starts_at'], $this->fieldValue(new Datetime('starts_at'), $model));
        }
    }

    public function test_custom_callbacks_and_picker_parsing_options_retain_control(): void
    {
        $model = new DateCastRecord(['starts_at' => '2026-10-03 12:34:56']);
        $serialized = $model->toArray()['starts_at'];
        $field = (new Datetime('starts_at'))->customFormat(function ($value) use ($serialized) {
            $this->assertSame($serialized, $value);

            return $value;
        });
        $this->assertSame($serialized, $this->fieldValue($field, $model));
        $seen = null;
        $field = (new Datetime('starts_at'))->with(function ($value) use (&$seen) {
            $seen = $value;

            return $value;
        });
        $this->assertSame($serialized, $this->fieldValue($field, $model));
        $this->assertSame($serialized, $seen);
        foreach ([['parseInputDate' => 'customParser'], ['timeZone' => 'UTC']] as $options) {
            $field = (new Datetime('starts_at'))->options($options);
            $this->assertSame($serialized, $this->fieldValue($field, $model));
        }
    }

    private function fieldValue($field, $model): string
    {
        (new Form($model))->pushField($field);
        $field->fill($model->toArray());

        return $this->renderedValue($field);
    }

    private function renderedValue($field): string
    {
        return (new Crawler((string) $field->render()))->filter('input')->attr('value');
    }

    private function pickerValues(array $values): array
    {
        // The shipped Moment parser used by datetimepicker, not a browser/UI test.
        $process = proc_open(['node', __DIR__.'/fixtures/parse-date-inputs.js', ...$values], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Node.js is required for the shipped Moment regression.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}

class PlainDateRecord extends Model
{
    protected $table = 'date_cast_records';
    protected $guarded = [];
    public $timestamps = false;
}

class DateCastRecord extends PlainDateRecord
{
    protected $casts = ['anniversary' => 'date', 'starts_at' => 'datetime'];
}

class ImmutableDateCastRecord extends PlainDateRecord
{
    protected $casts = ['anniversary' => 'immutable_date', 'starts_at' => 'immutable_datetime'];
}

class FormattedDateCastRecord extends PlainDateRecord
{
    protected $casts = ['anniversary' => 'date:d/m/Y', 'starts_at' => 'datetime:d/m/Y H:i:s'];
}

class StorageFormattedDateCastRecord extends DateCastRecord
{
    protected $dateFormat = 'd/m/Y H:i:s';
}

class SerializedDateCastRecord extends DateCastRecord
{
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d\\TH:i:sP');
    }
}
