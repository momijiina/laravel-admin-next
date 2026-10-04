<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class SwitchLabelsTest extends TestCase
{
    private array $previousScript = [];
    private array $states = [
        'on' => ['value' => 1, 'text' => "C'est actif", 'color' => 'success'],
        'off' => ['value' => 0, 'text' => "N'est pas actif", 'color' => 'warning'],
    ];
    private $size = 'small';
    private $default = 0;
    private array $attributes = [];

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
            $router->get('/switch-labels/create', fn () => $this->renderForm());
            $router->post('/switch-labels', fn () => $this->form()->store());
            $router->get('/switch-labels/{id}/edit', fn ($id) => $this->renderForm($id));
            $router->put('/switch-labels/{id}', fn ($id) => $this->form()->update($id));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('switch_label_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->integer('enabled');
            $table->integer('other_enabled')->default(1);
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new SwitchLabelRecord());
        $form->setAction('/switch-labels');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $form->switch('enabled')->states($this->states)->setSize($this->size)
            ->default($this->default)->attribute($this->attributes);
        $form->switch('other_enabled')->default(1);

        return $form;
    }

    private function renderForm($id = null)
    {
        $previous = Admin::$script;
        try {
            Admin::$script = [];
            Admin::script('window.switchBefore = (window.switchBefore || 0) + 1;');
            $form = $this->form();
            $html = $id === null ? $form->render() : $form->edit($id)->render();
            Admin::script('window.switchAfter = (window.switchAfter || 0) + 1;');

            return response()->json([
                'html' => $html, 'scriptHtml' => Admin::script()->render(),
                // Switch has historically passed these options to the plugin as strings.
                'options' => [
                    'size' => (string) $this->size,
                    'onText' => (string) $this->states['on']['text'],
                    'offText' => (string) $this->states['off']['text'],
                    'onColor' => (string) $this->states['on']['color'],
                    'offColor' => (string) $this->states['off']['color'],
                ],
            ]);
        } finally {
            Admin::$script = $previous;
        }
    }

    private function widget(array $fixture, array $actions = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/switch-labels.cjs']);
        $process->setInput(json_encode($fixture + ['actions' => $actions], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['shipped', 'modern'], array_keys($results));
        $this->assertSame($results['shipped'], $results['modern']);

        return $results;
    }

    private function controls(array $result, string $expected, string $other = 'on'): array
    {
        parse_str($result['query'], $values);
        $this->assertSame($expected, $values['enabled']);
        $this->assertSame($other, $values['other_enabled']);
        $this->assertSame($expected === 'on', $result['checked']);

        return $values;
    }

    public function test_quoted_labels_initialize_full_form_and_native_clicks_save_create_and_edit(): void
    {
        $fixture = $this->get('/switch-labels/create')->assertOk()->json();
        foreach ($this->widget($fixture, ['native']) as $jquery => $result) {
            $this->assertSame('off', $result['initial']);
            $values = $this->controls($result, 'on');
            $this->post('/switch-labels', $values)->assertRedirect();
            $record = SwitchLabelRecord::query()->latest('id')->firstOrFail();
            $this->assertSame(1, $record->enabled);
            $this->assertSame(1, $record->other_enabled);
            $path = '/switch-labels/'.$record->id;
            $edited = $this->widget($this->get($path.'/edit')->assertOk()->json())[$jquery];
            $this->assertSame('on', $edited['initial']);
            $values = $this->controls($edited, 'on');
            $values['title'] = 'Only the title changed';
            $this->put($path, $values)->assertRedirect();
            $this->assertSame(1, $record->fresh()->enabled);
            $off = $this->widget($this->get($path.'/edit')->assertOk()->json(), ['handle-off'])[$jquery];
            $this->put($path, $this->controls($off, 'off'))->assertRedirect();
            $this->assertSame(0, $record->fresh()->enabled);
            $this->controls($this->widget($this->get($path.'/edit')->assertOk()->json())[$jquery], 'off');
            $record->delete();
        }
    }

    public function test_ordinary_localized_multiline_and_html_labels_reach_the_real_plugin_unchanged(): void
    {
        foreach ([
            ['ON', 'OFF'], ["C'est actif", 'Not "active"'],
            ["ligne 1\nligne 2", "ligne 1\r\nligne 2"],
            ["C:\\nouveau\\test", "Fin\\"], ["Oui\tmaintenant", "Non\rplus tard"],
            ['有効 ✓', '無効 ✕'], ["Oui\u{2028}encore", "Non\u{2029}demain"],
            ['<b>Oui</b>', '<i>Non &amp; arrêt</i>'],
            ['<span title="C\'est actif">Oui</span>', '<em>Non</em>'],
        ] as [$on, $off]) {
            $this->states['on']['text'] = $on;
            $this->states['off']['text'] = $off;
            foreach ($this->widget($this->get('/switch-labels/create')->assertOk()->json(), ['handle-on']) as $result) {
                $this->post('/switch-labels', $this->controls($result, 'on'))->assertRedirect();
                $this->assertSame(1, SwitchLabelRecord::query()->latest('id')->firstOrFail()->enabled);
            }
        }
    }

    public function test_scalar_labels_preserve_existing_string_coercion_and_supported_options(): void
    {
        foreach ([
            [0, 42, 'mini', 'primary', 'default'],
            [1.5, -2, 'small', 'success', 'warning'],
            [true, false, 'normal', 'info', 'danger'],
            [null, '', 'large', 'default', 'primary'],
        ] as [$on, $off, $size, $onColor, $offColor]) {
            $this->states['on']['text'] = $on;
            $this->states['off']['text'] = $off;
            $this->states['on']['color'] = $onColor;
            $this->states['off']['color'] = $offColor;
            $this->size = $size;
            foreach ($this->widget($this->get('/switch-labels/create')->assertOk()->json(), ['native']) as $result) {
                $this->controls($result, 'on');
            }
        }
    }

    public function test_custom_state_values_defaults_and_both_fields_keep_independent_mapping(): void
    {
        $this->states['on']['value'] = 7;
        $this->states['off']['value'] = -3;
        $this->default = -3;
        foreach ($this->widget($this->get('/switch-labels/create')->assertOk()->json(), ['handle-on', 'other-native']) as $result) {
            $this->assertSame('off', $result['initial']);
            $this->post('/switch-labels', $this->controls($result, 'on', 'off'))->assertRedirect();
            $record = SwitchLabelRecord::query()->latest('id')->firstOrFail();
            $this->assertSame(7, $record->enabled);
            $this->assertSame(0, $record->other_enabled);
            $path = '/switch-labels/'.$record->id;
            foreach ($this->widget($this->get($path.'/edit')->assertOk()->json(), ['native']) as $edited) {
                $this->assertSame('on', $edited['initial']);
                $this->put($path, $this->controls($edited, 'off', 'off'))->assertRedirect();
                $this->assertSame(-3, $record->fresh()->enabled);
            }
        }
        $this->default = fn () => 7;
        foreach ($this->widget($this->get('/switch-labels/create')->assertOk()->json()) as $result) {
            $this->controls($result, 'on');
        }
    }

    public function test_failed_create_and_edit_redisplay_clicked_state_and_corrected_retry_persists(): void
    {
        $record = SwitchLabelRecord::create(['title' => 'Saved', 'enabled' => 0, 'other_enabled' => 1]);
        foreach ([null, $record->id] as $id) {
            $this->flushSession();
            $path = $id === null ? '/switch-labels' : '/switch-labels/'.$id;
            $page = $id === null ? $path.'/create' : $path.'/edit';
            $method = $id === null ? 'post' : 'put';
            $fixture = $this->get($page)->assertOk()->json();
            foreach ($this->widget($fixture, ['native']) as $jquery => $result) {
                $this->flushSession();
                $values = $this->controls($result, 'on');
                $values['title'] = '';
                $count = SwitchLabelRecord::count();
                $this->from($page)->{$method}($path, $values)->assertRedirect($page)->assertSessionHasErrors('title');
                $this->assertSame($count, SwitchLabelRecord::count());
                $this->assertSame(0, $record->fresh()->enabled);
                $retry = $this->widget($this->get($page)->assertOk()->json())[$jquery];
                $this->assertSame('on', $retry['initial']);
                $values = $this->controls($retry, 'on');
                $values['title'] = 'Corrected';
                $this->{$method}($path, $values)->assertRedirect()->assertSessionHasNoErrors();
                $saved = $id === null ? SwitchLabelRecord::query()->latest('id')->firstOrFail() : $record->fresh();
                $this->assertSame(1, $saved->enabled);
                $this->assertSame('Corrected', $saved->title);
                if ($id === null) {
                    $saved->delete();
                } else {
                    $record->refresh()->update(['enabled' => 0, 'title' => 'Saved']);
                }
            }
        }
    }

    public function test_repeated_initializer_does_not_reset_state_or_duplicate_change_handlers(): void
    {
        $fixture = $this->get('/switch-labels/create')->assertOk()->json();
        foreach ($this->widget($fixture, ['native', 'repeat', 'handle-off', 'handle-on', 'repeat']) as $result) {
            $this->controls($result, 'on');
            $this->assertSame(3, $result['changes']);
        }
    }

    public function test_readonly_and_disabled_plugin_controls_keep_existing_state_and_submission(): void
    {
        foreach (['readonly', 'disabled'] as $attribute) {
            $this->attributes = [$attribute => $attribute];
            foreach ($this->widget($this->get('/switch-labels/create')->assertOk()->json(), ['blocked-handle-on']) as $result) {
                $this->controls($result, 'off');
                $this->assertSame(0, $result['changes']);
            }
        }
    }
}

class SwitchLabelRecord extends Model
{
    protected $table = 'switch_label_records';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['enabled' => 'integer', 'other_enabled' => 'integer'];
}
