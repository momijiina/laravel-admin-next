<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class SliderRangeTest extends TestCase
{
    private array $previousScript = [];
    private array $options = ['type' => 'double', 'min' => 1, 'max' => 100, 'from' => 25, 'to' => 75];
    private array $attributes = [];
    private $default = null;

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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/slider-ranges/create', fn () => $this->renderForm());
            $router->post('/slider-ranges', fn () => $this->form()->store());
            $router->get('/slider-ranges/{id}/edit', fn ($id) => $this->renderForm($id));
            $router->put('/slider-ranges/{id}', fn ($id) => $this->form()->update($id));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('slider_range_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('band')->nullable();
            $table->string('other_band')->default('42');
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new SliderRangeRecord());
        $form->setAction('/slider-ranges');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $form->slider('band')->options($this->options)->attribute($this->attributes)->default($this->default);
        $form->slider('other_band')->options(['min' => 0, 'max' => 100])->default(42);

        return $form;
    }

    private function renderForm($id = null)
    {
        $previous = Admin::$script;
        try {
            Admin::$script = [];
            $form = $this->form();
            $html = $id === null ? $form->render() : $form->edit($id)->render();

            return response()->json(['html' => $html, 'scriptHtml' => Admin::script()->render()]);
        } finally {
            Admin::$script = $previous;
        }
    }

    private function widget(array $fixture): array
    {
        $process = new Process(['node', __DIR__.'/javascript/slider-ranges.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['shipped', 'modern'], array_keys($results));
        $this->assertSame($results['shipped'], $results['modern']);

        return $results;
    }

    private function controls(array $result, string $expected): array
    {
        parse_str($result['query'], $values);
        $this->assertSame($expected, $result['after']['value']);
        $this->assertSame($expected, $values['band']);
        $this->assertSame('42', $values['other_band']);
        // A pair in input.value would redefine the shipped widget's min/max.
        $this->assertSame((string) $this->options['min'], $result['after']['min']);
        $this->assertSame((string) $this->options['max'], $result['after']['max']);

        return $values;
    }

    private function assertCreated(array $values, string $expected): void
    {
        $this->post('/slider-ranges', $values)->assertRedirect();
        $record = SliderRangeRecord::query()->latest('id')->firstOrFail();
        $this->assertSame($expected, $record->band);
        $this->assertSame('42', $record->other_band);
        $record->delete();
    }

    public function test_widget_generated_create_reedit_and_untouched_update_preserve_both_endpoints(): void
    {
        $fixture = $this->get('/slider-ranges/create')->assertOk()->json();
        foreach ($this->widget($fixture + ['update' => ['from' => 20, 'to' => 80]]) as $jquery => $created) {
            $this->assertSame('25;75', $created['initialized']);
            $values = $this->controls($created, '20;80');
            $values['title'] = 'Created';
            $this->post('/slider-ranges', $values)->assertRedirect();
            $record = SliderRangeRecord::query()->latest('id')->firstOrFail();
            $this->assertSame('20;80', $record->band);
            $path = '/slider-ranges/'.$record->id;
            $reedit = $this->widget($this->get($path.'/edit')->assertOk()->json())[$jquery];
            $this->assertSame('20;80', $reedit['before']['from']);
            $this->assertSame('', $reedit['before']['value']);
            $values = $this->controls($reedit, '20;80');
            $values['title'] = 'Only the title changed';
            $this->put($path, $values)->assertRedirect();
            $this->assertSame('Only the title changed', $record->fresh()->title);
            $this->assertSame('20;80', $record->fresh()->band);
            $this->controls($this->widget($this->get($path.'/edit')->assertOk()->json())[$jquery], '20;80');
            $record->delete();
        }
    }

    public function test_saved_pairs_zero_negative_and_configured_bounds_survive_native_updates(): void
    {
        foreach ([
            ['20;80', ['min' => 1, 'max' => 100], '20;80'],
            ['20;80', ['min' => 1, 'max' => 100, 'from' => 25, 'to' => 75], '20;80'],
            ['0;0', ['min' => 0, 'max' => 100], '0;0'],
            ['-80;-20', ['min' => -100, 'max' => 100], '-80;-20'],
            ['-20;0', ['min' => -100, 'max' => 100], '-20;0'],
            ['-20;30', ['min' => -100, 'max' => 100], '-20;30'],
            ['-20;120', ['min' => 1, 'max' => 100], '1;100'],
            ['80;20', ['min' => 1, 'max' => 100], '20;20'],
        ] as [$saved, $options, $expected]) {
            $this->flushSession();
            $this->options = ['type' => 'double'] + $options;
            $record = SliderRangeRecord::create(['title' => 'Original', 'band' => $saved]);
            $path = '/slider-ranges/'.$record->id;
            foreach ($this->widget($this->get($path.'/edit')->assertOk()->json()) as $result) {
                $values = $this->controls($result, $expected);
                $this->put($path, $values)->assertRedirect();
                $this->assertSame($expected, $record->fresh()->band);
            }
            $record->delete();
        }
    }

    public function test_scalar_single_and_malformed_values_keep_existing_fallbacks(): void
    {
        foreach ([
            ['single', '20', '20'], ['single', '0', '0'], ['single', '-20', '-20'],
            ['single', '20;80', '25'],
            ['double', '20', '20;75'], ['double', '0', '0;75'], ['double', '-20', '-20;75'],
            ['double', null, '25;75'], ['double', '', '25;75'], ['double', 'bad', '25;75'],
            ['double', '20;80;90', '25;75'], ['double', '20,80', '25;75'],
            ['double', '20.5;80.5', '25;75'], ['double', '020;80', '25;75'],
            ['double', '+20;80', '25;75'], ['double', '-0;80', '25;75'],
            ['double', ' 20;80', '25;75'], ['double', "20;80\n", '25;75'],
            ['double', '9007199254740993;9007199254740994', '25;75'],
        ] as [$type, $value, $expected]) {
            $this->options = ['type' => $type, 'min' => -100, 'max' => 100, 'from' => 25, 'to' => 75];
            $this->default = $value;
            foreach ($this->widget($this->get('/slider-ranges/create')->assertOk()->json()) as $result) {
                $this->assertCreated($this->controls($result, $expected), $expected);
            }
        }
    }

    public function test_pair_defaults_and_model_values_keep_existing_precedence(): void
    {
        foreach (['20;80', fn () => '20;80'] as $default) {
            $this->default = $default;
            foreach ($this->widget($this->get('/slider-ranges/create')->assertOk()->json()) as $result) {
                $this->assertCreated($this->controls($result, '20;80'), '20;80');
            }
            $record = SliderRangeRecord::create(['title' => 'Original', 'band' => '30;70']);
            foreach ($this->widget($this->get('/slider-ranges/'.$record->id.'/edit')->assertOk()->json()) as $result) {
                $this->controls($result, '30;70');
            }
            $record->delete();
        }
    }

    public function test_explicit_empty_null_and_malformed_old_input_do_not_resurrect_saved_pair(): void
    {
        $this->default = '40;60';
        $record = SliderRangeRecord::create(['title' => 'Original', 'band' => '20;80']);
        foreach (['' => '', 'null' => null, 'bad' => 'bad', 'extra' => '20;80;90', 'zero' => '0;0'] as $old) {
            $this->flushSession();
            $record->update(['band' => '20;80']);
            $this->options['min'] = 0;
            $this->app['session']->flashInput(['band' => $old]);
            $fixture = $this->get('/slider-ranges/'.$record->id.'/edit')->assertOk()->json();
            foreach ($this->widget($fixture) as $result) {
                $this->assertSame($old ?? '', $result['before']['from']);
                $expected = $old === '0;0' ? '0;0' : '25;75';
                $values = $this->controls($result, $expected);
                $this->put('/slider-ranges/'.$record->id, $values)->assertRedirect();
                $this->assertSame($expected, $record->fresh()->band);
            }
        }
    }

    public function test_native_failed_validation_and_corrected_retry_preserve_submitted_pair(): void
    {
        foreach (['shipped', 'modern'] as $jquery) {
            $this->flushSession();
            $record = SliderRangeRecord::create(['title' => 'Original', 'band' => '10;90']);
            $path = '/slider-ranges/'.$record->id;
            $selected = $this->widget($this->get($path.'/edit')->assertOk()->json() + ['update' => ['from' => 30, 'to' => 70]])[$jquery];
            $values = $this->controls($selected, '30;70');
            $values['title'] = '';
            $this->from($path.'/edit')->put($path, $values)->assertRedirect($path.'/edit')->assertSessionHasErrors('title');
            $this->assertSame('30;70', $this->app['session']->getOldInput('band'));
            $this->assertSame('10;90', $record->fresh()->band);
            $this->assertSame('Original', $record->fresh()->title);
            $retry = $this->widget($this->get($path.'/edit')->assertOk()->json())[$jquery];
            $this->assertSame('30;70', $retry['before']['from']);
            $values = $this->controls($retry, '30;70');
            $values['title'] = 'Corrected';
            $this->put($path, $values)->assertRedirect();
            $this->assertSame('30;70', $record->fresh()->band);
            $this->assertSame('Corrected', $record->fresh()->title);
            $record->delete();
        }
    }

    public function test_explicit_data_type_and_data_to_keep_shipped_widget_precedence(): void
    {
        foreach ([
            ['single', ['data-type' => 'double'], '20;80'],
            ['double', ['data-type' => 'single'], '25'],
            ['double', ['data-type' => ''], '20;80'],
            ['double', ['data-type' => 'false'], '20;80'],
            ['double', ['data-type' => '0'], '20;80'],
            ['double', ['data-to' => 90], '20;90'],
            ['double', ['data-to' => 0], '0;0'],
            ['double', ['data-to' => 'bad'], '20;75'],
            ['double', ['data-to' => ''], '20;75'],
            ['double', ['data-to' => 'false'], '20;75'],
            ['double', ['data-to' => 'null'], '20;75'],
            ['double', ['data-to' => null], '20;75'],
            ['double', ['data-from' => 50], '20;80'],
        ] as [$type, $attributes, $expected]) {
            $this->options['type'] = $type;
            $this->options['min'] = 0;
            $this->attributes = $attributes;
            $this->default = '20;80';
            foreach ($this->widget($this->get('/slider-ranges/create')->assertOk()->json()) as $result) {
                $this->assertCreated($this->controls($result, $expected), $expected);
            }
        }
    }

    public function test_repeated_initializer_does_not_reset_a_live_selection(): void
    {
        $this->default = '20;80';
        $fixture = $this->get('/slider-ranges/create')->assertOk()->json();
        foreach ($this->widget($fixture + ['update' => ['from' => 35, 'to' => 65], 'repeat' => true]) as $result) {
            $this->assertSame('20;80', $result['initialized']);
            $this->controls($result, '35;65');
        }
    }
}

class SliderRangeRecord extends Model
{
    protected $table = 'slider_range_records';
    public $timestamps = false;
    protected $guarded = [];
}
