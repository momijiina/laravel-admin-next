<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class ListboxRemoteOptionsTest extends TestCase
{
    private array $options = [];
    private array $settings = [];
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('l', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/listbox-options', fn () => response()->json((object) $this->options));
            $router->get('/listbox/create', fn () => $this->renderForm());
            $router->get('/listbox/{id}/edit', fn ($id) => $this->renderForm($id));
            $router->post('/listbox', fn () => $this->form()->store());
            $router->put('/listbox/{id}', fn ($id) => $this->form()->update($id));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('listbox_remote_records', function ($table) {
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

    private function form(): Form
    {
        $form = new Form(new ListboxRemoteRecord());
        $form->setAction('/listbox');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $field = $form->listbox('choice');
        $field->options(($this->settings['static'] ?? false) ? $this->options : '/listbox-options', $this->settings['parameters'] ?? [], $this->settings['ajaxOptions'] ?? []);
        if (array_key_exists('default', $this->settings)) {
            $field->default($this->settings['default']);
        }
        if (isset($this->settings['widget'])) {
            $field->settings($this->settings['widget']);
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
        $fixture['options'] = (object) $this->get('/listbox-options')->assertOk()->json();
        $fixture['static'] = $this->settings['static'] ?? false;
        $process = new Process(['node', __DIR__.'/javascript/listbox-remote-options.cjs']);
        $process->setTimeout(120);
        $process->setInput(json_encode($fixture + $actions, JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function literalOptions(): array
    {
        return [
            'ordinary' => ['alpha', 'Alpha'],
            'zero' => ['0', 'Zero'],
            'numeric' => ['12', 'Twelve'],
            'leading zero' => ['001', 'Leading zero'],
            'literal null' => ['null', 'Literal null'],
            'single quote' => ["a'b", "A 'quote'"],
            'angle identifier' => ['a<b>', 'Angle identifier'],
            'quoted identifier' => ['a"b', 'Quoted identifier'],
            'entity identifier' => ['a&copy;b', 'Entity identifier'],
            'literal markup label' => ['markup', '<b>Bold</b> & <sales>'],
            'literal entity label' => ['entity', 'A &copy; &amp; B'],
            'unicode' => ['日本語', '選択肢 😀'],
        ];
    }

    #[DataProvider('literalOptions')]
    public function test_remote_options_preserve_literal_identifiers_labels_and_unchanged_saves(string $id, string $label): void
    {
        $this->options = [$id => $label, 'other' => 'Other'];
        foreach ([false, true] as $editing) {
            $this->settings = ['default' => [$id]];
            $record = $editing ? ListboxRemoteRecord::create(['title' => 'Original', 'choice' => [$id]]) : null;
            $fixture = $this->get($editing ? '/listbox/'.$record->id.'/edit' : '/listbox/create')->assertOk()->json();
            $result = $this->widget($fixture);
            $this->assertSame([['value' => $id, 'text' => $label, 'selected' => true, 'defaultSelected' => true], ['value' => 'other', 'text' => 'Other', 'selected' => false, 'defaultSelected' => false]], $result['options']);
            $this->assertSame([$id], $result['before']);
            $this->assertSame([$id], $result['after']);
            $this->assertSame(0, $result['unexpectedChildren']);
            $this->assertSame([$label], $result['selectedLabels']);
            parse_str($result['query'], $values);
            $this->assertSame([$id, ''], $values['choice']);
            $this->{$editing ? 'put' : 'post'}($editing ? '/listbox/'.$record->id : '/listbox', $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk()->assertJson(['status' => true]);
            $stored = $editing ? $record->fresh() : ListboxRemoteRecord::latest('id')->first();
            $this->assertSame([$id], $stored->choice);
        }
    }

    public function test_real_widget_changes_and_reopened_http_saves_preserve_literal_ids(): void
    {
        $this->options = ['0' => 'Zero', 'a"b' => '<b>Quoted</b>', 'a&copy;b' => 'A &amp; B', 'other' => 'Other'];
        foreach ([true, false] as $moveOnSelect) {
            $this->settings = ['widget' => ['moveOnSelect' => $moveOnSelect]];
            $record = ListboxRemoteRecord::create(['title' => 'Original', 'choice' => ['0', 'other']]);
            $page = '/listbox/'.$record->id.'/edit';
            $result = $this->widget($this->get($page)->assertOk()->json(), ['actions' => [
                ['type' => 'choose', 'values' => ['a"b', 'a&copy;b']],
                ['type' => 'remove', 'values' => ['other']],
            ]]);
            $this->assertSame(['0', 'other'], $result['before']);
            $this->assertSame(['0', 'a"b', 'a&copy;b'], $result['after']);
            $this->assertSame(['Zero', '<b>Quoted</b>', 'A &amp; B'], $result['selectedLabels']);
            parse_str($result['query'], $values);
            $this->put('/listbox/'.$record->id, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk()->assertJson(['status' => true]);
            $this->assertSame(['0', 'a"b', 'a&copy;b'], $record->fresh()->choice);
            $reopened = $this->widget($this->get($page)->assertOk()->json());
            $this->assertSame(['0', 'a"b', 'a&copy;b'], $reopened['before']);
            parse_str($reopened['query'], $again);
            $this->put('/listbox/'.$record->id, $again, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk()->assertJson(['status' => true]);
            $this->assertSame(['0', 'a"b', 'a&copy;b'], $record->fresh()->choice);
        }
    }

    public function test_native_reset_restores_remote_defaults_after_widget_changes(): void
    {
        $this->options = ['0' => 'Zero', 'a"b' => 'Quoted', 'other' => 'Other'];
        $this->settings = ['default' => ['0', 'a"b']];
        $fixture = $this->get('/listbox/create')->assertOk()->json();
        $result = $this->widget($fixture, ['actions' => [
            ['type' => 'clear'], ['type' => 'choose', 'values' => ['other']], ['type' => 'reset'], ['type' => 'refresh'],
        ]]);
        $this->assertSame(['0', 'a"b'], $result['after']);
        $this->assertSame(['Zero', 'Quoted'], $result['selectedLabels']);
        $this->assertSame([true, true, false], array_column($result['options'], 'defaultSelected'));
        parse_str($result['query'], $values);
        $this->assertSame(['0', 'a"b', ''], $values['choice']);
    }

    public function test_empty_selection_clear_and_ajax_widget_settings_keep_their_contract(): void
    {
        $this->options = ['0' => 'Zero', 'plain' => 'Plain'];
        $this->settings = ['parameters' => ['term' => 'a b'], 'widget' => ['selectorMinimalHeight' => 321, 'moveOnSelect' => false]];
        $result = $this->widget($this->get('/listbox/create')->assertOk()->json());
        $this->assertSame([], $result['before']);
        $this->assertSame([false, false], array_column($result['options'], 'defaultSelected'));
        $this->assertSame([['url' => '/listbox-options?term=a+b', 'type' => null, 'dataType' => null]], $result['requests']);
        $this->assertSame(321, $result['height']);
        $this->assertFalse($result['moveOnSelect']);
        $this->settings['default'] = ['0', 'plain'];
        $this->settings['ajaxOptions'] = ['url' => '/listbox-options?custom=1', 'type' => 'GET', 'dataType' => 'json'];
        $result = $this->widget($this->get('/listbox/create')->assertOk()->json(), ['actions' => [['type' => 'clear']]]);
        $this->assertSame(['0', 'plain'], $result['before']);
        $this->assertSame([], $result['after']);
        $this->assertSame('0,plain', $result['dataValue']);
        $this->assertSame([['url' => '/listbox-options?custom=1', 'type' => 'GET', 'dataType' => 'json']], $result['requests']);
        parse_str($result['query'], $values);
        $this->assertSame([''], $values['choice']);
        $this->post('/listbox', $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk()->assertJson(['status' => true]);
        $this->assertSame([], ListboxRemoteRecord::latest('id')->first()->choice);
    }

    public function test_static_options_keep_their_existing_blade_rendering_contract(): void
    {
        $this->options = ['a"b' => '<b>Quoted</b>', 'a&copy;b' => 'A &amp; B'];
        $this->settings = ['static' => true, 'default' => ['a"b']];
        $result = $this->widget($this->get('/listbox/create')->assertOk()->json());
        $this->assertSame(['a"b'], $result['before']);
        $this->assertSame(['<b>Quoted</b>'], $result['selectedLabels']);
        $this->assertSame(['A & B'], $result['availableLabels']);
        $this->assertSame(['a"b', 'a©b'], array_column($result['options'], 'value'));
        $this->assertSame([], $result['requests']);
    }

}

class ListboxRemoteRecord extends Model
{
    protected $table = 'listbox_remote_records';
    protected $guarded = [];
    protected $casts = ['choice' => 'array'];
    public $timestamps = false;
}
