<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Checkbox;
use Encore\Admin\Form\Field\Listbox;
use Encore\Admin\Form\Field\MultipleSelect;
use Encore\Admin\Form\Field\Select;
use Encore\Admin\Form\Field\Timezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class RemoteSelectOldInputTest extends TestCase
{
    private string $kind = 'select';
    private array $settings = [];
    private array $previousScript = [];
    private array $normalized = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/remote-options', function () {
                return response()->json($this->settings['remoteOptions'] ?? [
                    ['id' => 0, 'text' => 'Zero'],
                    ['id' => '1', 'text' => 'One'],
                    ['id' => 2, 'text' => 'Two'],
                    ['id' => 'alpha', 'text' => 'Alpha'],
                    ['id' => '001', 'text' => 'Leading zeros'],
                    ['id' => 'null', 'text' => 'Literal null'],
                    ['id' => 'a"<&', 'text' => 'Escaped identifier'],
                ]);
            });
            $router->get('/dependent-options', fn () => response()->json([['id' => '1', 'text' => 'One'], ['id' => '2', 'text' => 'Two']]));
            $router->get('/remote-select/create', fn () => $this->renderForm());
            $router->get('/remote-select/{id}/edit', fn ($id) => $this->renderForm($id));
            $router->post('/remote-select', function () {
                $this->normalized = request()->all();
                return $this->form()->store();
            });
            $router->put('/remote-select/{id}', function ($id) {
                $this->normalized = request()->all();
                return $this->form()->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('remote_select_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('choice')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        parent::tearDown();
    }

    private function model(): RemoteSelectRecord
    {
        return $this->kind === 'select' ? new RemoteSelectRecord() : new RemoteSelectMultiRecord();
    }

    private function form(): Form
    {
        $form = new Form($this->model());
        $form->setAction('/remote-select');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $field = $form->{$this->kind}('choice');
        foreach ($this->settings['config'] ?? [] as $key => $value) {
            $field->config($key, $value);
        }
        $field->options('/remote-options', $this->settings['parameters'] ?? [], $this->settings['ajaxOptions'] ?? []);
        foreach (['default', 'value'] as $method) {
            if (array_key_exists($method, $this->settings)) {
                $field->{$method}($this->settings[$method]);
            }
        }
        if ($this->settings['dependent'] ?? false) {
            $field->load('target', '/dependent-options');
            $form->select('target')->value('1')->options([1 => 'One', 2 => 'Two']);
        }
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

    private function widget(array $fixture, array $actions = []): array
    {
        $fixture['options'] = $this->get('/remote-options')->assertOk()->json();
        $process = new Process(['node', __DIR__.'/javascript/remote-select-old-input.cjs']);
        $process->setTimeout(120);
        $process->setInput(json_encode($fixture + $actions, JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_validation_retry_preserves_native_remote_selection_and_clear_on_create_and_edit(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            foreach ([false, true] as $editing) {
                foreach (['choose-two' => ['2'], 'clear' => [], 'zero' => ['0'], 'string' => ['alpha']] as $case => $choices) {
                    $this->flushSession();
                    $saved = $kind === 'select' ? '1' : ['1'];
                    $this->settings = ['default' => $saved];
                    $record = $editing ? $this->model()->create(['title' => 'Original', 'choice' => $saved]) : null;
                    $page = $editing ? '/remote-select/'.$record->id.'/edit' : '/remote-select/create';
                    $target = $editing ? '/remote-select/'.$record->id : '/remote-select';
                    $method = $editing ? 'put' : 'post';
                    $fixture = $this->get($page)->assertOk()->json();
                    $first = $this->widget($fixture, ['action' => $case === 'clear' ? 'clear' : 'choose', 'choice' => $choices]);
                    $this->assertSame(['1'], $first['before']);
                    $this->assertSame($choices, $first['displayed']);
                    parse_str($first['query'], $values);
                    $values['title'] = '';
                    $this->from($page)->{$method}($target, $values)->assertRedirect($page)->assertSessionHasErrors('title');
                    $old = $this->app['session']->getOldInput('choice');
                    $this->assertSame($kind === 'select' ? ($choices[0] ?? null) : array_merge($choices, [null]), $old);
                    if ($editing) {
                        $this->assertSame($saved, $record->fresh()->choice);
                    }
                    $retryFixture = $this->get($page)->assertOk()->json();
                    $retry = $this->widget($retryFixture);
                    $this->assertSame('1', $retry['dataValue'], 'The dependent-select metadata contract is unchanged.');
                    $this->assertSame(implode(',', $choices), $retry['remoteValue']);
                    $this->assertSame($choices, $retry['displayed']);
                    parse_str($retry['query'], $retryValues);
                    $retryValues['title'] = 'Corrected';
                    $this->{$method}($target, $retryValues, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $this->assertSame($kind === 'select' ? ($choices[0] ?? null) : array_merge($choices, [null]), $this->normalized['choice']);
                    $stored = $editing ? $record->fresh() : $this->model()->latest('id')->first();
                    $this->assertSame($kind === 'select' ? ($choices[0] ?? null) : $choices, $stored->choice);
                    $this->assertSame('Corrected', $stored->title);
                }
            }
        }
    }

    public function test_old_input_presence_null_markers_zero_and_string_identifiers(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            $this->settings = ['default' => $kind === 'select' ? '1' : ['1']];
            $matrix = $kind === 'select'
                ? [[null, []], ['', []], [0, ['0']], ['0', ['0']], [2, ['2']], ['alpha', ['alpha']], ['001', ['001']], ['null', ['null']], ['a"<&', ['a"<&']]]
                : [[null, []], [[null], []], [[], []], [[null, null], []], [[0, null], ['0']], [['0', '2', null], ['0', '2']], [['alpha', null], ['alpha']], [2, ['2']], [['a"<&', null], ['a"<&']]];
            foreach ($matrix as [$old, $expected]) {
                $this->flushSession();
                $fixture = $this->withSession(['_old_input' => ['choice' => $old]])->get('/remote-select/create')->assertOk()->json();
                $result = $this->widget($fixture);
                $this->assertSame(implode(',', $expected), $result['remoteValue']);
                $this->assertSame($expected, $result['displayed']);
                parse_str($result['query'], $values);
                $this->post('/remote-select', $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                    ->assertOk()->assertJson(['status' => true]);
                $this->assertSame($kind === 'select' ? ($expected[0] ?? null) : $expected, $this->model()->latest('id')->first()->choice);
            }
        }
    }

    public function test_absent_old_input_keeps_stored_values_defaults_and_remote_selected_overrides(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            foreach ([null, '0', '2', 'alpha'] as $value) {
                $this->flushSession();
                $value = $kind === 'multipleSelect' && $value !== null ? [$value] : $value;
                $this->settings = [];
                $record = $this->model()->create(['title' => 'Original', 'choice' => $value]);
                $fixture = $this->withSession(['_old_input' => ['unrelated' => '2']])
                    ->get('/remote-select/'.$record->id.'/edit')->assertOk()->json();
                $result = $this->widget($fixture);
                $this->assertNull($result['remoteValue']);
                $this->assertSame((array) $value, $result['displayed']);
            }
            foreach (['2', fn () => '2'] as $default) {
                $this->flushSession();
                $this->settings = ['default' => $default];
                $result = $this->widget($this->get('/remote-select/create')->assertOk()->json());
                $this->assertSame(['2'], $result['displayed']);
                $this->assertNull($result['remoteValue']);
            }
            $this->settings = ['remoteOptions' => [['id' => '2', 'text' => 'Server-selected', 'selected' => true]]];
            $this->flushSession();
            $result = $this->widget($this->get('/remote-select/create')->assertOk()->json());
            $this->assertSame(['2'], $result['displayed']);
            $this->assertNull($result['remoteValue']);
            // A server-selected option is only a fallback; an explicit retry clear wins.
            $result = $this->widget($this->withSession(['_old_input' => ['choice' => null]])->get('/remote-select/create')->assertOk()->json());
            $this->assertSame([], $result['displayed']);
            $this->assertSame('', $result['remoteValue']);
        }
    }

    public function test_remote_configuration_and_selected_options_do_not_override_retry_values(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            $this->flushSession();
            $this->settings = [
                'remoteOptions' => [['id' => '1', 'text' => 'One', 'selected' => true], ['id' => '2', 'text' => 'Two']],
                'parameters' => ['scope' => 'test'],
                'ajaxOptions' => ['url' => '/remote-options?explicit=1', 'type' => 'GET', 'dataType' => 'json'],
                'config' => ['allowClear' => false],
                'default' => '1',
            ];
            $old = $kind === 'select' ? '2' : ['2', null];
            $fixture = $this->withSession(['_old_input' => ['choice' => $old]])->get('/remote-select/create')->assertOk()->json();
            $result = $this->widget($fixture, ['url' => '/remote-options?explicit=1']);
            $this->assertSame(['2'], $result['displayed']);
            $this->assertFalse($result['allowClear']);
            $this->assertSame([['url' => '/remote-options?explicit=1', 'type' => 'GET', 'dataType' => 'json']], $result['requests']);
        }
    }

    public function test_explicit_select2_data_configuration_keeps_its_initial_override(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            $this->settings = ['config' => ['data' => [
                ['id' => 'alpha', 'text' => 'Configured', 'selected' => true],
                ['id' => '2', 'text' => 'Two'],
            ]]];
            $this->flushSession();
            $initial = $this->widget($this->get('/remote-select/create')->assertOk()->json());
            $this->assertSame(['alpha'], $initial['displayed']);
            $this->assertNull($initial['remoteValue']);
            foreach (['2' => ['2'], '' => []] as $old => $expected) {
                $this->flushSession();
                $fixture = $this->withSession(['_old_input' => ['choice' => $old]])->get('/remote-select/create')->assertOk()->json();
                $retry = $this->widget($fixture);
                $this->assertSame($expected, $retry['displayed']);
            }
        }
    }

    public function test_remote_retry_change_keeps_dependent_load_stored_value_contract(): void
    {
        $this->settings = ['dependent' => true, 'default' => '1'];
        $fixture = $this->withSession(['_old_input' => ['choice' => '2', 'target' => '2']])
            ->get('/remote-select/create')->assertOk()->json();
        $fixture['dependentOptions'] = $this->get('/dependent-options')->assertOk()->json();
        $result = $this->widget($fixture, ['requestCount' => 2]);
        $this->assertSame(['2'], $result['displayed']);
        // load() has always restored its target's data-value; this fix does not
        // silently redefine dependent-target old input or request sequencing.
        $this->assertSame(['1'], $result['dependent']);
        $this->assertSame('1', $result['dataValue']);
        $this->assertSame('2', $result['remoteValue']);
    }

    public function test_nonremote_preload_and_inherited_metadata_contracts_are_unchanged(): void
    {
        $this->withSession(['_old_input' => ['choice' => '2']])->get('/remote-select/create')->assertOk();
        // Keep a request/session active for direct field rendering, matching Blade's old() lookup.
        $this->app['session']->put('_old_input', ['choice' => '2']);
        $seen = [];
        $fields = [
            (new Select('choice'))->options([1 => 'One', 2 => 'Two']),
            (new MultipleSelect('choice'))->options([1 => 'One', 2 => 'Two']),
            (new Select('choice'))->options(function ($value) use (&$seen) { $seen[] = $value; return [1 => 'One', 2 => 'Two']; }),
            (new Select('choice'))->ajax('/search')->options([1 => 'One', 2 => 'Two']),
            (new Listbox('choice'))->options('/listbox-options'),
            (new Checkbox('choice'))->options([1 => 'One', 2 => 'Two']),
            (new Timezone('choice')),
        ];
        foreach ($fields as $field) {
            $this->app['session']->put('_old_input', ['choice' => $field instanceof MultipleSelect ? ['2'] : '2']);
            $field->value($field instanceof MultipleSelect ? ['1'] : '1');
            $html = $field->render();
            $this->assertStringNotContainsString('data-remote-value', $html);
            $this->assertStringContainsString('data-value="1"', $html);
        }
        $this->assertSame(['1'], $seen, 'Callable preload still receives the stored field value.');
        $record = RemoteSelectRecord::create(['title' => 'Model preload', 'choice' => '1']);
        $modelField = (new Select('choice'))->value($record->id)->model(RemoteSelectRecord::class, 'id', 'title');
        $html = $modelField->render();
        $this->assertStringContainsString('Model preload', $html);
        $this->assertStringNotContainsString('data-remote-value', $html);
    }

    public function test_invalid_nested_input_does_not_break_validation_error_redisplay(): void
    {
        foreach (['select', 'multipleSelect'] as $kind) {
            $this->kind = $kind;
            $this->settings = ['default' => '1'];
            $this->flushSession();
            $this->from('/remote-select/create')->post('/remote-select', [
                'title' => '', 'choice' => ['invalid' => ['2'], 'valid' => '0', null],
            ])->assertRedirect('/remote-select/create')->assertSessionHasErrors('title');
            set_error_handler(function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $fixture = $this->get('/remote-select/create')->assertOk()->json();
            } finally {
                restore_error_handler();
            }
            $result = $this->widget($fixture);
            $this->assertSame('0', $result['remoteValue']);
            $this->assertSame(['0'], $result['displayed']);
        }
    }

    public function test_nested_column_lookup_and_repeated_render_keep_retry_metadata_scoped(): void
    {
        $this->withSession(['_old_input' => ['preferences' => ['choice' => null]]])->get('/remote-select/create')->assertOk();
        $this->app['session']->put('_old_input', ['preferences' => ['choice' => null]]);
        $field = (new Select('preferences.choice'))->value('1')->options('/remote-options');
        $html = $field->render();
        $crawler = new Crawler($html);
        $this->assertSame('preferences[choice]', $crawler->filter('select')->attr('name'));
        $this->assertSame('1', $crawler->filter('select')->attr('data-value'));
        $this->assertSame('', $crawler->filter('select')->attr('data-remote-value'));
        $this->app['session']->forget('_old_input');
        $this->assertStringNotContainsString('data-remote-value', $field->render());
        $this->app['session']->put('_old_input', ['preferences' => ['other' => '2']]);
        $this->assertStringNotContainsString('data-remote-value', $field->render());
    }
}

class RemoteSelectRecord extends Model
{
    protected $table = 'remote_select_records';
    protected $guarded = [];
    public $timestamps = false;
}

class RemoteSelectMultiRecord extends RemoteSelectRecord
{
    protected $casts = ['choice' => 'array'];
}
