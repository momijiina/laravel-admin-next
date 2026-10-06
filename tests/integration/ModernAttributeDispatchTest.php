<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class AttributeDispatchOwner extends Model
{
    protected $table = 'attribute_dispatch_owners';
    protected $guarded = [];
    public $timestamps = false;
}

class AttributeDispatchRecord extends Model
{
    protected $table = 'attribute_dispatch_records';
    protected $guarded = [];
    protected $appends = ['display_name', 'legacy', 'precedence'];
    public $timestamps = false;
    public static array $declarations = [];
    public static int $ordinaryCalls = 0;

    private function declaration(string $name, ?callable $get = null, ?callable $set = null)
    {
        static::$declarations[] = $name;

        return Attribute::make(get: $get, set: $set);
    }

    protected function name(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => strtoupper($value));
    }

    public function title(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'title:'.$value);
    }

    protected function both(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'BOTH:'.$value, fn ($value) => strtoupper($value));
    }

    public function publicboth(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'PUBLIC:'.$value, fn ($value) => strtoupper($value));
    }

    protected function writeonly(): Attribute
    {
        return $this->declaration(__FUNCTION__, set: fn ($value) => 'WRITE:'.strtoupper($value));
    }

    public function publicwrite(): Attribute
    {
        return $this->declaration(__FUNCTION__, set: fn ($value) => 'PUBLIC-WRITE:'.strtoupper($value));
    }

    protected function snakeValue(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'SNAKE:'.$value);
    }

    protected function camelValue(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'CAMEL:'.$value);
    }

    protected function displayName(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value, $attributes) => 'computed:'.$attributes['ordinary']);
    }

    protected function nullable(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => $value);
    }

    protected function zero(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => (int) $value);
    }

    public function getLegacyAttribute()
    {
        return 'LEGACY';
    }

    public function getPrecedenceAttribute()
    {
        return 'LEGACY FIRST';
    }

    protected function precedence(): Attribute
    {
        return $this->declaration(__FUNCTION__, fn ($value) => 'WRONG MODERN VALUE');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(AttributeDispatchOwner::class, 'owner_id');
    }

    public function ordinaryMethod()
    {
        static::$ordinaryCalls++;

        return null;
    }
}

class AttributeDispatchCamelRecord extends AttributeDispatchRecord
{
    public static $snakeAttributes = false;
    protected $appends = ['displayName', 'legacy'];
}

/** Native Attribute dispatch with real HTTP, SQLite and the shipped Grid/Show views. */
class ModernAttributeDispatchTest extends TestCase
{
    private array $packageState = [];
    private array $dispatchDeclarations = [];
    private array $fieldMetadata = [];

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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->get('/attribute-grid/{api}/{name}', function ($api, $name) {
            $class = request()->boolean('camel') ? AttributeDispatchCamelRecord::class : AttributeDispatchRecord::class;
            $grid = new Grid(new $class());
            $grid->model()->orderBy('id');
            AttributeDispatchRecord::$declarations = [];
            $label = request()->query('label');
            $column = $api === 'explicit' ? $grid->column($name, $label) : $grid->$name($label);
            $this->dispatchDeclarations = AttributeDispatchRecord::$declarations;
            $this->fieldMetadata = [$column->getName(), $column->getLabel()];
            if ($name === 'owner') {
                $column->name('Owner name');
            }
            $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();

            $grid->build();

            return $grid->render();
        });
        $router->get('/attribute-show/{api}/{name}', function ($api, $name) {
            $class = request()->boolean('camel') ? AttributeDispatchCamelRecord::class : AttributeDispatchRecord::class;
            $show = new Show($class::firstOrFail());
            $show->panel()->tools(fn ($tools) => $tools->disableList()->disableEdit()->disableDelete());
            AttributeDispatchRecord::$declarations = [];
            $label = request()->query('label');
            $field = $api === 'explicit' ? $show->field($name, $label) : $show->$name($label);
            $this->dispatchDeclarations = AttributeDispatchRecord::$declarations;
            $this->fieldMetadata = [$field->getName(), $field->getLabel()];
            if ($name === 'owner') {
                $field->as(fn ($owner) => $owner->name);
            }

            return $show->render();
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script'],
            Grid::class => ['snakeAttributes', 'macros'],
            Show::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
            AttributeDispatchRecord::class => ['declarations', 'ordinaryCalls'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            Schema::create('attribute_dispatch_owners', function (Blueprint $table) {
                $table->increments('id');
                $table->text('name');
            });
            Schema::create('attribute_dispatch_records', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('owner_id');
                foreach (['name', 'title', 'both', 'publicboth', 'writeonly', 'publicwrite', 'snake_value', 'camelValue', 'ordinary', 'zero'] as $name) {
                    $table->text($name);
                }
                $table->text('nullable')->nullable();
            });
            $owner = AttributeDispatchOwner::create(['name' => 'Related owner']);
            AttributeDispatchRecord::create([
                'owner_id' => $owner->id, 'name' => 'sample', 'title' => 'sample',
                'both' => 'seed', 'publicboth' => 'seed', 'writeonly' => 'seed', 'publicwrite' => 'seed',
                'snake_value' => 'sample', 'camelValue' => 'sample', 'ordinary' => 'raw', 'zero' => '0', 'nullable' => null,
            ]);
        } catch (\Throwable $exception) {
            $this->restorePackageState();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restorePackageState();
        }
    }

    private function restorePackageState(): void
    {
        foreach ($this->packageState as [$property, $value]) {
            $property->setValue(null, $value);
        }
    }

    public static function fields(): array
    {
        return [
            'protected getter' => ['name', 'SAMPLE'],
            'public getter' => ['title', 'title:sample'],
            'protected getter and setter' => ['both', 'BOTH:SEED'],
            'public getter and setter' => ['publicboth', 'PUBLIC:SEED'],
            'protected setter only' => ['writeonly', 'WRITE:SEED'],
            'public setter only' => ['publicwrite', 'PUBLIC-WRITE:SEED'],
            'snake case' => ['snake_value', 'SNAKE:sample'],
            'camel case' => ['camelValue', 'CAMEL:sample'],
            'appended snake case' => ['display_name', 'computed:raw'],
            'null' => ['nullable', null],
            'zero' => ['zero', 0],
            'legacy getter' => ['legacy', 'LEGACY'],
            'legacy precedence' => ['precedence', 'LEGACY FIRST'],
            'ordinary raw column' => ['ordinary', 'raw'],
        ];
    }

    private function assertRenderedField(string $view, string $api, string $name, $expected, ?string $label = 'Custom label', bool $camel = false): void
    {
        $before = (array) DB::table('attribute_dispatch_records')->first();
        $class = $camel ? AttributeDispatchCamelRecord::class : AttributeDispatchRecord::class;
        $record = $class::firstOrFail();
        $this->assertSame($expected, $record->getAttribute($name));
        // Grid consumes Eloquent's serialized values; Show resolves the native attribute directly.
        $rendered = $view === 'grid' ? $record->toArray()[$name] : $expected;
        $url = '/attribute-'.$view.'/'.$api.'/'.$name;
        $query = $camel ? ['camel' => '1'] : [];
        if ($label !== null) {
            $query['label'] = $label;
        }
        if ($query) {
            $url .= '?'.http_build_query($query);
        }
        $response = $this->get($url)->assertOk();
        $this->assertSame([], $this->dispatchDeclarations, 'Dispatch must not invoke native Attribute declarations as relationships.');
        $expectedLabel = $label ?? str_replace('_', ' ', ucfirst($name));
        $this->assertSame([$name, $expectedLabel], $this->fieldMetadata);
        $html = new Crawler($response->getContent());
        if ($view === 'grid') {
            $this->assertCount(1, $html->filter('table.grid-table > tbody > tr'), $response->getContent());
            $this->assertSame((string) $rendered, trim($html->filter('table.grid-table > tbody > tr > td')->text()));
            $this->assertStringContainsString($expectedLabel, $html->filter('table.grid-table > thead')->text());
        } else {
            $this->assertCount(1, $html->filter('.form-group'));
            $this->assertSame($expectedLabel, trim($html->filter('.form-group > label')->text()));
            $this->assertSame((string) $expected, trim(str_replace("\xc2\xa0", ' ', $html->filter('.box-show > .box-body')->text())));
        }
        $this->assertSame($before, (array) DB::table('attribute_dispatch_records')->first());
        $this->assertSame($expected, $class::firstOrFail()->getAttribute($name));
    }

    #[DataProvider('fields')]
    public function test_grid_explicit_columns_use_native_values(string $name, $expected): void
    {
        $this->assertRenderedField('grid', 'explicit', $name, $expected);
    }

    #[DataProvider('fields')]
    public function test_grid_shorthand_columns_use_native_values(string $name, $expected): void
    {
        $this->assertRenderedField('grid', 'shorthand', $name, $expected);
    }

    #[DataProvider('fields')]
    public function test_show_shorthand_fields_use_native_values(string $name, $expected): void
    {
        $this->assertRenderedField('show', 'shorthand', $name, $expected);
    }

    #[DataProvider('fields')]
    public function test_show_explicit_fields_keep_native_values(string $name, $expected): void
    {
        $this->assertRenderedField('show', 'explicit', $name, $expected);
    }

    public function test_default_labels_and_names_are_preserved(): void
    {
        foreach (['grid', 'show'] as $view) {
            foreach (['explicit', 'shorthand'] as $api) {
                $this->assertRenderedField($view, $api, 'display_name', 'computed:raw', null);
            }
        }
    }

    public function test_camel_case_serialization_and_appends_follow_the_model_setting(): void
    {
        foreach (['grid', 'show'] as $view) {
            foreach (['explicit', 'shorthand'] as $api) {
                $this->assertRenderedField($view, $api, 'camelValue', 'CAMEL:sample', null, true);
                $this->assertRenderedField($view, $api, 'displayName', 'computed:raw', null, true);
            }
        }
    }

    public function test_grid_macros_keep_precedence_over_native_attributes(): void
    {
        Grid::macro('name', function ($label) {
            return $this->column('ordinary', $label);
        });
        foreach (['explicit', 'shorthand'] as $api) {
            $response = $this->get('/attribute-grid/'.$api.'/name?label=Macro%20label')->assertOk();
            $this->assertSame(['ordinary', 'Macro label'], $this->fieldMetadata);
            $this->assertSame([], $this->dispatchDeclarations);
            $html = new Crawler($response->getContent());
            $this->assertSame('raw', trim($html->filter('td.column-ordinary')->text()));
        }
    }

    public function test_public_single_level_relations_keep_their_dispatch(): void
    {
        foreach (['explicit', 'shorthand'] as $api) {
            $response = $this->get('/attribute-grid/'.$api.'/owner')->assertOk();
            $html = new Crawler($response->getContent());
            $this->assertSame('Related owner', trim($html->filter('table.grid-table > tbody > tr > td')->text()));
        }
        $response = $this->get('/attribute-show/shorthand/owner')->assertOk();
        $html = new Crawler($response->getContent());
        $this->assertSame('Related owner', trim(str_replace("\xc2\xa0", ' ', $html->filter('.box-show > .box-body')->text())));
        $this->assertSame('Owner', trim($html->filter('.form-group > label')->text()));
    }

    public function test_other_public_model_methods_are_not_broadly_bypassed(): void
    {
        AttributeDispatchRecord::$ordinaryCalls = 0;
        $grid = new Grid(new AttributeDispatchRecord());
        $column = $grid->ordinaryMethod('Ordinary');
        $this->assertSame('ordinaryMethod', $column->getName());
        $this->assertSame(1, AttributeDispatchRecord::$ordinaryCalls);
        $show = new Show(AttributeDispatchRecord::firstOrFail());
        $field = $show->ordinaryMethod('Ordinary');
        $this->assertSame('ordinaryMethod', $field->getName());
        $this->assertSame(2, AttributeDispatchRecord::$ordinaryCalls);
    }

    public function test_frameworks_without_native_attribute_detection_keep_the_legacy_fallback(): void
    {
        // Deliberately small capability double, not a claim of running an old Laravel release.
        $legacyModel = new class {
            public function hasGetMutator($name) { return $name === 'legacy'; }
        };
        $grid = new Grid(new AttributeDispatchRecord());
        $gridModel = new \ReflectionProperty(Grid::class, 'model');
        $gridModel->setValue($grid, new class($legacyModel) {
            private $model;
            public function __construct($model) { $this->model = $model; }
            public function eloquent() { return $this->model; }
        });
        $columnHandler = new \ReflectionMethod(Grid::class, 'handleGetMutatorColumn');
        $this->assertFalse($columnHandler->invoke($grid, 'ordinary', 'Ordinary'));
        $this->assertSame('legacy', $columnHandler->invoke($grid, 'legacy', 'Legacy')->getName());
        $show = new Show($legacyModel);
        $fieldHandler = new \ReflectionMethod(Show::class, 'handleGetMutatorField');
        $this->assertFalse($fieldHandler->invoke($show, 'ordinary', 'Ordinary'));
        $this->assertSame('legacy', $fieldHandler->invoke($show, 'legacy', 'Legacy')->getName());
    }

    public function test_legacy_getter_detection_keeps_precedence(): void
    {
        $model = new class extends AttributeDispatchRecord {
            public function hasAttributeMutator($name)
            {
                throw new \LogicException('Legacy getters must be recognized first.');
            }
        };
        $grid = new Grid($model);
        $this->assertSame('legacy', $grid->legacy('Legacy')->getName());
        $show = new Show($model);
        $this->assertSame('legacy', $show->legacy('Legacy')->getName());
    }
}
