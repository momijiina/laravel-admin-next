<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Displayers\Editable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridEditableSelectSourceItem extends Model
{
    protected $table = 'grid_editable_select_source_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['options' => 'array'];
}

class GridEditableSelectSourceTest extends TestCase
{
    private array $packageState = [];
    public static array $callbackRows = [];

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
        $router->get('/grid-editable-source/{mode}', function ($mode) {
            $grid = new Grid(new GridEditableSelectSourceItem());
            $grid->setResource('/grid-editable-source');
            $grid->model()->orderBy('id');
            $grid->column('id');
            if ($mode === 'dynamic') {
                $grid->column('choice')->editable('select', function ($row) {
                    GridEditableSelectSourceTest::$callbackRows[] = [
                        $row->id, $this instanceof Editable, $this->row === $row,
                    ];

                    return $row->options;
                });
            } else {
                $grid->column('choice')->editable('select', self::staticOptions());
            }
            $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();

            return ['html' => $grid->render(), 'scripts' => (string) Admin::script()];
        });
        $router->put('/grid-editable-source/{id}', function ($id) {
            $form = new Form(new GridEditableSelectSourceItem());
            $form->text('choice');

            return $form->update($id);
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'html', 'style'],
            Form::class => ['snakeAttributes'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            Admin::$script = Admin::$html = Admin::$style = [];
            self::$callbackRows = [];
            $this->withoutExceptionHandling();
            Schema::create('grid_editable_select_source_items', function ($table) {
                $table->increments('id');
                $table->text('choice')->nullable();
                $table->text('options');
            });
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
        self::$callbackRows = [];
    }

    private static function staticOptions(): array
    {
        return ['stored' => 'Ordinary', "next'&quot;" => "O'Reilly &amp; <b>literal</b>"];
    }

    public static function sourceCases(): iterable
    {
        yield 'apostrophe' => ["next'value", "O'Reilly"];
        yield 'double quote' => ['next"value', 'A "quoted" label'];
        yield 'ampersand' => ['next&value', 'ACME & Sons'];
        yield 'literal entity' => ['next&amp;value', 'ACME &amp; Sons'];
        yield 'quote entities' => ['next&quot;value', '&quot; &#39; &#34;'];
        yield 'numeric entities' => ['next&#13;value', '&#13; &#10; &#x27;'];
        yield 'markup-looking' => ['next<value>', '<b>literal</b>'];
        yield 'attribute-looking' => ["next' data-unexpected='yes", "' data-unexpected='yes"];
        yield 'unicode' => ['次🌿', '注文 café 🌿 é'];
        yield 'empty label' => ['next', ''];
        yield 'zero label' => ['next', '0'];
        yield 'LF' => ["next\nvalue", "Line 1\nLine 2"];
        yield 'CRLF' => ["next\r\nvalue", "Line 1\r\nLine 2"];
        yield 'backslash' => ['next\\value', 'C:\\data\\value'];
    }

    #[DataProvider('sourceCases')]
    public function test_callback_source_round_trips_through_the_html_attribute(string $key, string $label): void
    {
        $grid = new Grid(new GridEditableSelectSourceItem());
        $grid->setResource('/grid-editable-source');
        $row = new GridEditableSelectSourceItem(['id' => 1, 'choice' => 'stored']);
        $displayer = new Editable('stored', $grid, $grid->column('choice'), $row);
        $html = $displayer->display('select', function () use ($key, $label) {
            return ['stored' => 'Ordinary', $key => $label];
        });
        $anchor = (new Crawler($html))->filter('a');
        $this->assertCount(1, $anchor);
        $this->assertSame([
            ['value' => 'stored', 'text' => 'Ordinary'], ['value' => $key, 'text' => $label],
        ], json_decode($anchor->attr('data-source'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertFalse($anchor->getNode(0)->hasAttribute('data-unexpected'));
    }

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery' => ['shipped'];
        yield 'modern jQuery' => ['modern'];
    }

    #[DataProvider('jqueryVersions')]
    public function test_actual_editable_plugin_preserves_per_row_sources_cancel_and_http_saves(string $jquery): void
    {
        $expected = [];
        foreach (self::sourceCases() as [$key, $label]) {
            $options = ['stored' => 'Ordinary', $key => $label];
            $item = GridEditableSelectSourceItem::create(['choice' => 'stored', 'options' => $options]);
            $expected[] = ['id' => $item->id, 'options' => $options];
        }
        $payload = $this->get('/grid-editable-source/dynamic')->assertOk()->json();
        $this->assertSame(array_map(static fn ($row) => [$row['id'], true, true], $expected), self::$callbackRows);
        $observed = $this->observe($payload, $jquery, $expected, true);
        $this->assertRequestsPersist($observed, $expected);
    }

    #[DataProvider('jqueryVersions')]
    public function test_static_array_options_keep_the_existing_script_transport(string $jquery): void
    {
        $item = GridEditableSelectSourceItem::create(['choice' => 'stored', 'options' => self::staticOptions()]);
        $expected = [['id' => $item->id, 'options' => self::staticOptions()]];
        $payload = $this->get('/grid-editable-source/static')->assertOk()->json();
        $this->assertSame([], self::$callbackRows);
        $observed = $this->observe($payload, $jquery, $expected, false);
        $this->assertRequestsPersist($observed, $expected);
    }

    public function test_callback_source_preserves_integer_keys_and_zero_labels(): void
    {
        $grid = new Grid(new GridEditableSelectSourceItem());
        $row = new GridEditableSelectSourceItem(['id' => 1]);
        $displayer = new Editable(0, $grid, $grid->column('choice'), $row);
        $anchor = (new Crawler($displayer->display('select', function () {
            return [0 => '0', 2 => "O'Reilly"];
        })))->filter('a');
        $this->assertSame([
            ['value' => 0, 'text' => '0'], ['value' => 2, 'text' => "O'Reilly"],
        ], json_decode($anchor->attr('data-source'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('0', $anchor->attr('data-value'));
    }

    public function test_empty_callback_options_remain_an_empty_array(): void
    {
        $grid = new Grid(new GridEditableSelectSourceItem());
        $row = new GridEditableSelectSourceItem(['id' => 1]);
        $displayer = new Editable(null, $grid, $grid->column('choice'), $row);
        $anchor = (new Crawler($displayer->display('select', function () { return []; })))->filter('a');
        $this->assertSame('[]', $anchor->attr('data-source'));
        $this->assertSame('', $anchor->attr('data-value'));
    }

    public function test_text_editable_value_and_visible_label_keep_existing_escaping(): void
    {
        $value = "O'Reilly &amp; <b>literal</b>";
        $grid = new Grid(new GridEditableSelectSourceItem());
        $row = new GridEditableSelectSourceItem(['id' => 1]);
        $displayer = new Editable($value, $grid, $grid->column('choice'), $row);
        $anchor = (new Crawler($displayer->display('text')))->filter('a');
        $this->assertSame($value, $anchor->attr('data-value'));
        $this->assertSame($value, $anchor->text());
        $this->assertCount(0, $anchor->filter('b'));
        $this->assertFalse($anchor->getNode(0)->hasAttribute('data-source'));
    }

    private function observe(array $payload, string $jquery, array $expected, bool $dynamic): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-editable-select-source.cjs']);
        $process->setInput(json_encode($payload + compact('jquery', 'expected', 'dynamic'), JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertRequestsPersist(array $observed, array $expected): void
    {
        $this->assertSame([], $observed['errors']);
        $this->assertCount(count($expected) * 2, $observed['requests']);
        foreach ($observed['requests'] as $index => $request) {
            $row = $expected[intdiv($index, 2)];
            $value = $index % 2 === 0 ? (string) array_keys($row['options'])[1] : 'stored';
            $this->assertSame('/grid-editable-source/'.$row['id'], $request['url']);
            $this->assertSame('POST', $request['type']);
            parse_str($request['query'], $input);
            $this->assertSame([
                'name' => 'choice', 'value' => $value, 'pk' => (string) $row['id'],
                '_token' => 'test-token', '_editable' => '1', '_method' => 'PUT',
            ], $input);
            $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson($request['url'], $input)->assertOk()->assertJson(['status' => true]);
            $item = GridEditableSelectSourceItem::findOrFail($row['id']);
            $this->assertSame($value, $item->choice);
            $this->assertSame($row['options'], $item->options);
            $this->assertSame(
                array_fill(0, count($expected) - 1, 'stored'),
                GridEditableSelectSourceItem::where('id', '!=', $row['id'])->orderBy('id')->pluck('choice')->all()
            );
        }
    }
}
