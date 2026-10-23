<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class StructuredFormInsertTest extends TestCase
{
    private array $packageState = [];
    private const SETTINGS = ['theme' => '日本語', 'count' => '0', 'nested' => ['label' => 'Inner']];

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
            $router->get('/structured-inserts/{cast}/{layout}/create', function ($cast, $layout) {
                return $this->form($cast, $layout)->render();
            });
            $router->get('/structured-inserts/{cast}/{layout}/{id}/edit', function ($cast, $layout, $id) {
                return $this->form($cast, $layout)->edit($id)->render();
            });
            $router->post('/structured-inserts/{cast}/{layout}', function ($cast, $layout) {
                return $this->form($cast, $layout)->store();
            });
            $router->put('/structured-inserts/{cast}/{layout}/{id}', function ($cast, $layout, $id) {
                return $this->form($cast, $layout)->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        foreach ([Admin::class => ['script', 'style'], Form::class => ['collectedAssets', 'snakeAttributes']] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        parent::setUp();
        $this->withoutExceptionHandling();
        $this->app['view']->share('errors', new ViewErrorBag());
        Schema::create('structured_insert_records', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            foreach (['settings', 'metadata', 'choices'] as $column) {
                $table->json($column)->nullable();
            }
        });
        Schema::create('structured_insert_profiles', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('record_id');
            $table->string('label');
        });
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->packageState as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    private function model(string $cast): StructuredInsertRecord
    {
        return match ($cast) {
            'array' => new StructuredInsertRecord(),
            'object' => new StructuredInsertObjectRecord(),
            'attribute' => new StructuredInsertAttributeRecord(),
        };
    }

    private function form(string $cast, string $layout): Form
    {
        $form = new Form($this->model($cast));
        $form->setAction('/structured-inserts/'.$cast.'/'.$layout);
        if ($layout === 'scalar-first') {
            $form->text('title');
        }
        if ($layout === 'choices') {
            $form->multipleSelect('choices')->options([0 => 'Zero', 2 => 'Two']);
        } else {
            $form->embeds('settings', function ($embedded) {
                $embedded->text('theme')->rules('required');
                $embedded->text('count')->rules('nullable');
                $embedded->embeds('nested', function ($nested) {
                    $nested->text('label');
                });
            });
        }
        if ($layout === 'array-first') {
            $form->text('title');
        } elseif ($layout === 'leaf') {
            $form->text('metadata->flag');
        } elseif ($layout === 'relation') {
            $form->text('profile.label');
        } elseif ($layout === 'mixed') {
            $form->multipleSelect('choices')->options([0 => 'Zero', 2 => 'Two']);
        }
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });

        return $form;
    }

    public static function embeddedInputs(): iterable
    {
        foreach (['array', 'object', 'attribute'] as $cast) {
            foreach (['only', 'array-first', 'scalar-first', 'token-first', 'token-last', 'nested-first', 'unknown-first'] as $layout) {
                yield $cast.' '.$layout => [$cast, $layout];
            }
        }
    }

    #[DataProvider('embeddedInputs')]
    public function test_registered_structured_values_survive_create_and_subsequent_edit_update(string $cast, string $layout): void
    {
        $input = ['settings' => self::SETTINGS];
        if ($layout === 'array-first') {
            $input['title'] = 'Original';
        } elseif ($layout === 'scalar-first') {
            $input = ['title' => 'Original'] + $input;
        } elseif ($layout === 'token-first') {
            $input = ['_token' => 'test-token'] + $input;
        } elseif ($layout === 'token-last') {
            $input['_token'] = 'test-token';
        } elseif ($layout === 'unknown-first') {
            $input = ['unregistered' => ['ignored' => 'Untrusted']] + $input;
        } elseif ($layout === 'nested-first') {
            $input['settings'] = ['nested' => self::SETTINGS['nested']] + self::SETTINGS;
        }
        $url = '/structured-inserts/'.$cast.'/'.$layout;
        $this->post($url, $input)->assertRedirect();
        $record = $this->model($cast)->firstOrFail();
        $this->assertSame(1, $record->count());
        $this->assertEquals(self::SETTINGS, $this->storedSettings($record));
        $this->assertSame($input['title'] ?? null, $record->title);
        $this->assertArrayNotHasKey('unregistered', $record->getAttributes());
        $html = $this->get($url.'/'.$record->id.'/edit')->assertOk()->getContent();
        $crawler = new Crawler($html);
        foreach (['settings[theme]' => '日本語', 'settings[count]' => '0', 'settings[nested][label]' => 'Inner'] as $name => $value) {
            $this->assertSame($value, $crawler->filter('input[name="'.$name.'"]')->attr('value'));
        }
        $updated = ['theme' => 'Changed', 'count' => null, 'nested' => ['label' => 'New']];
        $this->put($url.'/'.$record->id, ['settings' => $updated])->assertRedirect();
        $this->assertSame($updated, $this->storedSettings($record->fresh()));
    }

    public function test_rendered_form_submits_structured_inputs_before_its_csrf_field(): void
    {
        $url = '/structured-inserts/array/only';
        $html = $this->get($url.'/create')->assertOk()->getContent();
        $form = (new Crawler($html, 'http://localhost'.$url.'/create'))->filter('form')->form();
        $form['settings[theme]'] = '日本語';
        $form['settings[count]'] = '0';
        $form['settings[nested][label]'] = 'Inner';
        $input = $form->getPhpValues();
        $this->assertSame('settings', array_key_first($input));
        $this->assertArrayHasKey('_token', $input);
        $this->post($form->getUri(), $input)->assertRedirect();
        $this->assertSame(self::SETTINGS, $this->storedSettings(StructuredInsertRecord::firstOrFail()));
    }

    public function test_structured_fields_keep_legacy_leaf_and_actual_relation_saves(): void
    {
        $url = '/structured-inserts/array/leaf';
        $this->post($url, ['settings' => self::SETTINGS, 'metadata' => ['flag' => '0']])->assertRedirect();
        $record = StructuredInsertRecord::firstOrFail();
        $this->assertSame(self::SETTINGS, $record->settings);
        $this->assertSame(['flag' => '0'], $record->metadata);

        $this->post('/structured-inserts/array/relation', [
            'settings' => self::SETTINGS, 'profile' => ['label' => 'Related'],
        ])->assertRedirect();
        $record = StructuredInsertRecord::latest('id')->firstOrFail();
        $this->assertSame(self::SETTINGS, $record->settings);
        $this->assertSame('Related', $record->profile->label);
    }

    public function test_sparse_multiple_select_values_are_prepared_by_the_registered_field(): void
    {
        $this->post('/structured-inserts/array/choices', ['choices' => [2 => '0', 8 => '2']])->assertRedirect();
        $record = StructuredInsertRecord::firstOrFail();
        $this->assertSame([2 => '0', 8 => '2'], $record->choices);
    }

    public function test_multiple_registered_structured_fields_are_preserved_in_the_same_request(): void
    {
        $this->post('/structured-inserts/array/mixed', [
            'settings' => self::SETTINGS, 'choices' => [2 => '0', 8 => '2'],
        ])->assertRedirect();
        $record = StructuredInsertRecord::firstOrFail();
        $this->assertSame(self::SETTINGS, $record->settings);
        $this->assertSame([2 => '0', 8 => '2'], $record->choices);
    }

    private function storedSettings(StructuredInsertRecord $record): array
    {
        $json = $record->getRawOriginal('settings');
        $this->assertIsString($json, 'The structured field must be persisted, not silently discarded.');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

class StructuredInsertRecord extends Model
{
    protected $table = 'structured_insert_records';
    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'metadata' => 'array', 'choices' => 'array'];
    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(StructuredInsertProfile::class, 'record_id');
    }
}

class StructuredInsertObjectRecord extends StructuredInsertRecord
{
    protected $casts = ['settings' => 'object', 'metadata' => 'array', 'choices' => 'array'];
}

class StructuredInsertAttributeRecord extends StructuredInsertRecord
{
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : json_decode($value, true),
            set: fn ($value) => json_encode($value),
        );
    }
}

class StructuredInsertProfile extends Model
{
    protected $table = 'structured_insert_profiles';
    protected $guarded = [];
    public $timestamps = false;
}
