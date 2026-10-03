<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Checkbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class CheckboxZeroTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('checkbox_zero_records', function ($table) {
            $table->increments('id');
            $table->text('choices')->nullable();
            $table->string('title');
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('checkbox-zero/{storage}/{grouped}/{id?}', function ($storage, $grouped, $id = null) {
            $model = $this->record($storage);
            $form = new Form($model);
            $field = $this->configure($form->checkbox('choices'), (bool) $grouped);
            if ($id !== null) {
                $field->fill($model->findOrFail($id)->toArray());
            }
            return '<form method="post">'.$field->render().
                '<input name="title" value="Original"><button type="submit">Save</button></form>';
        })->middleware('web');
        $this->app['router']->post('checkbox-zero/{storage}/{grouped}/{id?}', function ($storage, $grouped, $id = null) {
            $form = new Form($this->record($storage));
            $this->configure($form->checkbox('choices'), (bool) $grouped);
            $form->text('title')->rules('required');
            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    private function record(string $storage): Model
    {
        return $storage === 'csv' ? new CheckboxZeroCsvRecord() : new CheckboxZeroRecord();
    }

    private function configure(Checkbox $field, bool $grouped, array $options = [0 => 'Zero', 1 => 'One']): Checkbox
    {
        return $grouped ? $field->groups(['Choices' => $options]) : $field->options($options);
    }

    private function checked(string $html): array
    {
        return (new Crawler($html))->filter('input[type="checkbox"][checked]')->each(function ($input) {
            return $input->attr('value');
        });
    }

    private function serialize(string $html, ?array $selection = null): array
    {
        $root = __DIR__.'/../../resources/assets';
        $process = new Process(['node', __DIR__.'/javascript/checkbox-zero.cjs']);
        $process->setInput(json_encode([
            'html' => $html, 'selection' => $selection,
            'jquery' => $root.'/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
            'icheck' => $root.'/AdminLTE/plugins/iCheck/icheck.min.js',
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        parse_str($result['query'], $values);
        $this->assertSame(array_merge($result['checked'], ['']), $values['choices']);
        return $values;
    }

    public function test_zero_arrays_and_csv_render_in_both_layouts(): void
    {
        foreach ([false, true] as $grouped) {
            foreach ([[[0], ['0']], [['0'], ['0']], [[0, 1], ['0', '1']], [['0', '1'], ['0', '1']],
                ['0', ['0']], ['0,1', ['0', '1']], [[1, '0'], ['0', '1']]] as [$value, $expected]) {
                $field = $this->configure(new Checkbox('choices'), $grouped);
                $field->fill(['choices' => $value]);
                $this->assertSame($expected, $this->checked($field->render()));
            }
        }
    }

    public function test_legacy_falsy_filter_and_nonempty_loose_comparisons_are_unchanged(): void
    {
        $options = [0 => 'Zero', 1 => 'One', '01' => 'Leading zero', -2 => 'Negative', '2.5' => 'Decimal', 'draft' => 'Draft', '' => 'Empty'];
        foreach ([false, true] as $grouped) {
            foreach ([null, [], [null], [false], [0.0], [''], [false, '', null], [1], ['01'], [-2], ['2.5'], ['draft'], [true]] as $value) {
                $field = $this->configure(new Checkbox('choices'), $grouped, $options);
                $field->fill(['choices' => $value]);
                $expected = [];
                foreach ($options as $option => $label) {
                    if (false !== array_search($option, array_filter($value ?? []))) {
                        $expected[] = (string) $option;
                    }
                }
                $this->assertSame($expected, $this->checked($field->render()));
            }
        }
    }

    public function test_checked_defaults_and_old_input_keep_existing_precedence(): void
    {
        foreach ([false, true] as $grouped) {
            foreach ([false, true] as $closure) {
                $field = $this->configure(new Checkbox('choices'), $grouped);
                $field->default($closure ? function () { return [0]; } : [0]);
                $field->fill(['choices' => null]);
                $this->assertSame(['0'], $this->checked($field->render()));
                $field->fill(['choices' => ['1']]);
                $this->assertSame(['1'], $this->checked($field->render()));
            }
            $field = $this->configure(new Checkbox('choices'), $grouped)->checked([0]);
            $field->fill(['choices' => null]);
            $this->assertSame(['0'], $this->checked($field->render()));
            $field->fill(['choices' => []]);
            $this->assertSame([], $this->checked($field->render()));
            foreach ([[['0', ''], ['0']], [[''], []], [[null], []]] as [$old, $expected]) {
                $this->app['request']->setLaravelSession($this->app['session']->driver());
                $this->app['session']->flashInput(['choices' => $old]);
                $field = $this->configure(new Checkbox('choices'), $grouped)->default([1])->checked([1]);
                $field->fill(['choices' => ['1']]);
                $this->assertSame($expected, $this->checked($field->render()));
                // Legacy checked() fallback is additive when the effective value is null.
                $field = $this->configure(new Checkbox('choices'), $grouped)->checked([1]);
                $field->fill(['choices' => null]);
                $this->assertSame(array_merge($expected, ['1']), $this->checked($field->render()));
                $this->flushSession();
            }
        }
    }

    public function test_untouched_rendered_edit_forms_preserve_zero_and_empty_storage(): void
    {
        $this->withoutExceptionHandling();
        foreach (['json', 'csv'] as $storage) {
            foreach ([0, 1] as $grouped) {
                foreach ([['0'], ['0', '1'], ['1'], []] as $choices) {
                    $record = $this->record($storage)->create(['choices' => $choices, 'title' => 'Original']);
                    $url = '/checkbox-zero/'.$storage.'/'.$grouped.'/'.$record->id;
                    $html = $this->get($url)->assertOk()->getContent();
                    $this->assertSame($choices, $this->checked($html));
                    $values = $this->serialize($html);
                    $this->assertSame(array_merge($choices, ['']), $values['choices']);
                    $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $this->assertSame($storage === 'csv' ? implode(',', $choices) : $choices, $record->fresh()->choices);
                }
            }
        }
    }

    public function test_create_and_clear_through_icheck_successful_controls(): void
    {
        foreach (['json', 'csv'] as $storage) {
            foreach ([0, 1] as $grouped) {
                $url = '/checkbox-zero/'.$storage.'/'.$grouped;
                foreach ([[], ['0'], ['0', '1']] as $choices) {
                    $html = $this->get($url)->assertOk()->getContent();
                    $this->assertSame([], $this->checked($html));
                    $values = $this->serialize($html, $choices);
                    $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $record = $this->record($storage)->latest('id')->firstOrFail();
                    $this->assertSame($storage === 'csv' ? implode(',', $choices) : $choices, $record->choices);
                    $edit = $url.'/'.$record->id;
                    $values = $this->serialize($this->get($edit)->assertOk()->getContent(), []);
                    $this->assertSame([''], $values['choices']);
                    $this->post($edit, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $this->assertSame($storage === 'csv' ? '' : [], $record->fresh()->choices);
                }
            }
        }
    }

    public function test_validation_redisplay_preserves_zero_and_clearing_over_model(): void
    {
        foreach ([0, 1] as $grouped) {
            foreach ([['0'], ['0', '1'], []] as $choices) {
                $record = CheckboxZeroRecord::create(['choices' => ['1'], 'title' => 'Original']);
                $url = '/checkbox-zero/json/'.$grouped.'/'.$record->id;
                $values = $this->serialize($this->get($url)->assertOk()->getContent(), $choices);
                $values['title'] = '';
                $this->from($url)->post($url, $values)->assertRedirect($url)->assertSessionHasErrors('title');
                $this->assertSame(['1'], $record->fresh()->choices);
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame($choices, $this->checked($html));
                $values = $this->serialize($html);
                $values['title'] = 'Corrected';
                $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                    ->assertOk()->assertJson(['status' => true]);
                $this->assertSame($choices, $record->fresh()->choices);
                $this->flushSession();
            }
        }
    }
}

class CheckboxZeroRecord extends Model
{
    protected $table = 'checkbox_zero_records';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['choices' => 'array'];
}

class CheckboxZeroCsvRecord extends CheckboxZeroRecord
{
    protected $casts = [];

    public function setChoicesAttribute($value)
    {
        $this->attributes['choices'] = is_array($value) ? implode(',', $value) : $value;
    }
}
