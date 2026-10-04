<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\MultipleSelect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class MultipleSelectNullOldInputTest extends TestCase
{
    private array $normalized = [];
    private array $config = [];
    private array $previousScript = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/multiple-choice/create', function () {
                $previousScript = Admin::$script;
                try {
                    Admin::$script = [];
                    $html = $this->form()->render();
                    return response()->json(['html' => $html, 'script' => implode("\n", Admin::$script)]);
                } finally {
                    Admin::$script = $previousScript;
                }
            });
            $router->post('/multiple-choice/store', function () {
                $this->normalized = request()->all();
                return $this->form()->store();
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('multiple_choice_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('choice');
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new MultipleChoiceRecord());
        $form->setAction('/multiple-choice/store');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required');
        $field = $form->multipleSelect('choice', 'Choice')->options([0 => 'Zero', 1 => 'One', 2 => 'Two'])
            ->placeholder('Choose values')->attribute('data-probe', 'fixture');
        if (array_key_exists('default', $this->config)) {
            $field->default($this->config['default']);
        }
        if (array_key_exists('value', $this->config)) {
            $field->value($this->config['value']);
        }
        return $form;
    }

    private function widget(array $fixture, string $mode = 'inspect', array $choice = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/multiple-select-null-old-input.cjs']);
        $process->setTimeout(120);
        $process->setInput(json_encode($fixture + compact('mode', 'choice'), JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_hidden_marker_survives_validation_and_corrected_retry_without_adding_zero(): void
    {
        foreach ([
            'clear-all' => [[], []],
            'remove-last' => [[], []],
            'nonzero' => [['1'], ['1']],
            'string-zero' => [['0'], ['0']],
            'integer-zero' => [[0], ['0']],
            'mixed-zero' => [['0', '1'], ['0', '1']],
        ] as $label => [$choice, $expected]) {
            $this->flushSession();
            $this->config = ['value' => [1], 'default' => [2]];
            $initial = $this->get('/multiple-choice/create')->assertOk()->json();
            $mode = in_array($label, ['clear-all', 'remove-last']) ? $label : 'choose';
            $before = $this->widget($initial, $mode, $choice);
            $this->assertSame(['1'], $before['selected'], $label);
            $this->assertSame($expected, $before['visibleAfter'], $label);
            $this->assertSame(array_merge($expected, ['']), $before['submitted'], $label);
            // Native browser controls always send strings. Keep a separate integer
            // control at the HTTP boundary to protect the field's PHP contract too.
            $submitted = $label === 'integer-zero' ? [0, ''] : $before['submitted'];
            $this->from('/multiple-choice/create')->post('/multiple-choice/store', [
                'title' => '', 'choice' => $submitted,
            ])->assertRedirect('/multiple-choice/create')->assertSessionHasErrors('title');
            $normalized = $label === 'integer-zero' ? [0, null] : array_merge($expected, [null]);
            $this->assertSame(['title' => null, 'choice' => $normalized], $this->normalized, $label);
            $this->assertSame($normalized, $this->app['session']->getOldInput('choice'), $label);
            $this->assertSame(0, MultipleChoiceRecord::count(), $label);
            $after = $this->widget($this->get('/multiple-choice/create')->assertOk()->json());
            $this->assertSame($expected, $after['selected'], $label);
            $this->assertSame(array_merge($expected, ['']), $after['submitted'], $label);
            $this->from('/multiple-choice/create')->post('/multiple-choice/store', [
                'title' => 'Corrected title', 'choice' => $after['submitted'],
            ])->assertRedirect();
            $this->assertSame(1, MultipleChoiceRecord::count(), $label);
            $record = MultipleChoiceRecord::firstOrFail();
            $this->assertSame($expected, $record->choice, $label);
            $this->assertSame('Corrected title', $record->title, $label);
            $record->delete();
        }
    }

    public function test_value_defaults_and_explicit_old_input_keep_their_precedence(): void
    {
        foreach ([
            'unset' => [[], [], []],
            'nonzero default' => [['default' => [2]], [], ['2']],
            'integer zero default' => [['default' => [0]], [], ['0']],
            'string zero default' => [['default' => ['0']], [], ['0']],
            'closure zero default' => [['default' => function () { return [0]; }], [], ['0']],
            'integer zero value' => [['value' => [0], 'default' => [2]], [], ['0']],
            'string zero value' => [['value' => ['0'], 'default' => [2]], [], ['0']],
            'explicit empty value' => [['value' => [], 'default' => [2]], [], []],
            'old empty' => [['value' => [1], 'default' => [2]], ['choice' => []], []],
            'old top-level null' => [['value' => [1], 'default' => [2]], ['choice' => null], []],
            'old null entry' => [['value' => [1], 'default' => [2]], ['choice' => [null]], []],
            'old integer zero' => [['value' => [1], 'default' => [2]], ['choice' => [0, null]], ['0']],
            'old string zero' => [['value' => [1], 'default' => [2]], ['choice' => ['0', null]], ['0']],
            'old nonzero' => [['value' => [0], 'default' => [2]], ['choice' => ['1', null]], ['1']],
        ] as $label => [$config, $old, $expected]) {
            $this->flushSession();
            $this->config = $config;
            $this->app['session']->flashInput($old);
            $result = $this->widget($this->get('/multiple-choice/create')->assertOk()->json());
            $this->assertSame($expected, $result['selected'], $label);
            $this->assertSame(array_merge($expected, ['']), $result['submitted'], $label);
        }
    }

    public function test_only_null_members_are_excluded_from_legacy_loose_option_matching(): void
    {
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        $this->app['view']->share('errors', new ViewErrorBag());
        $options = [0 => 'Zero', 1 => 'One', -2 => 'Negative', '01' => 'Leading zero',
            '2.5' => 'Decimal', 'draft' => 'Draft', '' => 'Explicit empty'];
        foreach ([null, 0, '0', false, 1, '1', true, -2, '-2', '01', 2.5, '2.5', 'draft', '', 'missing'] as $input) {
            foreach ([[$input], [null, $input], [$input, null]] as $values) {
                $expected = [];
                foreach ($options as $key => $label) {
                    if ($input !== null && $key == $input) {
                        $expected[] = (string) $key;
                    }
                }
                $this->app['session']->flashInput(['choice' => $values]);
                $field = (new MultipleSelect('choice'))->options($options)->value([2])->default([1]);
                $crawler = new Crawler($field->render());
                $actual = $crawler->filter('option[selected]')->each(function ($option) {
                    return $option->attr('value');
                });
                $this->assertSame($expected, $actual, var_export($values, true));
            }
        }
    }
}

class MultipleChoiceRecord extends Model
{
    protected $table = 'multiple_choice_records';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['choice' => 'array', 'title' => 'string'];
}
