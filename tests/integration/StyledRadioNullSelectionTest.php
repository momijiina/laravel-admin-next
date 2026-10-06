<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Radio;
use Encore\Admin\Form\Field\RadioButton;
use Encore\Admin\Form\Field\RadioCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class StyledRadioNullSelectionTest extends TestCase
{
    private array $previousScript = [];
    private array $previousStyle = [];
    private string $widget = 'radioButton';
    private array $config = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/styled-radio/create', function () {
                return $this->renderForm();
            });
            $router->get('/styled-radio/{id}/edit', function ($id) {
                return $this->renderForm($id);
            });
            $router->post('/styled-radio', function () {
                return $this->form()->store();
            });
            $router->put('/styled-radio/{id}', function ($id) {
                return $this->form()->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        $this->previousStyle = Admin::$style;
        $this->app['view']->share('errors', new ViewErrorBag());
        Schema::create('styled_radio_selection_records', function ($table) {
            $table->increments('id');
            $table->integer('state')->nullable();
            $table->integer('other_state')->nullable();
            $table->integer('plain_state')->nullable();
            $table->text('button_choices')->nullable();
            $table->text('card_choices')->nullable();
            $table->string('title');
            foreach (['zero', 'one', 'other'] as $name) {
                $table->string('detail_'.$name)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        try {
            Admin::$script = $this->previousScript;
            Admin::$style = $this->previousStyle;
            $this->assertSame($this->previousScript, Admin::$script);
            $this->assertSame($this->previousStyle, Admin::$style);
        } finally {
            parent::tearDown();
        }
    }

    public static function widgets(): array
    {
        return ['button' => ['radioButton'], 'card' => ['radioCard']];
    }

    private function field(string $widget, array $options = [0 => 'Draft', 1 => 'Published']): Radio
    {
        $class = $widget === 'radioCard' ? RadioCard::class : RadioButton::class;
        return (new $class('state'))->options($options);
    }

    private function model(): Model
    {
        return !empty($this->config['boolean']) ? new StyledRadioSelectionBooleanRecord() : new StyledRadioSelectionRecord();
    }

    private function record($state): Model
    {
        return $this->model()->create([
            'state' => $state, 'other_state' => 1, 'plain_state' => 0,
            'button_choices' => ['0'], 'card_choices' => ['0'], 'title' => 'Original',
        ]);
    }

    private function form(): Form
    {
        $form = new Form($this->model());
        $form->setAction('/styled-radio');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $form->text('title')->rules('required')->default('Original');
        $options = !empty($this->config['reverse']) ? [1 => 'Published', 0 => 'Draft'] : [0 => 'Draft', 1 => 'Published'];
        $field = $form->{$this->widget}('state')->options($options);
        if (array_key_exists('default', $this->config)) {
            $field->default($this->config['default']);
        }
        if (!empty($this->config['required'])) {
            $field->required()->rules('required');
        }
        $other = $form->{$this->widget}('other_state')->options([1 => 'Other one', 0 => 'Other zero'])->default(1);
        if (!empty($this->config['conditional'])) {
            foreach ([0 => 'zero', 1 => 'one'] as $value => $name) {
                $field->when($value, function (Form $form) use ($name) {
                    $form->text('detail_'.$name)->default('Detail '.$name);
                });
            }
            $other->when(1, function (Form $form) {
                $form->text('detail_other')->default('Other detail');
            });
        }
        // Unchanged controls share the actual page and scripts with both styled radio groups.
        $form->radio('plain_state')->options([0 => 'Plain zero', 1 => 'Plain one'])->default(0);
        $form->checkboxButton('button_choices')->options([0 => 'Button zero', 1 => 'Button one'])->default([0]);
        $form->checkboxCard('card_choices')->options([0 => 'Card zero', 1 => 'Card one'])->default([0]);
        return $form;
    }

    private function renderForm($id = null)
    {
        $script = Admin::$script;
        $style = Admin::$style;
        try {
            Admin::$script = [];
            Admin::$style = [];
            $form = $this->form();
            $html = $id === null ? $form->render() : $form->edit($id)->render();
            return response()->json(['html' => $html, 'scriptHtml' => Admin::script()->render()]);
        } finally {
            Admin::$script = $script;
            Admin::$style = $style;
            $this->assertSame($script, Admin::$script);
            $this->assertSame($style, Admin::$style);
        }
    }

    private function assertSelection(array $expected, string $html): void
    {
        $crawler = new Crawler($html);
        $values = function ($input) { return $input->attr('value'); };
        $this->assertSame($expected, $crawler->filter('input[type="radio"][name="state"][checked]')->each($values));
        $this->assertSame($expected, $crawler->filter('.radio-group-toggle label.active input[name="state"]')->each($values));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="state"]'));
    }

    private function controls(array $fixture, array $expected, array $actions = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/styled-radio-null-selection.cjs']);
        $process->setInput(json_encode($fixture + [
            'expected' => $expected, 'actions' => $actions,
            'conditional' => !empty($this->config['conditional']),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        parse_str($result['query'], $values);
        $this->assertSame($result['selected'], array_key_exists('state', $values) ? [$values['state']] : []);
        $this->assertSame(['1'], $result['otherSelected']);
        $this->assertSame('0', $values['plain_state']);
        $this->assertSame(['0', ''], $values['button_choices']);
        $this->assertSame(['0', ''], $values['card_choices']);
        $required = (new Crawler($fixture['html']))->filter('input[name="state"][required]')->count() > 0;
        $this->assertSame(!$required || $result['selected'] !== [], $result['valid']);
        return $values;
    }

    private function save(?Model $record, array $values): Model
    {
        $method = $record === null ? 'post' : 'put';
        $this->{$method}('/styled-radio'.($record === null ? '' : '/'.$record->id), $values,
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertOk()->assertJson(['status' => true]);
        $saved = $record === null ? $this->model()->latest('id')->firstOrFail() : $record->fresh();
        $this->assertSame(1, $saved->other_state);
        $this->assertSame(0, $saved->plain_state);
        $this->assertSame(['0'], $saved->button_choices);
        $this->assertSame(['0'], $saved->card_choices);
        return $saved;
    }

    private function page(?Model $record): string
    {
        return '/styled-radio/'.($record === null ? 'create' : $record->id.'/edit');
    }

    #[DataProvider('widgets')]
    public function test_null_guard_retains_nonnull_loose_matching_and_active_parity(string $widget): void
    {
        $options = [0 => 'Draft', 1 => 'Published', '01' => 'Leading zero', -2 => 'Negative', '2.5' => 'Decimal', 'draft' => 'Word', '' => 'Empty'];
        foreach ([$options, array_reverse($options, true)] as $ordered) {
            foreach ([null, 0, '0', false, 1, '1', true, '', '01', -2, '2.5', 'draft'] as $value) {
                $field = $this->field($widget, $ordered);
                $field->fill(['state' => $value]);
                $expected = [];
                foreach ($ordered as $option => $label) {
                    if ($value !== null && $option == $value) {
                        $expected[] = (string) $option;
                    }
                }
                // Source attributes may intentionally match several loosely equivalent options.
                $this->assertSelection($expected, $field->render());
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_defaults_label_checked_fallback_and_old_input_precedence(string $widget): void
    {
        foreach ([0, 1] as $default) {
            foreach ([false, true] as $closure) {
                $field = $this->field($widget)->default($closure ? function () use ($default) { return $default; } : $default);
                $field->fill(['state' => null]);
                $this->assertSelection([(string) $default], $field->render());
                $field->fill(['state' => 1 - $default]);
                $this->assertSelection([(string) (1 - $default)], $field->render());
            }
        }
        foreach ([null, '', '0', 0, false, '1'] as $old) {
            $this->app['request']->setLaravelSession($this->app['session']->driver());
            $this->app['session']->flashInput(['state' => $old]);
            $expected = $old === null || $old === '' ? [] : [($old == 0 ? '0' : '1')];
            $field = $this->field($widget)->default(1);
            $field->fill(['state' => 1]);
            $this->assertSelection($expected, $field->render());
            // checked() takes labels and is additive only when the effective field value is null.
            $field = $this->field($widget)->checked('Published');
            $field->fill(['state' => null]);
            $this->assertSelection(array_values(array_unique(array_merge($expected, ['1']))), $field->render());
            $this->flushSession();
        }
        $this->assertSelection(['1'], $this->field($widget)->checked(['Draft', 'Published'])->render());
        $this->assertSelection([], $this->field($widget)->checked('1')->render());
        $field = $this->field($widget)->checked('Published');
        $field->fill(['state' => 0]);
        $this->assertSelection(['0'], $field->render());
    }

    #[DataProvider('widgets')]
    public function test_values_labels_and_required_attributes_remain_escaped(string $widget): void
    {
        $key = '"><script>alert(1)</script>';
        $label = '<b>Unsafe & label</b>';
        $field = $this->field($widget, [$key => $label])->required()->attribute('data-note', '\"<&');
        $field->fill(['state' => $key]);
        $html = $field->render();
        $this->assertSelection([$key], $html);
        $crawler = new Crawler($html);
        $this->assertCount(0, $crawler->filter('script, b'));
        $this->assertCount(1, $crawler->filter('input[required]'));
        $this->assertSame('\"<&', $crawler->filter('input')->attr('data-note'));
        $this->assertStringContainsString($label, $crawler->text());
    }

    #[DataProvider('widgets')]
    public function test_native_untouched_create_and_edit_preserve_null_zero_and_one(string $widget): void
    {
        $this->widget = $widget;
        foreach ([false, true] as $edit) {
            foreach ($edit ? [null, 0, 1] : [null] as $value) {
                $record = $edit ? $this->record($value) : null;
                $fixture = $this->get($this->page($record))->assertOk()->json();
                $expected = $value === null ? [] : [(string) $value];
                $this->assertSelection($expected, $fixture['html']);
                $saved = $this->save($record, $this->controls($fixture, $expected));
                $this->assertSame($value, $saved->state);
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_native_intentional_defaults_create_and_update_null_records(string $widget): void
    {
        $this->widget = $widget;
        foreach ([0, 1] as $default) {
            $this->config = ['default' => $default === 0 ? 0 : function () { return 1; }];
            foreach ([null, $this->record(null)] as $record) {
                $fixture = $this->get($this->page($record))->assertOk()->json();
                $saved = $this->save($record, $this->controls($fixture, [(string) $default]));
                $this->assertSame($default, $saved->state);
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_boolean_casts_preserve_null_false_and_true_through_native_submission(string $widget): void
    {
        $this->widget = $widget;
        $this->config = ['boolean' => true];
        foreach ([null, false, true] as $value) {
            $record = $this->record($value);
            $expected = $value === null ? [] : [$value ? '1' : '0'];
            $values = $this->controls($this->get($this->page($record))->assertOk()->json(), $expected);
            $saved = $this->save($record, $values);
            $this->assertSame($value, $saved->state);
            $this->assertSame($value === null ? null : (int) $value, $saved->getRawOriginal('state'));
        }
    }

    #[DataProvider('widgets')]
    public function test_validation_retry_preserves_explicit_old_null_and_zero_over_model_and_default(string $widget): void
    {
        $this->widget = $widget;
        $this->config = ['default' => 0];
        foreach ([false, true] as $edit) {
            foreach ([null, '0'] as $old) {
                $this->flushSession();
                $record = $edit ? $this->record(1) : null;
                $page = $this->page($record);
                $method = $edit ? 'put' : 'post';
                $target = '/styled-radio'.($edit ? '/'.$record->id : '');
                $fixture = $this->get($page)->assertOk()->json();
                $values = $this->controls($fixture, [$edit ? '1' : '0'], [['click' => '0']]);
                // Native radios cannot clear themselves; an explicit blank request tests the
                // existing middleware/old-input contract, without inventing a hidden marker.
                $values['state'] = $old === null ? '' : $old;
                $values['title'] = '';
                $count = $this->model()->count();
                $this->from($page)->{$method}($target, $values)->assertRedirect($page)->assertSessionHasErrors('title');
                $this->assertSame($old, $this->app['session']->getOldInput('state'));
                $this->assertSame($count, $this->model()->count());
                if ($record !== null) {
                    $this->assertSame(1, $record->fresh()->state);
                }
                $fixture = $this->get($page)->assertOk()->json();
                $expected = $old === null ? [] : ['0'];
                $this->assertSelection($expected, $fixture['html']);
                $values = $this->controls($fixture, $expected);
                $values['title'] = 'Corrected';
                $saved = $this->save($record, $values);
                $this->assertSame($old === null ? ($edit ? 1 : null) : 0, $saved->state);
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_omission_preserves_updates_while_explicit_blank_still_clears(string $widget): void
    {
        $this->widget = $widget;
        $record = $this->record(1);
        $fixture = $this->get($this->page($record))->assertOk()->json();
        $values = $this->controls($fixture, ['1'], [['omit' => true]]);
        $this->assertArrayNotHasKey('state', $values);
        $this->assertSame(1, $this->save($record, $values)->state);
        $values['state'] = '';
        $this->assertNull($this->save($record, $values)->state);
    }

    #[DataProvider('widgets')]
    public function test_required_native_constraints_and_present_null_server_validation(string $widget): void
    {
        $this->widget = $widget;
        $this->config = ['required' => true];
        foreach ([null, $this->record(null)] as $record) {
            $this->flushSession();
            $page = $this->page($record);
            $fixture = $this->get($page)->assertOk()->json();
            $this->assertSelection([], $fixture['html']);
            $values = $this->controls($fixture, []);
            $this->assertArrayNotHasKey('state', $values);
            $values['state'] = '';
            $method = $record === null ? 'post' : 'put';
            $target = '/styled-radio'.($record === null ? '' : '/'.$record->id);
            $count = $this->model()->count();
            $this->from($page)->{$method}($target, $values)->assertRedirect($page)->assertSessionHasErrors('state');
            $this->assertSame($count, $this->model()->count());
            $fixture = $this->get($page)->assertOk()->json();
            $this->assertSelection([], $fixture['html']);
            $values = $this->controls($fixture, [], [['click' => '0']]);
            $this->assertSame(0, $this->save($record, $values)->state);
        }
    }

    #[DataProvider('widgets')]
    public function test_repeated_label_and_card_body_clicks_keep_groups_and_conditions_independent(string $widget): void
    {
        $this->widget = $widget;
        $this->config = ['conditional' => true, 'reverse' => true];
        $record = $this->record(null);
        $fixture = $this->get($this->page($record))->assertOk()->json();
        $actions = [];
        foreach (['0', '0', '1', '1', '0'] as $index => $choice) {
            $actions[] = ['click' => $choice, 'body' => $index % 2 === 0];
        }
        $values = $this->controls($fixture, [], $actions);
        $this->assertSame(0, $this->save($record, $values)->state);
    }

    public function test_ordinary_radio_and_styled_checkbox_controls_keep_existing_behavior(): void
    {
        $this->widget = 'radio';
        foreach ([null, 0] as $value) {
            $record = $this->record($value);
            $expected = $value === null ? [] : ['0'];
            $fixture = $this->get($this->page($record))->assertOk()->json();
            $values = $this->controls($fixture, $expected, [['checkboxes' => true]]);
            $this->assertSame($value, $this->save($record, $values)->state);
        }
    }
}

class StyledRadioSelectionRecord extends Model
{
    protected $table = 'styled_radio_selection_records';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['button_choices' => 'array', 'card_choices' => 'array'];
}

class StyledRadioSelectionBooleanRecord extends StyledRadioSelectionRecord
{
    protected $casts = ['state' => 'boolean', 'button_choices' => 'array', 'card_choices' => 'array'];
}
