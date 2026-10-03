<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class NullableSelectTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('nullable_select_records', function ($table) {
            $table->increments('id');
            $table->integer('state')->nullable();
            $table->string('title');
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('nullable-select/{cast}/{grouped}/{id?}', function ($cast, $grouped, $id = null) {
            $model = $this->record($cast);
            $form = new Form($model);
            $field = $form->select('state');
            $this->configureOptions($field, (bool) $grouped);
            if ($id !== null) {
                $field->fill($model->findOrFail($id)->toArray());
            }
            return '<form method="post">'.$field->render().
                '<input name="title" value="Original"><button type="submit">Save</button></form>';
        })->middleware('web');
        $this->app['router']->post('nullable-select/{cast}/{grouped}/{id?}', function ($cast, $grouped, $id = null) {
            $form = new Form($this->record($cast));
            $this->configureOptions($form->select('state')->rules('nullable|in:0,1'), (bool) $grouped);
            $form->text('title')->rules('required');
            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    private function record(string $cast): Model
    {
        return $cast === 'boolean' ? new NullableSelectBooleanRecord() : new NullableSelectRecord();
    }

    private function configureOptions(Select $field, bool $grouped, array $options = [0 => 'Draft', 1 => 'Published']): Select
    {
        return $grouped ? $field->groups([['label' => 'Status', 'options' => $options]]) : $field->options($options);
    }

    private function renderField($value, bool $grouped, array $options = [0 => 'Draft', 1 => 'Published']): Crawler
    {
        $field = $this->configureOptions(new Select('state'), $grouped, $options);
        $field->fill(['state' => $value]);
        return $this->crawler($field->render());
    }

    private function crawler(string $html): Crawler
    {
        return new Crawler('<form method="post">'.$html.'<button type="submit">Save</button></form>', 'http://localhost/');
    }

    private function selected(Crawler $crawler): array
    {
        return $crawler->filter('select[name="state"] option[selected]')->each(function ($option) {
            return $option->attr('value');
        });
    }

    public function test_null_renders_blank_while_zero_and_boolean_values_keep_their_selection(): void
    {
        foreach ([false, true] as $grouped) {
            foreach ([[null, [], ''], [0, ['0'], '0'], ['0', ['0'], '0'], [false, ['0'], '0'],
                [1, ['1'], '1'], ['1', ['1'], '1'], [true, ['1'], '1']] as [$value, $selected, $submitted]) {
                $crawler = $this->renderField($value, $grouped);
                $this->assertSame($selected, $this->selected($crawler));
                $this->assertSame($submitted, $crawler->selectButton('Save')->form()->getPhpValues()['state']);
                $this->assertSame($grouped ? 1 : 0, $crawler->filter('optgroup[label="Status"]')->count());
            }
        }
    }

    public function test_non_null_option_comparison_retains_legacy_loose_equivalence(): void
    {
        $options = [0 => 'Zero', 1 => 'One', -2 => 'Negative', '01' => 'Leading zero',
            '2.5' => 'Decimal', 'draft' => 'Draft', '' => 'Explicit empty'];
        foreach ([false, true] as $grouped) {
            foreach ([0, '0', false, 1, '1', true, -2, '-2', '01', 2.5, '2.5', 'draft', '', 'missing'] as $value) {
                $expected = [];
                foreach ($options as $key => $label) {
                    // Deliberately characterize the existing public comparison contract.
                    if ($key == $value) {
                        $expected[] = (string) $key;
                    }
                }
                $this->assertSame($expected, $this->selected($this->renderField($value, $grouped, $options)));
            }
        }
    }

    public function test_explicit_defaults_and_placeholder_remain_effective(): void
    {
        foreach ([false, true] as $grouped) {
            foreach ([null, 0, '0', false, 1, '1', true] as $default) {
                foreach ([false, true] as $closure) {
                    $field = $this->configureOptions(new Select('state'), $grouped);
                    $field->default($closure ? function () use ($default) { return $default; } : $default);
                    $field->config('placeholder', ['id' => '', 'text' => 'Choose a status']);
                    $field->fill(['state' => null]);
                    $crawler = $this->crawler($field->render());
                    $expected = $default === null ? [] : [(string) (int) (bool) $default];
                    $this->assertSame($expected, $this->selected($crawler));
                    $this->assertStringContainsString('"placeholder":{"id":"","text":"Choose a status"}', $field->getScript());
                    $field->fill(['state' => 0]);
                    $this->assertSame(['0'], $this->selected($this->crawler($field->render())));
                }
            }
        }
    }

    public function test_old_null_and_zero_override_explicit_default_and_model_value(): void
    {
        foreach ([false, true] as $grouped) {
            foreach ([null, '0'] as $old) {
                $this->app['request']->setLaravelSession($this->app['session']->driver());
                $this->app['session']->flashInput(['state' => $old]);
                $field = $this->configureOptions(new Select('state'), $grouped)->default(1);
                $field->fill(['state' => 1]);
                $crawler = $this->crawler($field->render());
                $this->assertSame($old === null ? [] : ['0'], $this->selected($crawler));
                $this->assertSame($old ?? '', $crawler->selectButton('Save')->form()->getPhpValues()['state']);
                $this->flushSession();
            }
        }
    }

    public function test_untouched_rendered_forms_preserve_null_zero_and_boolean_cast_storage(): void
    {
        $this->withoutExceptionHandling();
        foreach (['integer', 'boolean'] as $cast) {
            foreach ([0, 1] as $grouped) {
                foreach ([null, 0, 1] as $stored) {
                    $record = $this->record($cast)->create(['state' => $stored, 'title' => 'Original']);
                    $url = '/nullable-select/'.$cast.'/'.$grouped.'/'.$record->id;
                    $response = $this->get($url)->assertOk();
                    $crawler = new Crawler($response->getContent(), 'http://localhost'.$url);
                    $values = $crawler->selectButton('Save')->form()->getPhpValues();
                    $this->assertSame($stored === null ? [] : [(string) $stored], $this->selected($crawler));
                    $this->assertSame($stored === null ? '' : (string) $stored, $values['state']);
                    $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $this->assertSame($stored, $record->fresh()->getRawOriginal('state'));
                    $this->assertSame($stored === null || $cast === 'integer' ? $stored : (bool) $stored, $record->fresh()->state);
                }
            }
        }
    }

    public function test_blank_create_form_stores_null_and_explicit_zero_remains_selectable(): void
    {
        $this->withoutExceptionHandling();
        foreach (['integer', 'boolean'] as $cast) {
            foreach ([0, 1] as $grouped) {
                $url = '/nullable-select/'.$cast.'/'.$grouped;
                $response = $this->get($url)->assertOk();
                $crawler = new Crawler($response->getContent(), 'http://localhost'.$url);
                $form = $crawler->selectButton('Save')->form();
                $this->assertSame([], $this->selected($crawler));
                $this->assertSame('', $form->getPhpValues()['state']);
                foreach ([null, '0'] as $selection) {
                    if ($selection !== null) {
                        $form['state']->select($selection);
                    }
                    $this->post($url, $form->getPhpValues(), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $record = $this->record($cast)->latest('id')->firstOrFail();
                    $this->assertSame($selection === null ? null : 0, $record->getRawOriginal('state'));
                }
            }
        }
    }

    public function test_validation_redisplay_preserves_cleared_null_and_zero_over_stored_value(): void
    {
        foreach ([0, 1] as $grouped) {
            foreach (['', '0'] as $submitted) {
                $record = NullableSelectRecord::create(['state' => 1, 'title' => 'Original']);
                $url = '/nullable-select/integer/'.$grouped.'/'.$record->id;
                $response = $this->get($url)->assertOk();
                $crawler = new Crawler($response->getContent(), 'http://localhost'.$url);
                $form = $crawler->selectButton('Save')->form();
                // Grouped selects historically have no blank choice for a non-null value;
                // clearing through Select2 submits its hidden empty control.
                $values = $form->getPhpValues();
                $values['state'] = $submitted;
                $values['title'] = '';
                $this->from($url)->post($url, $values)->assertRedirect($url)->assertSessionHasErrors('title');
                $this->assertSame(1, $record->fresh()->state);
                $response = $this->get($url)->assertOk();
                $crawler = new Crawler($response->getContent(), 'http://localhost'.$url);
                $this->assertSame($submitted === '' ? [] : ['0'], $this->selected($crawler));
                $values = $crawler->selectButton('Save')->form()->getPhpValues();
                $this->assertSame($submitted, $values['state']);
                $values['title'] = 'Corrected';
                $this->post($url, $values, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                    ->assertOk()->assertJson(['status' => true]);
                $this->assertSame($submitted === '' ? null : 0, $record->fresh()->state);
                $this->flushSession();
            }
        }
    }
}

class NullableSelectRecord extends Model
{
    protected $table = 'nullable_select_records';
    protected $guarded = [];
    public $timestamps = false;
}

class NullableSelectBooleanRecord extends NullableSelectRecord
{
    protected $casts = ['state' => 'boolean'];
}
