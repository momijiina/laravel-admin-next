<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\CheckboxButton;
use Encore\Admin\Form\Field\CheckboxCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class StyledCheckboxZeroTest extends TestCase
{
    private array $previousScript = [];
    private array $previousStyle = [];
    private array $defaults = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/styled-checkbox/{widget}/{storage}/create', function ($widget, $storage) {
                return $this->renderForm($widget, $storage);
            });
            $router->get('/styled-checkbox/{widget}/{storage}/{id}/edit', function ($widget, $storage, $id) {
                return $this->renderForm($widget, $storage, $id);
            });
            $router->post('/styled-checkbox/{widget}/{storage}', function ($widget, $storage) {
                return $this->form($widget, $storage)->store();
            });
            $router->put('/styled-checkbox/{widget}/{storage}/{id}', function ($widget, $storage, $id) {
                return $this->form($widget, $storage)->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        $this->previousStyle = Admin::$style;
        $this->app['view']->share('errors', new ViewErrorBag());
        Schema::create('styled_checkbox_records', function ($table) {
            $table->increments('id');
            $table->text('choices')->nullable();
            $table->text('other_choices')->nullable();
            $table->string('title');
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        Admin::$style = $this->previousStyle;
        parent::tearDown();
    }

    private function record(string $storage): Model
    {
        return $storage === 'csv' ? new StyledCheckboxCsvRecord() : new StyledCheckboxRecord();
    }

    private function field(string $widget, array $options = [0 => 'Zero', 1 => 'One']): CheckboxButton
    {
        $class = $widget === 'checkboxCard' ? CheckboxCard::class : CheckboxButton::class;
        return (new $class('choices'))->options($options);
    }

    private function form(string $widget, string $storage): Form
    {
        $form = new Form($this->record($storage));
        $form->setAction('/styled-checkbox/'.$widget.'/'.$storage);
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $form->{$widget}('choices')->options([0 => 'Zero', 1 => 'One'])->default($this->defaults);
        $form->{$widget}('other_choices')->options([1 => 'Other one', 0 => 'Other zero'])->default([1]);
        return $form;
    }

    private function renderForm(string $widget, string $storage, $id = null)
    {
        $previous = Admin::$script;
        try {
            Admin::$script = [];
            $form = $this->form($widget, $storage);
            $html = $id === null ? $form->render() : $form->edit($id)->render();
            // Include the production ready wrapper and script deduplication.
            return response()->json(['html' => $html, 'scriptHtml' => Admin::script()->render()]);
        } finally {
            Admin::$script = $previous;
        }
    }

    private function assertSelection(array $expected, string $html): void
    {
        $crawler = new Crawler($html);
        $this->assertSame($expected, $crawler->filter('input[type="checkbox"][checked]')->each(function ($input) {
            return $input->attr('value');
        }));
        $this->assertSame($expected, $crawler->filter('.checkbox-group-toggle label.active input')->each(function ($input) {
            return $input->attr('value');
        }));
    }

    private function controls(array $fixture, array $expected, array $selections = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/styled-checkbox-zero.cjs']);
        $process->setInput(json_encode($fixture + ['expected' => $expected, 'selections' => $selections], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        parse_str($result['query'], $values);
        $this->assertSame(array_merge($result['selected'], ['']), $values['choices']);
        return $values;
    }

    private function assertStored(string $storage, array $choices, Model $record): void
    {
        $this->assertSame($storage === 'csv' ? implode(',', $choices) : $choices, $record->fresh()->choices);
    }

    public function test_zero_arrays_csv_and_sparse_values_match_active_labels(): void
    {
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach ([[[0], ['0']], [['0'], ['0']], [[0, 1], ['0', '1']], [['0', '1'], ['0', '1']],
                ['0', ['0']], ['0,1', ['0', '1']], [[1, '0'], ['0', '1']], [[4 => 0, 9 => 1], ['0', '1']]] as [$value, $expected]) {
                $field = $this->field($widget);
                $field->fill(['choices' => $value]);
                $this->assertSelection($expected, $field->render());
            }
            $field = $this->field($widget, [1 => 'One', 0 => 'Zero']);
            $field->fill(['choices' => [0, 1]]);
            $this->assertSelection(['1', '0'], $field->render());
        }
    }

    public function test_legacy_falsy_filter_and_loose_nonempty_matching_are_unchanged(): void
    {
        $options = [0 => 'Zero', 1 => 'One', '01' => 'Leading zero', -2 => 'Negative', '2.5' => 'Decimal', 'draft' => 'Draft', '' => 'Empty'];
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach ([null, [], [null], [false], [0.0], [''], [false, '', null], [1], ['01'], [-2], ['2.5'], ['draft'], [true]] as $value) {
                $field = $this->field($widget, $options);
                if ($value !== null) {
                    $field->value($value);
                }
                $expected = [];
                foreach ($options as $option => $label) {
                    if (false !== array_search($option, array_filter($value ?? []))) {
                        $expected[] = (string) $option;
                    }
                }
                $this->assertSelection($expected, $field->render());
            }
        }
    }

    public function test_defaults_checked_and_old_input_keep_existing_precedence(): void
    {
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach ([false, true] as $closure) {
                $field = $this->field($widget)->default($closure ? function () { return [0]; } : [0]);
                $this->assertSelection(['0'], $field->render());
                $field->fill(['choices' => ['1']]);
                $this->assertSelection(['1'], $field->render());
            }
            $field = $this->field($widget)->checked([0]);
            $this->assertSelection(['0'], $field->render());
            $field->fill(['choices' => []]);
            $this->assertSelection([], $field->render());
            foreach ([[['0', ''], ['0']], [[0, null], ['0']], [[4 => '0', 9 => null], ['0']], [[''], []], [[null], []], [[], []]] as [$old, $expected]) {
                $this->app['request']->setLaravelSession($this->app['session']->driver());
                $this->app['session']->flashInput(['choices' => $old]);
                $field = $this->field($widget)->default([1])->checked([1]);
                $field->fill(['choices' => ['1']]);
                $this->assertSelection($expected, $field->render());
                // Keep the existing additive checked() fallback when the effective value is null.
                $field = $this->field($widget)->checked([1]);
                $this->assertSelection(array_merge($expected, ['1']), $field->render());
                $this->flushSession();
            }
        }
    }

    public function test_untouched_http_edits_preserve_zero_json_and_csv(): void
    {
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach (['json', 'csv'] as $storage) {
                foreach ([[[0], ['0']], [['0'], ['0']], [[0, 1], ['0', '1']], [[1, '0'], ['0', '1']],
                    [['1'], ['1']], [[], []], [null, []]] as [$choices, $expected]) {
                    $record = $this->record($storage)->create(['choices' => $choices, 'title' => 'Original']);
                    $url = '/styled-checkbox/'.$widget.'/'.$storage.'/'.$record->id;
                    $values = $this->controls($this->get($url.'/edit')->assertOk()->json(), $expected);
                    $this->put($url, $values)->assertRedirect();
                    $this->assertStored($storage, $expected, $record);
                }
            }
        }
    }

    public function test_shipped_label_clicks_create_clear_and_repeat_without_affecting_another_field(): void
    {
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach (['json', 'csv'] as $storage) {
                foreach ([[], ['0'], ['0', '1']] as $choices) {
                    $url = '/styled-checkbox/'.$widget.'/'.$storage;
                    $values = $this->controls($this->get($url.'/create')->assertOk()->json(), [], [['0'], [], ['0', '1'], ['1'], $choices]);
                    $this->post($url, $values)->assertRedirect();
                    $record = $this->record($storage)->latest('id')->firstOrFail();
                    $this->assertStored($storage, $choices, $record);
                    $values = $this->controls($this->get($url.'/'.$record->id.'/edit')->assertOk()->json(), $choices, [[]]);
                    $this->assertSame([''], $values['choices']);
                    $this->put($url.'/'.$record->id, $values)->assertRedirect();
                    $this->assertStored($storage, [], $record);
                }
            }
        }
    }

    public function test_validation_zero_and_clear_override_defaults_and_stored_values_on_retry(): void
    {
        $this->defaults = [1];
        foreach (['checkboxButton', 'checkboxCard'] as $widget) {
            foreach (['json', 'csv'] as $storage) {
                foreach ([false, true] as $edit) {
                    foreach ([['0'], ['0', '1'], []] as $choices) {
                        $this->flushSession();
                        $record = $edit ? $this->record($storage)->create(['choices' => ['1'], 'title' => 'Original']) : null;
                        $base = '/styled-checkbox/'.$widget.'/'.$storage;
                        $page = $edit ? $base.'/'.$record->id.'/edit' : $base.'/create';
                        $target = $edit ? $base.'/'.$record->id : $base;
                        $method = $edit ? 'put' : 'post';
                        $count = $this->record($storage)->count();
                        $values = $this->controls($this->get($page)->assertOk()->json(), ['1'], [$choices]);
                        $values['title'] = '';
                        $this->from($page)->{$method}($target, $values)->assertRedirect($page)->assertSessionHasErrors('title');
                        $this->assertSame(array_merge($choices, [null]), $this->app['session']->getOldInput('choices'));
                        $this->assertSame($count, $this->record($storage)->count());
                        if ($edit) {
                            $this->assertStored($storage, ['1'], $record);
                        }
                        $values = $this->controls($this->get($page)->assertOk()->json(), $choices);
                        $values['title'] = 'Corrected';
                        $this->{$method}($target, $values)->assertRedirect();
                        $record = $record ?? $this->record($storage)->latest('id')->firstOrFail();
                        $this->assertStored($storage, $choices, $record);
                    }
                }
            }
        }
    }
}

class StyledCheckboxRecord extends Model
{
    protected $table = 'styled_checkbox_records';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['choices' => 'array', 'other_choices' => 'array'];
}

class StyledCheckboxCsvRecord extends StyledCheckboxRecord
{
    protected $casts = ['other_choices' => 'array'];

    public function setChoicesAttribute($value)
    {
        $this->attributes['choices'] = is_array($value) ? implode(',', $value) : $value;
    }
}
