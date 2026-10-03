<?php

namespace LaravelAdminNext\Integration;

use DateTimeInterface;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\DateRange;
use Encore\Admin\Form\Field\DatetimeRange;
use Encore\Admin\Form\Field\TimeRange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class DateRangeCastPresentationTest extends TestCase
{
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
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
        Schema::create('date_range_cast_records', function ($table) {
            $table->increments('id');
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->dateTime('datetime_start')->nullable();
            $table->dateTime('datetime_end')->nullable();
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();
        });
        $this->app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
        DateRangeCastController::$modelClass = NativeRangeCastRecord::class;
    }

    public static function roundTrips(): array
    {
        $cases = [];
        foreach (['UTC', 'Asia/Tokyo', 'America/Los_Angeles'] as $timezone) {
            foreach ([NativeRangeCastRecord::class, ImmutableRangeCastRecord::class, PlainRangeCastRecord::class, MatchingRangeCastRecord::class] as $model) {
                foreach (['neither', 'start', 'end'] as $nullEndpoint) {
                    $cases[$timezone.' '.$model.' null '.$nullEndpoint] = [
                        $timezone, $model, $nullEndpoint, '2026-10-03', '2026-10-04', '00:00:00', '23:34:56',
                    ];
                }
            }
        }
        foreach (['2026-03-08', '2026-11-01'] as $day) {
            foreach ([NativeRangeCastRecord::class, ImmutableRangeCastRecord::class] as $model) {
                foreach (['neither', 'start', 'end'] as $nullEndpoint) {
                    $cases['Los Angeles DST '.$day.' '.$model.' null '.$nullEndpoint] = [
                        'America/Los_Angeles', $model, $nullEndpoint, $day, $day, '00:34:56', '03:34:56',
                    ];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('roundTrips')]
    public function test_rendered_edit_values_survive_moment_and_ordinary_put($timezone, $model, $nullEndpoint, $startDay, $endDay, $startClock, $endClock): void
    {
        $this->assertSame($timezone, config('app.timezone'));
        DateRangeCastController::$modelClass = $model;
        $this->app['router']->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) {
            $router->resource('date-range-records', DateRangeCastController::class);
        });
        $this->actingAs(Administrator::firstOrFail(), 'admin');
        $data = [
            'date_start' => $nullEndpoint === 'start' ? null : $startDay,
            'date_end' => $nullEndpoint === 'end' ? null : $endDay,
            'datetime_start' => $nullEndpoint === 'start' ? null : $startDay.' '.$startClock,
            'datetime_end' => $nullEndpoint === 'end' ? null : $endDay.' '.$endClock,
            'time_start' => $nullEndpoint === 'start' ? null : $startClock,
            'time_end' => $nullEndpoint === 'end' ? null : $endClock,
        ];
        $record = $model::create($data)->fresh();
        $before = $record->getRawOriginal();
        $serialized = $record->toArray();
        $url = '/admin/date-range-records/'.$record->id;
        $html = $this->get($url.'/edit')->assertOk()->getContent();
        $values = $this->inputValues($html);
        $parsed = $this->pickerValues($html);

        // Make the real save before asserting display values, so a negative
        // control demonstrates database corruption rather than only bad HTML.
        $this->put($url, $parsed)->assertRedirect('/admin/date-range-records');
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame($serialized, $record->fresh()->toArray());
        $this->assertSame(array_map(function ($value) { return $value ?? ''; }, $data), $values);
        $this->assertSame($values, $parsed);
    }

    public function test_configured_picker_format_locale_and_model_state_are_preserved(): void
    {
        foreach ([NativeRangeCastRecord::class, ImmutableRangeCastRecord::class, StorageFormatRangeCastRecord::class] as $class) {
            $model = new $class($this->rangeData());
            $model->syncOriginal();
            $serialized = $model->toArray();
            $attributes = $model->getAttributes();
            $original = $model->getRawOriginal();
            $dates = [];
            foreach (['date_start', 'date_end', 'datetime_start', 'datetime_end'] as $column) {
                $dates[$column] = $model->getAttribute($column);
            }
            $snapshots = array_map(function ($date) { return [$date->format('Y-m-d H:i:s.u'), $date->getTimezone()->getName(), $date->locale()]; }, $dates);

            $date = (new DateRange('date_start', ['date_end']))->options(['format' => 'LL', 'locale' => 'fr']);
            $this->assertSame(['date_start' => '3 octobre 2026', 'date_end' => '4 octobre 2026'], $this->fieldValues($date, $model));
            $this->assertSame($this->renderedValues($date), $this->pickerValues((string) $date->render(), $date->getScript()));

            $datetime = (new DatetimeRange('datetime_start', ['datetime_end']))->options([
                'format' => 'DD/MM/YYYY [at] HH:mm:ss Z ZZ', 'locale' => 'en', 'useCurrent' => false, 'stepping' => 5,
            ]);
            $this->assertSame([
                'datetime_start' => '03/10/2026 at 00:00:00 +09:00 +0900',
                'datetime_end' => '04/10/2026 at 23:34:56 +09:00 +0900',
            ], $this->fieldValues($datetime, $model));
            $this->assertStringContainsString('"format":"DD\\/MM\\/YYYY [at] HH:mm:ss Z ZZ"', $datetime->getScript());
            $this->assertSame(2, substr_count($datetime->getScript(), '"useCurrent":false'));
            $this->assertSame(2, substr_count($datetime->getScript(), '"stepping":5'));

            config(['app.locale' => 'fr']);
            $inheritedLocale = (new DatetimeRange('datetime_start', ['datetime_end']))->options(['format' => 'LLL']);
            $this->assertSame([
                'datetime_start' => '3 octobre 2026 00:00', 'datetime_end' => '4 octobre 2026 23:34',
            ], $this->fieldValues($inheritedLocale, $model));
            config(['app.locale' => 'en']);

            $this->assertSame($serialized, $model->toArray());
            $this->assertSame($attributes, $model->getAttributes());
            $this->assertSame($original, $model->getRawOriginal());
            $this->assertSame([], $model->getDirty());
            $this->assertSame($snapshots, array_map(function ($date) { return [$date->format('Y-m-d H:i:s.u'), $date->getTimezone()->getName(), $date->locale()]; }, $dates));
            $this->assertSame($snapshots, array_map(function ($column) use ($model) {
                $date = $model->getAttribute($column);

                return [$date->format('Y-m-d H:i:s.u'), $date->getTimezone()->getName(), $date->locale()];
            }, array_combine(array_keys($dates), array_keys($dates))));
            $this->assertSame('Asia/Tokyo', config('app.timezone'));
            $this->assertSame('Asia/Tokyo', date_default_timezone_get());
        }
    }

    public function test_custom_cast_formats_and_serialization_remain_application_controlled(): void
    {
        foreach ([FormattedRangeCastRecord::class, SerializedRangeCastRecord::class] as $class) {
            $model = new $class($this->rangeData());
            $serialized = $model->toArray();
            foreach ($this->fields() as $field) {
                $expected = array_intersect_key($serialized, array_flip($field->column()));
                $this->assertSame($expected, $this->fieldValues($field, $model));
            }
        }
        // A serializeDate override that emits the native serialization is still
        // eligible; the boundary is the actual value, not method ownership.
        $model = new NativeSerializedRangeCastRecord($this->rangeData());
        foreach ($this->fields() as $field) {
            $this->assertSame(array_intersect_key($this->rangeData(), array_flip($field->column())), $this->fieldValues($field, $model));
        }
    }

    public static function customIsoCastTimezones(): array
    {
        return [['UTC']];
    }

    #[DataProvider('customIsoCastTimezones')]
    public function test_explicit_iso_cast_formats_remain_custom_even_when_matching_native_json($timezone): void
    {
        $this->assertSame($timezone, config('app.timezone'));
        $model = new IsoFormattedRangeCastRecord($this->rangeData());
        $serialized = $model->toArray();
        foreach ($this->fields() as $field) {
            foreach ($field->column() as $column) {
                $this->assertSame($model->getAttribute($column)->toJSON(), $serialized[$column]);
            }
            $this->assertSame(array_intersect_key($serialized, array_flip($field->column())), $this->fieldValues($field, $model));
        }
    }

    public function test_ordinary_strings_standalone_and_dotted_fields_remain_unchanged(): void
    {
        foreach (['2026-10-03', '03/10/2026', '2026-10-03T12:34:56+09:00', '2026-10-03T03:34:56.000000Z'] as $value) {
            $model = new PlainRangeCastRecord(array_fill_keys(array_keys($this->rangeData()), $value));
            foreach ($this->fields() as $field) {
                $this->assertSame(array_fill_keys(array_values($field->column()), $value), $this->fieldValues($field, $model));
            }
            foreach ($this->fields() as $field) {
                $field->value(['start' => $value, 'end' => $value]);
                $this->assertSame(array_fill_keys(array_values($field->column()), $value), $this->renderedValues($field));
            }
        }
        $model = new NativeRangeCastRecord($this->rangeData());
        foreach ([DateRange::class, DatetimeRange::class] as $class) {
            $field = new $class('period.datetime_start', ['period.datetime_end']);
            (new Form($model))->pushField($field);
            $serialized = $model->toArray();
            $field->fill(['period' => $serialized]);
            $this->assertSame([
                'period[datetime_start]' => $serialized['datetime_start'], 'period[datetime_end]' => $serialized['datetime_end'],
            ], $this->renderedValues($field));
        }
        // Even a literal dotted key declared as a native model cast remains
        // outside direct-column presentation normalization.
        $dotted = new DottedRangeCastRecord();
        $dotted->setRawAttributes(['period.start' => '2026-10-03 00:00:00', 'period.end' => '2026-10-04 23:34:56']);
        foreach ([DateRange::class, DatetimeRange::class] as $class) {
            $this->assertSame([
                'period[start]' => $dotted->toArray()['period.start'], 'period[end]' => $dotted->toArray()['period.end'],
            ], $this->fieldValues(new $class('period.start', ['period.end']), $dotted));
        }
    }

    public function test_each_endpoint_requires_its_own_native_cast_and_exact_serialized_value(): void
    {
        $model = new MixedRangeCastRecord($this->rangeData());
        $serialized = $model->toArray();
        $this->assertSame(['date_start' => '2026-10-03', 'date_end' => '04/10/2026'], $this->fieldValues(new DateRange('date_start', ['date_end']), $model));
        $this->assertSame(['datetime_start' => '2026-10-03 00:00:00', 'datetime_end' => '2026-10-04 23:34:56'], $this->fieldValues(new DatetimeRange('datetime_start', ['datetime_end']), $model));

        foreach ($this->fields() as $field) {
            (new Form($model))->pushField($field);
            $field->fill($serialized);
            $endColumn = $field->column()['end'];
            $field->value(['start' => 'application-owned start', 'end' => $serialized[$endColumn]]);
            $this->assertSame('application-owned start', array_values($this->renderedValues($field))[0]);
        }
        $native = new NativeRangeCastRecord($this->rangeData());
        foreach ($this->fields() as $field) {
            (new Form($native))->pushField($field);
            $field->fill($native->toArray());
            $startColumn = $field->column()['start'];
            $field->value(['start' => $native->toArray()[$startColumn], 'end' => 'application-owned end']);
            $this->assertSame([$startColumn => $this->rangeData()[$startColumn], $field->column()['end'] => 'application-owned end'], $this->renderedValues($field));
        }
        foreach ($this->fields() as $field) {
            (new Form($native))->pushField($field);
            $columns = $field->column();
            $swapped = ['start' => $native->toArray()[$columns['end']], 'end' => $native->toArray()[$columns['start']]];
            $field->value($swapped);
            $this->assertSame(array_combine(array_values($columns), array_values($swapped)), $this->renderedValues($field));
        }
        $mixedNative = new MixedNativeRangeCastRecord($this->rangeData());
        foreach ($this->fields() as $field) {
            $this->assertSame(array_intersect_key($this->rangeData(), array_flip($field->column())), $this->fieldValues($field, $mixedNative));
        }
    }

    public function test_callbacks_and_parsing_options_keep_the_serialized_input(): void
    {
        $model = new NativeRangeCastRecord($this->rangeData());
        foreach ($this->fields() as $base) {
            $expected = array_intersect_key($model->toArray(), array_flip($base->column()));
            $seen = null;
            $field = (clone $base)->with(function ($value) use (&$seen) {
                $seen = $value;

                return ['start' => 'callback start', 'end' => 'callback end'];
            });
            $this->assertSame(array_combine(array_values($base->column()), ['callback start', 'callback end']), $this->fieldValues($field, $model));
            $this->assertSame(array_combine(['start', 'end'], array_values($expected)), $seen);

            $called = false;
            $field = (clone $base)->customFormat(function ($value) use (&$called) {
                $called = true;

                return $value;
            });
            $this->assertSame($expected, $this->fieldValues($field, $model));
            // Array-column fill has never invoked customFormat; this fix must
            // neither apply normalization nor introduce callback invocation.
            $this->assertFalse($called);
            foreach ([['parseInputDate' => 'customParser'], ['timeZone' => 'UTC']] as $options) {
                $field = (clone $base)->options($options);
                $this->assertSame($expected, $this->fieldValues($field, $model));
                foreach ($options as $key => $value) {
                    $this->assertStringContainsString(json_encode($key).':'.json_encode($value), $field->getScript());
                }
            }
            foreach ([false, null, '', 123] as $format) {
                $this->assertSame($expected, $this->fieldValues((clone $base)->options(['format' => $format]), $model));
            }
        }
    }

    public function test_null_defaults_explicit_values_and_old_input_keep_precedence(): void
    {
        $model = new NativeRangeCastRecord($this->rangeData());
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        foreach ($this->fields() as $field) {
            $columns = array_values($field->column());
            $defaults = ['start' => 'default start', 'end' => 'default end'];
            $defaultField = (clone $field)->default($defaults);
            (new Form($model))->pushField($defaultField);
            $this->assertSame(array_combine($columns, array_values($defaults)), $this->renderedValues($defaultField));

            $nullModel = new NativeRangeCastRecord(array_fill_keys(array_keys($this->rangeData()), null));
            $this->assertSame(array_fill_keys($columns, ''), $this->fieldValues((clone $field)->default($defaults), $nullModel));

            $explicit = clone $field;
            (new Form($model))->pushField($explicit);
            $explicit->fill($model->toArray());
            $explicit->value(['start' => 'explicit start', 'end' => 'explicit end']);
            $this->assertSame(array_combine($columns, ['explicit start', 'explicit end']), $this->renderedValues($explicit));

            foreach ([['old start', 'old end'], ['', ''], [null, null]] as $old) {
                $this->app['session']->flashInput(array_combine($columns, $old));
                $this->assertSame(array_combine($columns, array_map(function ($value) { return $value ?? ''; }, $old)), $this->fieldValues(clone $field, $model));
            }
            $this->app['session']->flashInput([$columns[0] => 'old start']);
            $this->assertSame([$columns[0] => 'old start', $columns[1] => $this->rangeData()[$columns[1]]], $this->fieldValues(clone $field, $model));
            $this->app['session']->flashInput([]);
        }
    }

    public function test_custom_range_subclasses_and_time_range_are_excluded(): void
    {
        $model = new NativeRangeCastRecord($this->rangeData());
        foreach ([new CustomDateRange('date_start', ['date_end']), new CustomDatetimeRange('datetime_start', ['datetime_end']), new TimeRange('datetime_start', ['datetime_end'])] as $field) {
            $this->assertSame(array_intersect_key($model->toArray(), array_flip($field->column())), $this->fieldValues($field, $model));
        }
    }

    private function rangeData(): array
    {
        return ['date_start' => '2026-10-03', 'date_end' => '2026-10-04', 'datetime_start' => '2026-10-03 00:00:00', 'datetime_end' => '2026-10-04 23:34:56'];
    }

    private function fields(): array
    {
        return [new DateRange('date_start', ['date_end']), new DatetimeRange('datetime_start', ['datetime_end'])];
    }

    private function fieldValues($field, $model): array
    {
        (new Form($model))->pushField($field);
        $field->fill($model->toArray());

        return $this->renderedValues($field);
    }

    private function renderedValues($field): array
    {
        return $this->inputValues((string) $field->render());
    }

    private function inputValues(string $html): array
    {
        $values = [];
        (new Crawler($html))->filter('input[type="text"][name]')->each(function (Crawler $input) use (&$values) {
            $values[$input->attr('name')] = $input->attr('value');
        });

        return $values;
    }

    private function pickerValues(string $html, ?string $script = null): array
    {
        $crawler = new Crawler($html);
        preg_match_all('/\$\(\'([^\']+)\'\)\.datetimepicker\((\{[^\r\n]+\})\);/', $script ?? $html, $matches, PREG_SET_ORDER);
        $inputs = [];
        foreach ($matches as $match) {
            $input = $crawler->filter($match[1]);
            $this->assertCount(1, $input, 'Each rendered picker selector must identify exactly one input.');
            $inputs[] = ['name' => $input->attr('name'), 'value' => $input->attr('value'), 'options' => json_decode($match[2], true, 512, JSON_THROW_ON_ERROR)];
        }
        $this->assertCount(count($this->inputValues($html)), $inputs, 'Parse every rendered range endpoint with its actual picker options.');
        $this->assertNotEmpty($inputs);
        $process = proc_open(['node', __DIR__.'/javascript/parse-date-range-picker.js', json_encode($inputs, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Node.js is required for the shipped Moment regression.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}

class PlainRangeCastRecord extends Model
{
    protected $table = 'date_range_cast_records';
    protected $guarded = [];
    public $timestamps = false;
}

class NativeRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'date', 'date_end' => 'date', 'datetime_start' => 'datetime', 'datetime_end' => 'datetime'];
}

class ImmutableRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'immutable_date', 'date_end' => 'immutable_date', 'datetime_start' => 'immutable_datetime', 'datetime_end' => 'immutable_datetime'];
}

class MatchingRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'date:Y-m-d', 'date_end' => 'date:Y-m-d', 'datetime_start' => 'datetime:Y-m-d H:i:s', 'datetime_end' => 'datetime:Y-m-d H:i:s'];
}

class FormattedRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'date:d/m/Y', 'date_end' => 'date:d/m/Y', 'datetime_start' => 'datetime:d/m/Y H:i:s', 'datetime_end' => 'datetime:d/m/Y H:i:s'];
}

class StorageFormatRangeCastRecord extends NativeRangeCastRecord
{
    protected $dateFormat = 'd/m/Y H:i:s';
}

class IsoFormattedRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = [
        'date_start' => 'date:Y-m-d\\TH:i:s.u\\Z', 'date_end' => 'date:Y-m-d\\TH:i:s.u\\Z',
        'datetime_start' => 'datetime:Y-m-d\\TH:i:s.u\\Z', 'datetime_end' => 'datetime:Y-m-d\\TH:i:s.u\\Z',
    ];
}

class SerializedRangeCastRecord extends NativeRangeCastRecord
{
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d\\TH:i:sP');
    }
}

class NativeSerializedRangeCastRecord extends NativeRangeCastRecord
{
    protected function serializeDate(DateTimeInterface $date)
    {
        return parent::serializeDate($date);
    }
}

class MixedRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'date', 'date_end' => 'date:d/m/Y', 'datetime_end' => 'immutable_datetime'];
}

class MixedNativeRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['date_start' => 'date', 'date_end' => 'immutable_date', 'datetime_start' => 'datetime', 'datetime_end' => 'immutable_datetime'];
}

class DottedRangeCastRecord extends PlainRangeCastRecord
{
    protected $casts = ['period.start' => 'datetime', 'period.end' => 'immutable_datetime'];
}

class CustomDateRange extends DateRange
{
    protected $view = 'admin::form.daterange';
}

class CustomDatetimeRange extends DatetimeRange
{
    protected $view = 'admin::form.datetimerange';
}

class DateRangeCastController extends AdminController
{
    public static $modelClass = NativeRangeCastRecord::class;

    protected function form()
    {
        $form = new Form(new static::$modelClass());
        $form->dateRange('date_start', 'date_end');
        $form->datetimeRange('datetime_start', 'datetime_end');
        $form->timeRange('time_start', 'time_end');

        return $form;
    }
}
