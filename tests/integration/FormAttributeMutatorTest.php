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

class FormAttributeMutatorTest extends TestCase
{
    private array $packageState = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/form-attributes/{column}/{id}/edit', function ($column, $id) {
                return $this->form($column)->edit($id)->render();
            });
            $router->post('/form-attributes/{column}', function ($column) {
                return $this->form($column)->store();
            });
            $router->put('/form-attributes/{column}/{id}', function ($column, $id) {
                return $this->form($column)->update($id);
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
        Schema::create('form_attribute_records', function ($table) {
            $table->increments('id');
            foreach (['title', 'nickname', 'write_only', 'read_only', 'display_name',
                'first_name', 'last_name', 'legacy', 'plain', 'preferences'] as $column) {
                $table->string($column)->nullable();
            }
        });
        Schema::create('form_attribute_profiles', function ($table) {
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

    private function form(string $column): Form
    {
        $form = new Form(new FormAttributeRecord());
        $form->setAction('/form-attributes/'.$column);
        if ($column === 'preferences') {
            $form->embeds('preferences', function ($form) {
                $form->text('theme')->rules('required');
            });
        } else {
            $form->text($column)->rules('nullable|string');
        }
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });

        return $form;
    }

    public static function scalarAttributes(): iterable
    {
        foreach (['title', 'nickname', 'write_only', 'read_only', 'display_name', 'full_name', 'legacy', 'plain'] as $column) {
            foreach (['text' => ' Mixed <名前> ', 'zero' => '0', 'null' => null] as $label => $value) {
                yield $column.' '.$label => [$column, $value];
            }
        }
    }

    #[DataProvider('scalarAttributes')]
    public function test_http_create_edit_and_update_follow_native_eloquent_attributes(string $column, ?string $value): void
    {
        $native = new FormAttributeRecord();
        // The real web middleware trims text before Form sees the request.
        $native->setAttribute($column, $value === null ? null : trim($value));
        $this->post('/form-attributes/'.$column, [$column => $value])->assertRedirect();
        $record = FormAttributeRecord::firstOrFail();
        $this->assertSame(1, FormAttributeRecord::count());
        foreach ($native->getAttributes() as $key => $stored) {
            $this->assertSame($stored, $record->getRawOriginal($key));
        }
        $this->assertEditValue($column, $record);

        $next = $value === null ? ' Changed value ' : null;
        $native = $record->fresh();
        $native->setAttribute($column, $next === null ? null : trim($next));
        $this->put('/form-attributes/'.$column.'/'.$record->id, [$column => $next])->assertRedirect();
        $record->refresh();
        $this->assertSame($native->getAttributes(), $record->getAttributes());
        $this->assertEditValue($column, $record);
    }

    private function assertEditValue(string $column, FormAttributeRecord $record): void
    {
        $before = $record->getAttributes();
        $response = $this->get('/form-attributes/'.$column.'/'.$record->id.'/edit')->assertOk();
        $crawler = new Crawler($response->getContent());
        $this->assertSame((string) $record->toArray()[$column], $crawler->filter('input[name="'.$column.'"]')->attr('value'));
        $this->assertSame($before, $record->fresh()->getAttributes(), 'Editing must not write or normalize stored attributes.');
    }

    public function test_attribute_discovery_does_not_invoke_attribute_declarations_as_relations(): void
    {
        $form = new FormAttributeProbe(new FormAttributeRecord());
        $form->text('title');
        $form->text('nickname');
        $form->text('display_name');
        $form->text('preferences.theme');
        $form->text('profile.label');
        FormAttributeRecord::$declarations = 0;
        $this->assertSame(['profile'], $form->getRelations());
        $this->assertSame(['profile' => ['label' => 'Related']], $form->relationInputs([
            'title' => 'Title', 'nickname' => 'Nick', 'display_name' => 'Name',
            'preferences' => ['theme' => 'dark'], 'profile' => ['label' => 'Related'],
        ]));
        $this->assertSame(0, FormAttributeRecord::$declarations);
    }

    public function test_embedded_attribute_and_real_has_one_relation_can_be_edited_and_saved_together(): void
    {
        $this->post('/form-attributes/preferences', ['preferences' => ['theme' => 'dark']])->assertRedirect();
        $record = FormAttributeRecord::firstOrFail();
        $this->assertSame(['theme' => 'dark'], $record->preferences);
        $this->put('/form-attributes/preferences/'.$record->id, ['preferences' => ['theme' => 'light']])->assertRedirect();
        $this->assertSame(['theme' => 'light'], $record->fresh()->preferences);
        $html = $this->get('/form-attributes/preferences/'.$record->id.'/edit')->assertOk()->getContent();
        $this->assertSame('light', (new Crawler($html))->filter('input[name="preferences[theme]"]')->attr('value'));

        $form = new FormAttributeProbe($record);
        $form->text('title');
        $form->text('profile.label');
        $this->assertSame(['profile'], $form->getRelations());
        $form->update($record->id, ['title' => ' Changed ', 'profile' => ['label' => 'Related']]);
        $this->assertSame('changed', $record->fresh()->getRawOriginal('title'));
        $this->assertSame('Related', $record->fresh()->profile->label);
        $form = new FormAttributeProbe(new FormAttributeRecord());
        $form->text('title');
        $form->text('profile.label');
        $form->edit($record->id);
        $this->assertSame('Related', $form->fields()->last()->value());
    }

    public function test_models_without_the_attribute_capability_keep_existing_relation_detection(): void
    {
        $relation = (new FormAttributeRecord())->profile();
        $legacy = new class($relation) {
            public function __construct(private $relation) {}
            public function profile() { return $this->relation; }
            public function ordinary() { return 'ordinary'; }
        };
        $form = new FormAttributeProbe(new FormAttributeRecord());
        (new \ReflectionProperty(Form::class, 'model'))->setValue($form, $legacy);
        $form->text('plain');
        $form->text('profile.label');
        $form->text('ordinary');
        $this->assertSame(['profile', 'ordinary'], $form->getRelations());
        $this->assertSame(['profile' => ['label' => 'Related']], $form->relationInputs([
            'plain' => 'Scalar', 'ordinary' => 'Value', 'profile' => ['label' => 'Related'],
        ]));
    }
}

class FormAttributeProbe extends Form
{
    public function relationInputs(array $inputs): array
    {
        return $this->getRelationInputs($inputs);
    }
}

class FormAttributeRecord extends Model
{
    protected $table = 'form_attribute_records';
    protected $guarded = [];
    protected $appends = ['full_name'];
    public $timestamps = false;
    public static int $declarations = 0;

    protected function title(): Attribute
    {
        self::$declarations++;
        return Attribute::make(
            get: fn ($value) => $value === null ? null : strtoupper($value),
            set: fn ($value) => $value === null ? null : strtolower(trim($value)),
        );
    }

    public function nickname(): Attribute
    {
        self::$declarations++;
        return Attribute::make(
            get: fn ($value) => $value === null ? null : 'Nickname: '.$value,
            set: fn ($value) => $value === null ? null : trim($value),
        );
    }

    protected function writeOnly(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : 'stored:'.trim($value));
    }

    protected function readOnly(): Attribute
    {
        return Attribute::make(get: fn ($value) => $value === null ? null : 'Read: '.$value);
    }

    protected function displayName(): Attribute
    {
        self::$declarations++;
        return Attribute::make(set: fn ($value) => $value === null ? null : trim($value));
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')),
            set: function ($value) {
                [$first, $last] = array_pad(explode(' ', trim($value ?? ''), 2), 2, '');
                return ['first_name' => $first, 'last_name' => $last];
            },
        );
    }

    protected function preferences(): Attribute
    {
        self::$declarations++;
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : json_decode($value, true),
            set: fn ($value) => json_encode($value),
        );
    }

    public function getLegacyAttribute($value)
    {
        return $value === null ? null : 'Legacy: '.$value;
    }

    public function setLegacyAttribute($value)
    {
        $this->attributes['legacy'] = $value === null ? null : trim($value);
    }

    public function profile()
    {
        return $this->hasOne(FormAttributeProfile::class, 'record_id');
    }
}

class FormAttributeProfile extends Model
{
    protected $table = 'form_attribute_profiles';
    protected $guarded = [];
    public $timestamps = false;
}
