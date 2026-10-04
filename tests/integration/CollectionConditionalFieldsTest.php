<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class CollectionConditionalFieldsTest extends TestCase
{
    private array $config = [];
    private array $previousScript = [];
    private array $previousStyle = [];
    private const ESCAPED = "quoted'\"\\choice\n</script>&";

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/conditional/create', function () {
                return $this->renderForm();
            });
            $router->get('/conditional/{id}/edit', function ($id) {
                return $this->renderForm($id);
            });
            $router->post('/conditional', function () {
                return $this->form()->store();
            });
            $router->put('/conditional/{id}', function ($id) {
                return $this->form()->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        $this->previousStyle = Admin::$style;
        Schema::create('conditional_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('choices')->nullable();
            $table->text('other_choices')->nullable();
            $table->string('scalar')->nullable();
            foreach (['has', 'any', 'none', 'equal', 'different', 'escaped', 'empty', 'other', 'scalar_equal', 'scalar_in', 'scalar_gt'] as $name) {
                $table->string('detail_'.$name)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        Admin::$style = $this->previousStyle;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new ConditionalRecord());
        $form->setAction('/conditional');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required');
        $field = $form->{$this->config['widget']}('choices')->options([
            0 => 'Zero', 2 => 'Two', 'alpha' => 'Alpha', 'blocked' => 'Blocked', 'excluded' => 'Excluded', self::ESCAPED => 'Escaped',
        ]);
        if (array_key_exists('default', $this->config)) {
            $field->default($this->config['default']);
        }
        foreach ([
            ['has', 'alpha', 'has'], ['oneIn', [0, 2], 'any'], ['oneNotIn', ['blocked', 'excluded'], 'none'],
            ['=', ['alpha', 2], 'equal'], ['!=', ['blocked', 'alpha'], 'different'],
            ['has', self::ESCAPED, 'escaped'], ['has', '', 'empty'],
        ] as [$operator, $value, $name]) {
            $field->when($operator, $value, function (Form $form) use ($name) {
                $form->text('detail_'.$name);
            });
        }
        $form->multipleSelect('other_choices')->options(['alpha' => 'Alpha'])->default(['alpha'])
            ->when('has', 'alpha', function (Form $form) {
                $form->text('detail_other');
            });
        return $form;
    }

    private function renderForm($id = null)
    {
        $previousScript = Admin::$script;
        try {
            Admin::$script = [];
            $form = $this->form();
            $html = $id === null ? $form->render() : $form->edit($id)->render();
            return response()->json(['html' => $html, 'script' => implode("\n", Admin::$script)]);
        } finally {
            Admin::$script = $previousScript;
        }
    }

    private function widget(array $fixture, array $expected, bool $changes = false): array
    {
        $process = new Process(['node', __DIR__.'/javascript/collection-conditional-fields.cjs']);
        $process->setTimeout(120);
        $process->setInput(json_encode($fixture + [
            'widget' => $this->config['widget'], 'expected' => $expected,
            'changes' => $changes, 'escaped' => self::ESCAPED,
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_collection_create_defaults_and_sqlite_edits_initialize_and_change_visibility(): void
    {
        foreach (['checkbox', 'multipleSelect', 'checkboxButton', 'checkboxCard'] as $widget) {
            foreach ([
                'create' => [[], null, []],
                'default' => [['default' => ['alpha', 2]], null, ['alpha', '2']],
                'blocked default' => [['default' => ['blocked']], null, ['blocked']],
                'closure default' => [['default' => function () { return ['alpha']; }], null, ['alpha']],
                'selected edit' => [[], ['alpha', 2], ['alpha', '2']],
                'empty edit' => [[], [], []],
                'null edit' => [[], null, []],
                'escaped edit' => [[], [self::ESCAPED], [self::ESCAPED]],
            ] as $label => [$config, $value, $expected]) {
                $this->flushSession();
                $this->config = ['widget' => $widget] + $config;
                $path = '/conditional/create';
                if (str_contains($label, 'edit')) {
                    $record = ConditionalRecord::create(['title' => 'Stored', 'choices' => $value]);
                    $path = '/conditional/'.$record->id.'/edit';
                }
                $fixture = $this->get($path)->assertOk()->json();
                $result = $this->widget($fixture, $expected, $label === 'create');
                $this->assertSame($expected, $result['selected'], $widget.' '.$label);
            }
        }
    }

    public function test_zero_values_and_explicit_empty_old_input_override_defaults(): void
    {
        foreach (['checkbox', 'multipleSelect'] as $widget) {
            foreach ([[0], ['0'], [0, 2], ['0', 'alpha']] as $value) {
                $this->flushSession();
                $expected = array_map('strval', $value);
                $this->config = ['widget' => $widget, 'default' => $value];
                $this->widget($this->get('/conditional/create')->assertOk()->json(), $expected);
                $record = ConditionalRecord::create(['title' => 'Zero', 'choices' => $value]);
                $this->widget($this->get('/conditional/'.$record->id.'/edit')->assertOk()->json(), $expected);
                $this->app['session']->flashInput(['choices' => []]);
                $this->widget($this->get('/conditional/'.$record->id.'/edit')->assertOk()->json(), []);
            }
        }
        $this->config = ['widget' => 'multipleSelect', 'default' => ['alpha']];
        $this->app['session']->flashInput(['choices' => null]);
        $this->widget($this->get('/conditional/create')->assertOk()->json(), []);
    }

    public function test_validation_old_input_and_corrected_create_update_keep_storage_unchanged(): void
    {
        foreach (['checkbox', 'multipleSelect', 'checkboxButton', 'checkboxCard'] as $widget) {
            foreach ([[], ['alpha'], ['2']] as $expected) {
                $this->flushSession();
                $this->config = ['widget' => $widget, 'default' => ['blocked']];
                $submitted = array_merge($expected, ['']);
                $this->from('/conditional/create')->post('/conditional', [
                    'title' => '', 'choices' => $submitted,
                ])->assertRedirect('/conditional/create')->assertSessionHasErrors('title');
                $this->assertSame(array_merge($expected, [null]), $this->app['session']->getOldInput('choices'));
                $this->assertSame(0, ConditionalRecord::count());
                $result = $this->widget($this->get('/conditional/create')->assertOk()->json(), $expected);
                $this->post('/conditional', ['title' => 'Corrected', 'choices' => $result['submitted']])->assertRedirect();
                $record = ConditionalRecord::firstOrFail();
                $this->assertSame($expected, $record->choices);
                $this->flushSession();
                $this->widget($this->get('/conditional/'.$record->id.'/edit')->assertOk()->json(), $expected);
                $this->put('/conditional/'.$record->id, ['title' => 'Updated', 'choices' => ['alpha', '2', '']])->assertRedirect();
                $this->assertSame(['alpha', '2'], $record->fresh()->choices);
                $edit = '/conditional/'.$record->id.'/edit';
                $this->from($edit)->put('/conditional/'.$record->id, [
                    'title' => '', 'choices' => [''],
                ])->assertRedirect($edit)->assertSessionHasErrors('title');
                $this->assertSame(['alpha', '2'], $record->fresh()->choices);
                $cleared = $this->widget($this->get($edit)->assertOk()->json(), []);
                $this->put('/conditional/'.$record->id, [
                    'title' => 'Cleared', 'choices' => $cleared['submitted'],
                ])->assertRedirect();
                $this->assertSame([], $record->fresh()->choices);
                $record->delete();
            }
        }
    }

    public function test_scalar_controls_and_protected_hook_keep_their_existing_contract(): void
    {
        $this->app['router']->get('/conditional-scalar/{widget}/{value}', function ($widget, $value) {
            $value = $value === 'escaped' ? "quote'\\" : $value;
            $previous = Admin::$script;
            try {
                Admin::$script = [];
                $form = new Form(new ConditionalRecord());
                if ($widget === 'legacy') {
                    $field = new class('scalar') extends Select {
                        protected function getValueByJs() { return addslashes('0'); }
                        protected function getFormFrontValue() { return 'var checked = $(this).val();'; }
                    };
                    $field->setView('admin::form.select');
                    $form->pushField($field);
                } else {
                    $field = $form->{$widget}('scalar');
                }
                $field->options([0 => 'Zero', 2 => 'Two', "quote'\\" => 'Escaped'])->default($value);
                $field->when('0', function (Form $form) { $form->text('detail_scalar_equal'); });
                $field->when('in', ['2', 'other'], function (Form $form) { $form->text('detail_scalar_in'); });
                $field->when('>', '1', function (Form $form) { $form->text('detail_scalar_gt'); });
                $html = $form->render();
                return response()->json(['html' => $html, 'script' => implode("\n", Admin::$script),
                    'scalarInitial' => $widget === 'legacy' ? '0' : $value]);
            } finally {
                Admin::$script = $previous;
            }
        })->middleware('web');
        foreach (['select', 'radio'] as $widget) {
            $this->config = ['widget' => $widget];
            foreach (['0', '2', 'escaped'] as $value) {
                $expected = $value === 'escaped' ? "quote'\\" : $value;
                $this->widget($this->get('/conditional-scalar/'.$widget.'/'.$value)->assertOk()->json(), [$expected], true);
            }
        }
        $this->config = ['widget' => 'select'];
        $this->widget($this->get('/conditional-scalar/legacy/2')->assertOk()->json(), ['2'], true);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        $field = new class('scalar') extends Select {
            public function scalarJs() { return $this->getValueByJs(); }
        };
        $this->assertSame("quote\\'\\\\", $field->value("quote'\\")->scalarJs());
        $field->default('');
        $this->app['session']->flashInput(['scalar' => null]);
        $this->assertSame('', $field->scalarJs());
    }
}

class ConditionalRecord extends Model
{
    protected $table = 'conditional_records';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['choices' => 'array', 'other_choices' => 'array'];
}
