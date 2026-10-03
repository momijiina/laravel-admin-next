<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Radio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class NullableRadioTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('nullable_radio_records', function ($table) {
            $table->increments('id');
            $table->integer('state')->nullable();
            $table->string('title');
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('nullable-radio/{layout}/{mode}/{id?}', function ($layout, $mode, $id = null) {
            $form = new Form(new NullableRadioRecord());
            $field = $this->configure($form->radio('state'), $layout, $mode);
            if ($id !== null) {
                $field->fill(NullableRadioRecord::findOrFail($id)->toArray());
            }
            return '<form method="post">'.$field->render().
                '<input name="title" value="Original"><button type="submit">Save</button></form>';
        })->middleware('web');
        $this->app['router']->post('nullable-radio/{layout}/{mode}/{id?}', function ($layout, $mode, $id = null) {
            $form = new Form(new NullableRadioRecord());
            $this->configure($form->radio('state'), $layout, $mode);
            $form->text('title')->rules('required');
            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    private function configure(Radio $field, string $layout, string $mode = 'plain'): Radio
    {
        $field->options([0 => 'Draft', 1 => 'Published'])->{$layout}();
        if ($mode === 'required') {
            $field->required()->rules('required');
        } elseif ($mode === 'default') {
            $field->default(0);
        } elseif ($mode === 'closure') {
            $field->default(function () { return 1; });
        }
        return $field;
    }

    private function checked(string $html): array
    {
        return (new Crawler($html))->filter('input[type="radio"][checked]')->each(function ($input) {
            return $input->attr('value');
        });
    }

    private function serialize(string $html, ?array $selection = null): array
    {
        $root = __DIR__.'/../../resources/assets';
        $process = new Process(['node', __DIR__.'/javascript/radio-null.cjs']);
        $process->setInput(json_encode([
            'html' => $html, 'selection' => $selection,
            'jquery' => $root.'/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
            'icheck' => $root.'/AdminLTE/plugins/iCheck/icheck.min.js',
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $required = (new Crawler($html))->filter('input[type="radio"][required]')->count() > 0;
        $this->assertSame(!$required || $result['checked'] !== [], $result['valid']);
        parse_str($result['query'], $values);
        $this->assertSame($result['checked'], array_key_exists('state', $values) ? [$values['state']] : []);
        return $values;
    }

    private function save(string $url, array $values): void
    {
        $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertOk()->assertJson(['status' => true]);
    }

    public function test_null_guard_preserves_nonnull_loose_matching_in_both_layouts(): void
    {
        $options = [0 => 'Draft', 1 => 'Published', '01' => 'Leading zero', -2 => 'Negative', '2.5' => 'Decimal', 'draft' => 'Word', '' => 'Empty'];
        foreach (['inline', 'stacked'] as $layout) {
            foreach ([null, 0, '0', false, 1, '1', true, '', '01', -2, '2.5', 'draft'] as $value) {
                $field = (new Radio('state'))->options($options)->{$layout}();
                $field->fill(['state' => $value]);
                $expected = [];
                foreach ($options as $option => $label) {
                    if ($value !== null && $option == $value) {
                        $expected[] = (string) $option;
                    }
                }
                $this->assertSame($expected, $this->checked($field->render()));
            }
            foreach ([0, 1] as $value) {
                $model = new NullableRadioBooleanRecord();
                $model->setRawAttributes(['state' => $value]);
                $field = $this->configure(new Radio('state'), $layout);
                $field->fill($model->toArray());
                $this->assertSame([(string) $value], $this->checked($field->render()));
            }
        }
    }

    public function test_defaults_checked_fallback_and_old_input_precedence_are_preserved(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            foreach ([0, 1] as $default) {
                foreach ([false, true] as $closure) {
                    $field = $this->configure(new Radio('state'), $layout);
                    $field->default($closure ? function () use ($default) { return $default; } : $default);
                    $field->fill(['state' => null]);
                    $this->assertSame([(string) $default], $this->checked($field->render()));
                    $field->fill(['state' => 1 - $default]);
                    $this->assertSame([(string) (1 - $default)], $this->checked($field->render()));
                }
            }
            foreach ([null, '', '0', '1', false] as $old) {
                $this->app['request']->setLaravelSession($this->app['session']->driver());
                $this->app['session']->flashInput(['state' => $old]);
                $field = $this->configure(new Radio('state'), $layout)->default(1);
                $field->fill(['state' => null]);
                $expected = $old === null || $old === '' ? [] : [($old == 0 ? '0' : '1')];
                $this->assertSame($expected, $this->checked($field->render()));
                // The existing label-based checked() fallback remains additive for null values.
                $field = $this->configure(new Radio('state'), $layout)->checked('Published');
                $field->fill(['state' => null]);
                $this->assertSame(array_values(array_unique(array_merge($expected, ['1']))), $this->checked($field->render()));
                $this->flushSession();
            }
        }
    }

    public function test_null_zero_and_one_untouched_edit_roundtrips(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            foreach ([null, 0, 1] as $value) {
                $record = NullableRadioRecord::create(['state' => $value, 'title' => 'Original']);
                $url = '/nullable-radio/'.$layout.'/plain/'.$record->id;
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame($value === null ? [] : [(string) $value], $this->checked($html));
                $this->assertCount(0, (new Crawler($html))->filter('input[type="hidden"][name="state"]'));
                $this->save($url, $this->serialize($html));
                $this->assertSame($value, $record->fresh()->state);
            }
        }
    }

    public function test_blank_create_explicit_selection_and_defaults_use_actual_successful_controls(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            foreach (['plain' => null, 'default' => 0, 'closure' => 1] as $mode => $default) {
                $url = '/nullable-radio/'.$layout.'/'.$mode;
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame($default === null ? [] : [(string) $default], $this->checked($html));
                $this->save($url, $this->serialize($html));
                $this->assertSame($default, NullableRadioRecord::latest('id')->firstOrFail()->state);
                $record = NullableRadioRecord::create(['state' => null, 'title' => 'Original']);
                $edit = $url.'/'.$record->id;
                $html = $this->get($edit)->assertOk()->getContent();
                $this->assertSame($default === null ? [] : [(string) $default], $this->checked($html));
                $this->save($edit, $this->serialize($html));
                $this->assertSame($default, $record->fresh()->state);
            }
            foreach (['0', '1'] as $choice) {
                $url = '/nullable-radio/'.$layout.'/plain';
                $values = $this->serialize($this->get($url)->assertOk()->getContent(), [$choice]);
                $this->save($url, $values);
                $this->assertSame((int) $choice, NullableRadioRecord::latest('id')->firstOrFail()->state);
            }
        }
    }

    public function test_unchecking_omits_radio_and_preserves_existing_update_semantics(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            $record = NullableRadioRecord::create(['state' => 1, 'title' => 'Original']);
            $url = '/nullable-radio/'.$layout.'/plain/'.$record->id;
            $values = $this->serialize($this->get($url)->assertOk()->getContent(), []);
            $this->assertArrayNotHasKey('state', $values);
            $this->save($url, $values);
            $this->assertSame(1, $record->fresh()->state);
            // Explicit empty input still follows the application's normal null middleware.
            $this->save($url, ['state' => '', 'title' => 'Cleared']);
            $this->assertNull($record->fresh()->state);
        }
    }

    public function test_required_null_is_not_automatically_zero_on_create_or_edit(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            $record = NullableRadioRecord::create(['state' => null, 'title' => 'Original']);
            foreach (['', '/'.$record->id] as $suffix) {
                $url = '/nullable-radio/'.$layout.'/required'.$suffix;
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame([], $this->checked($html));
                $values = $this->serialize($html);
                $this->assertArrayNotHasKey('state', $values);
                $this->assertSame('0', $this->serialize($html, ['0'])['state']);
                if ($suffix !== '') {
                    $this->save($url, $values);
                    $this->assertNull($record->fresh()->state);
                }
                // Absent fields skip server validation; a present null exercises required rules.
                $values['state'] = '';
                $this->from($url)->post($url, $values)->assertRedirect($url)->assertSessionHasErrors('state');
                $this->assertSame([], $this->checked($this->get($url)->assertOk()->getContent()));
                $this->assertNull($record->fresh()->state);
                $this->assertSame($layout === 'inline' ? 1 : 2, NullableRadioRecord::count());
                $this->flushSession();
            }
        }
    }

    public function test_validation_redisplay_and_corrected_save_preserve_zero_or_null(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            foreach ([null, '0'] as $selection) {
                $record = NullableRadioRecord::create(['state' => $selection === null ? null : 1, 'title' => 'Original']);
                $url = '/nullable-radio/'.$layout.'/plain/'.$record->id;
                $values = $this->serialize($this->get($url)->assertOk()->getContent(), $selection === null ? [] : [$selection]);
                $values['title'] = '';
                $this->from($url)->post($url, $values)->assertRedirect($url)->assertSessionHasErrors('title');
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame($selection === null ? [] : [$selection], $this->checked($html));
                $values = $this->serialize($html);
                $values['title'] = 'Corrected';
                $this->save($url, $values);
                $this->assertSame($selection === null ? null : 0, $record->fresh()->state);
                $this->flushSession();
            }
        }
    }

    public function test_explicit_null_old_input_overrides_stored_value_and_default_after_validation(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            $record = NullableRadioRecord::create(['state' => 1, 'title' => 'Original']);
            $url = '/nullable-radio/'.$layout.'/default/'.$record->id;
            $this->from($url)->post($url, ['state' => '', 'title' => ''])
                ->assertRedirect($url)->assertSessionHasErrors('title');
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([], $this->checked($html));
            $values = $this->serialize($html);
            $this->assertArrayNotHasKey('state', $values);
            $values['title'] = 'Corrected';
            $this->save($url, $values);
            // Redisplayed null is omitted by native radios, so omission preserves storage.
            $this->assertSame(1, $record->fresh()->state);
            $this->flushSession();
        }
    }

    public function test_option_values_and_labels_remain_escaped_in_both_layouts(): void
    {
        foreach (['inline', 'stacked'] as $layout) {
            $key = '\"><script>alert(1)</script>';
            $label = '<b>Unsafe & label</b>';
            $field = (new Radio('state'))->options([$key => $label])->{$layout}();
            $field->fill(['state' => $key]);
            $html = $field->render();
            $crawler = new Crawler($html);
            $this->assertSame([$key], $this->checked($html));
            $this->assertCount(0, $crawler->filter('script, b'));
            $this->assertStringContainsString($label, $crawler->text());
            $this->assertCount(1, $crawler->filter($layout === 'inline' ? 'span.icheck label.radio-inline' : 'div.radio.icheck'));
        }
    }
}

class NullableRadioRecord extends Model
{
    protected $table = 'nullable_radio_records';
    protected $guarded = [];
    public $timestamps = false;
}

class NullableRadioBooleanRecord extends NullableRadioRecord
{
    protected $casts = ['state' => 'boolean'];
}
