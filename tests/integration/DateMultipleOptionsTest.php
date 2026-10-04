<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\DateMultiple;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class DateMultipleOptionsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('date_multiple_records', function ($table) {
            $table->increments('id');
            $table->text('dates')->nullable();
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('date-multiple/{id}/edit', function ($id) {
            config(['app.locale' => request('appLocale', 'en')]);
            $form = new Form(new DateMultipleRecord());
            $field = $this->configureField($form, request('mode', 'default'));
            $field->fill(DateMultipleRecord::findOrFail($id)->toArray());
            return [
                'html' => '<form method="post">'.$field->render().'<button type="submit">Save</button></form>',
                'script' => $field->getScript(),
            ];
        })->middleware('web');
        $save = function ($id = null) {
            $form = new Form(new DateMultipleRecord());
            $this->configureField($form, request('mode', 'default'))->rules('nullable|string');
            return $id === null ? $form->store() : $form->update($id);
        };
        $this->app['router']->post('date-multiple', $save)->middleware('web');
        $this->app['router']->put('date-multiple/{id}', $save)->middleware('web');
    }

    private function configureField(Form $form, string $mode): DateMultiple
    {
        $field = $form->DateMultiple('dates');
        switch ($mode) {
            case 'format':
                return $field->format('d/m/Y');
            case 'date-format':
                return $field->options(['dateFormat' => 'd/m/Y']);
            case 'format-first':
                return $field->format('m/d/Y')->options(['dateFormat' => 'd/m/Y']);
            case 'options-first':
                return $field->options(['dateFormat' => 'd/m/Y'])->format('m/d/Y');
            case 'options-merge':
                return $field->options(['dateFormat' => 'm/d/Y', 'conjunction' => ' | '])
                    ->options(['dateFormat' => 'd/m/Y']);
            case 'conjunction':
                return $field->options(['conjunction' => ' | ']);
            case 'min-date':
                return $field->options(['minDate' => '2026-10-03']);
            case 'max-date':
                return $field->options(['maxDate' => '2026-10-07']);
            case 'disable':
                return $field->options(['disable' => ['2026-10-05']]);
            case 'enable':
                return $field->options(['enable' => ['2026-10-03', '2026-10-07']]);
            case 'limits':
                return $field->options([
                    'dateFormat' => 'd/m/Y', 'minDate' => '03/10/2026',
                    'maxDate' => '07/10/2026', 'disable' => ['05/10/2026'],
                ]);
            case 'alt-input':
                return $field->options(['altInput' => true, 'altFormat' => 'd/m/Y']);
            case 'allow-input':
                return $field->options(['allowInput' => true]);
            case 'scalar-null':
                return $field->options([
                    'minDate' => null, 'maxDate' => null, 'allowInput' => false,
                    'weekNumbers' => true, 'showMonths' => 2, 'defaultHour' => 0,
                    'disable' => [],
                ]);
            case 'literal-string':
                return $field->options(['conjunction' => 'function() { window.unexpectedExecution = true; }']);
            case 'explicit-locale':
                return $field->options(['locale' => 'en']);
            case 'locale-object':
                return $field->options(['locale' => ['firstDayOfWeek' => 1]]);
            case 'managed-options':
                return $field->options(['mode' => 'single', 'plugins' => []]);
        }

        return $field;
    }

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery 2.1.4' => ['shipped'];
        yield 'modern jQuery 3.7.1' => ['modern'];
    }

    private function runCases(string $jquery, array $cases): void
    {
        $fixtures = [];
        $records = [];
        foreach ($cases as $case) {
            $this->app['session']->flush();
            $record = DateMultipleRecord::create(['dates' => $case['before']]);
            $query = http_build_query(['mode' => $case['mode'], 'appLocale' => $case['appLocale'] ?? 'en']);
            $fixtures[] = $this->get('/date-multiple/'.$record->id.'/edit?'.$query)->assertOk()->json()
                + ['actions' => $case['actions'] ?? []];
            $records[] = $record;
        }
        $process = new Process(['node', __DIR__.'/javascript/date-multiple-options.cjs']);
        $process->setTimeout(60);
        $process->setInput(json_encode([
            'fixtures' => $fixtures, 'jquery' => $jquery,
            'assets' => __DIR__.'/../../resources/assets',
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(count($cases), $results);
        foreach ($cases as $index => $case) {
            $this->app['session']->flush();
            $actual = $results[$index];
            $label = $jquery.' '.json_encode($case);
            $this->assertSame([], $actual['logs'], $label);
            $this->assertFalse($actual['unexpectedExecution'], $label);
            $this->assertSame('multiple', $actual['config']['mode'], $label);
            $this->assertSame('Clear', $actual['clearButton'], $label);
            $this->assertSame(1, $actual['config']['pluginCount'], $label);
            $this->assertSame((string) $case['before'], $actual['before'], $label);
            $this->assertSame($case['expected'], $actual['final']['value'], $label);
            $this->assertSame(['dates' => $case['expected']], $actual['final']['native'], $label);
            parse_str($actual['final']['jquery'], $serialized);
            $this->assertSame($actual['final']['native'], $serialized, $label);
            foreach ($case['config'] ?? [] as $key => $expected) {
                $this->assertSame($expected, $actual['config'][$key], $label.' config '.$key);
            }
            foreach ($case['final'] ?? [] as $key => $expected) {
                $this->assertSame($expected, $actual['final'][$key], $label.' final '.$key);
            }
            if (isset($case['clicks'])) {
                $this->assertSame($case['clicks'], array_column($actual['clicks'], 'disabled'), $label);
            }
            $response = $this->put('/date-multiple/'.$records[$index]->id, $actual['final']['native'], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
            $response->assertOk()->assertJson(['status' => true]);
            $this->assertSame($case['expected'] === '' ? null : $case['expected'], $records[$index]->fresh()->getRawOriginal('dates'), $label);
            // The same actual successful controls also exercise the create path.
            $this->post('/date-multiple', $actual['final']['native'], ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->assertJson(['status' => true]);
            $this->assertSame($case['expected'] === '' ? null : $case['expected'], DateMultipleRecord::latest('id')->first()->getRawOriginal('dates'), $label);
        }
    }

    #[DataProvider('jqueryVersions')]
    public function test_native_formats_conjunction_and_precedence_survive_saves(string $jquery): void
    {
        $cases = [];
        foreach (['format', 'date-format', 'format-first', 'options-first'] as $mode) {
            $cases[] = [
                'mode' => $mode, 'before' => '03/10/2026, 04/10/2026',
                'expected' => '03/10/2026, 04/10/2026', 'config' => ['dateFormat' => 'd/m/Y'],
                'final' => ['selected' => ['2026-10-03', '2026-10-04']],
            ];
            $cases[] = [
                'mode' => $mode, 'before' => null, 'actions' => ['2026-10-03', '2026-10-04'],
                'expected' => '03/10/2026, 04/10/2026', 'config' => ['dateFormat' => 'd/m/Y'],
            ];
        }
        $cases[] = ['mode' => 'options-merge', 'before' => '03/10/2026 | 04/10/2026', 'expected' => '03/10/2026 | 04/10/2026', 'config' => ['dateFormat' => 'd/m/Y', 'conjunction' => ' | ']];
        $cases[] = ['mode' => 'conjunction', 'before' => '2026-10-03 | 2026-10-04', 'expected' => '2026-10-03 | 2026-10-04'];
        $cases[] = ['mode' => 'conjunction', 'before' => '2026-10-03', 'actions' => ['2026-10-04'], 'expected' => '2026-10-03 | 2026-10-04'];
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_calendar_clicks_enforce_configured_limits(string $jquery): void
    {
        $this->runCases($jquery, [
            ['mode' => 'min-date', 'before' => null, 'actions' => ['2026-10-02', '2026-10-03'], 'clicks' => [true, false], 'expected' => '2026-10-03', 'config' => ['minDate' => '2026-10-03']],
            ['mode' => 'max-date', 'before' => null, 'actions' => ['2026-10-08', '2026-10-07'], 'clicks' => [true, false], 'expected' => '2026-10-07', 'config' => ['maxDate' => '2026-10-07']],
            ['mode' => 'disable', 'before' => null, 'actions' => ['2026-10-05', '2026-10-04'], 'clicks' => [true, false], 'expected' => '2026-10-04'],
            ['mode' => 'enable', 'before' => null, 'actions' => ['2026-10-05', '2026-10-03', '2026-10-07'], 'clicks' => [true, false, false], 'expected' => '2026-10-03, 2026-10-07'],
            ['mode' => 'limits', 'before' => null, 'actions' => ['2026-10-02', '2026-10-05', '2026-10-08', '2026-10-03', '2026-10-07'], 'clicks' => [true, true, true, false, false], 'expected' => '03/10/2026, 07/10/2026'],
        ]);
    }

    #[DataProvider('jqueryVersions')]
    public function test_defaults_locale_clear_and_json_data_options(string $jquery): void
    {
        $this->runCases($jquery, [
            ['mode' => 'default', 'before' => '2026-10-03, 2026-10-04', 'expected' => '2026-10-03, 2026-10-04', 'config' => ['dateFormat' => 'Y-m-d', 'locale' => 'zh', 'month' => '十月']],
            ['mode' => 'default', 'before' => '2026-10-03', 'actions' => ['2026-10-04'], 'expected' => '2026-10-03, 2026-10-04'],
            ['mode' => 'default', 'before' => null, 'expected' => '', 'final' => ['selected' => []]],
            ['mode' => 'default', 'before' => '2026-10-03', 'actions' => ['clear'], 'expected' => '', 'final' => ['selected' => [], 'isOpen' => false]],
            ['mode' => 'format', 'before' => '03/10/2026, 04/10/2026', 'actions' => ['clear'], 'expected' => '', 'final' => ['selected' => []]],
            ['mode' => 'default', 'appLocale' => 'zh', 'before' => '2026-10-03', 'expected' => '2026-10-03', 'config' => ['locale' => 'zh', 'month' => '十月']],
            ['mode' => 'explicit-locale', 'appLocale' => 'zh', 'before' => '2026-10-03', 'expected' => '2026-10-03', 'config' => ['locale' => 'en', 'month' => 'October']],
            ['mode' => 'locale-object', 'before' => '2026-10-03', 'expected' => '2026-10-03', 'config' => ['firstDayOfWeek' => 1]],
            ['mode' => 'alt-input', 'before' => '2026-10-03, 2026-10-04', 'expected' => '2026-10-03, 2026-10-04', 'final' => ['alternate' => '03/10/2026, 04/10/2026']],
            ['mode' => 'allow-input', 'before' => '2026-10-03', 'expected' => '2026-10-03', 'config' => ['allowInput' => true], 'final' => ['readOnly' => false]],
            ['mode' => 'scalar-null', 'before' => '2026-10-03', 'expected' => '2026-10-03', 'config' => ['minDate' => null, 'maxDate' => null, 'allowInput' => false, 'weekNumbers' => true, 'showMonths' => 2, 'defaultHour' => 0]],
            ['mode' => 'literal-string', 'before' => '2026-10-03', 'actions' => ['2026-10-04'], 'expected' => '2026-10-03function() { window.unexpectedExecution = true; }2026-10-04'],
            ['mode' => 'managed-options', 'before' => '2026-10-03', 'actions' => ['2026-10-04'], 'expected' => '2026-10-03, 2026-10-04'],
            ['mode' => 'managed-options', 'before' => '2026-10-03', 'actions' => ['clear'], 'expected' => ''],
        ]);
    }
}

class DateMultipleRecord extends Model
{
    protected $table = 'date_multiple_records';
    protected $guarded = [];
    public $timestamps = false;
}
