<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Displayers\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class GridTableItem extends Model
{
    protected $table = 'grid_table_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['lines' => 'array'];
}

/** Real HTTP Grid rendering, including the exact data passed to the shipped view. */
class GridTableTest extends TestCase
{
    private array $packageState = [];
    private array $titles = [];
    private array $tableViews = [];

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
        $router->get('/grid-table/{id}', function ($id) {
            $grid = new Grid(new GridTableItem());
            $grid->model()->where('id', $id);
            $grid->column('id');
            $grid->column('lines')->table($this->titles);
            $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();

            return $grid->render();
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script'],
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
            $this->withoutExceptionHandling();
            Schema::create('grid_table_items', function (Blueprint $table) {
                $table->increments('id');
                $table->text('lines')->nullable();
            });
            // Observe real view data without replacing the view or framework classes.
            $this->app['view']->composer('admin::grid.displayer.table', function ($view) {
                $data = $view->getData();
                $this->tableViews[] = ['titles' => $data['titles'], 'data' => $data['data']];
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
    }

    private function renderLines($lines, array $titles): Crawler
    {
        $this->titles = $titles;
        $this->tableViews = [];
        $item = GridTableItem::create(['lines' => $lines]);
        $raw = DB::table('grid_table_items')->where('id', $item->id)->value('lines');
        $this->assertSame($lines, $item->fresh()->lines);
        $response = $this->get('/grid-table/'.$item->id)->assertOk();
        $this->assertSame($raw, DB::table('grid_table_items')->where('id', $item->id)->value('lines'));
        $this->assertSame($lines, $item->fresh()->lines);
        $html = new Crawler($response->getContent());
        $this->assertCount(1, $html->filter('table.grid-table'));
        $this->assertSame((string) $item->id, trim($html->filter('td.column-id')->text()));

        return $html->filter('td.column-lines');
    }

    private function assertTable(array $lines, array $titles, array $expectedTitles, array $expectedData): void
    {
        $cell = $this->renderLines($lines, $titles);
        $table = $cell->filter('table');
        $this->assertCount(1, $table);
        $this->assertSame(array_map('strval', array_values($expectedTitles)), $table->filter('thead > tr > th')->each(fn ($cell) => $cell->text()));
        $rows = $table->filter('tbody > tr');
        $this->assertCount(count($expectedData), $rows);
        foreach ($expectedData as $index => $row) {
            $cells = $rows->eq($index)->filter('td');
            $this->assertCount(count($expectedTitles), $cells);
            $this->assertSame(array_map(fn ($value) => (string) $value, array_values($row)), $cells->each(fn ($cell) => $cell->text()));
        }
        // assertSame verifies key order, exact keys, NULL, and original value types.
        $this->assertSame([['titles' => $expectedTitles, 'data' => $expectedData]], $this->tableViews);
        $this->assertCount(0, $table->filter('script, img'));
    }

    public static function titleModes(): array
    {
        return [
            'mapped' => [['sku' => 'SKU', 'quantity' => 'Quantity', 'price' => 'Price'], ['sku' => 'SKU', 'quantity' => 'Quantity', 'price' => 'Price']],
            'listed' => [['sku', 'quantity', 'price'], ['sku' => 'sku', 'quantity' => 'quantity', 'price' => 'price']],
            'inferred' => [[], ['sku' => 'sku', 'quantity' => 'quantity', 'price' => 'price']],
        ];
    }

    public static function missingCells(): array
    {
        $cases = [];
        foreach (self::titleModes() as $mode => [$titles, $expectedTitles]) {
            foreach ([
                'first' => [['quantity' => 3, 'price' => '31.00'], ['sku' => null, 'quantity' => 3, 'price' => '31.00']],
                'middle' => [['sku' => 'B', 'price' => '21.00'], ['sku' => 'B', 'quantity' => null, 'price' => '21.00']],
                'last' => [['sku' => 'D', 'quantity' => 4], ['sku' => 'D', 'quantity' => 4, 'price' => null]],
                'all' => [[], ['sku' => null, 'quantity' => null, 'price' => null]],
            ] as $position => [$row, $expected]) {
                $cases[$mode.' '.$position] = [$titles, $expectedTitles, $row, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('missingCells')]
    public function test_missing_cells_stay_under_their_headers(array $titles, array $expectedTitles, array $row, array $expected): void
    {
        $first = ['sku' => 'A', 'quantity' => 2, 'price' => '10.50'];
        $last = ['sku' => 'Z', 'quantity' => 9, 'price' => '90.00'];
        $this->assertTable([$first, $row, $last], $titles, $expectedTitles, [$first, $expected, $last]);
    }

    #[DataProvider('titleModes')]
    public function test_complete_reordered_null_falsey_and_escaped_values_are_preserved(array $titles, array $expectedTitles): void
    {
        $first = ['sku' => 'A', 'quantity' => 2, 'price' => '10.50'];
        $this->assertTable([
            $first,
            ['price' => '21.00', 'sku' => 'B', 'quantity' => 3, 'extra' => 'excluded'],
            ['sku' => 'C', 'quantity' => null, 'price' => '0.00'],
            ['sku' => 'D', 'quantity' => 0, 'price' => false],
            ['sku' => '', 'quantity' => '0', 'price' => true],
            ['sku' => '<script>alert("x")</script>', 'quantity' => '<img src=x>', 'price' => '& < >'],
        ], $titles, $expectedTitles, [
            $first,
            ['sku' => 'B', 'quantity' => 3, 'price' => '21.00'],
            ['sku' => 'C', 'quantity' => null, 'price' => '0.00'],
            ['sku' => 'D', 'quantity' => 0, 'price' => false],
            ['sku' => '', 'quantity' => '0', 'price' => true],
            ['sku' => '<script>alert("x")</script>', 'quantity' => '<img src=x>', 'price' => '& < >'],
        ]);
    }

    public function test_configured_order_and_subset_do_not_follow_input_order(): void
    {
        $lines = [['sku' => 'A', 'quantity' => 2, 'price' => 10], ['sku' => 'B']];
        $expected = [['price' => 10, 'sku' => 'A'], ['price' => null, 'sku' => 'B']];
        $this->assertTable($lines, ['price' => 'Price', 'sku' => 'SKU'], ['price' => 'Price', 'sku' => 'SKU'], $expected);
        $this->assertTable($lines, ['price', 'sku'], ['price' => 'price', 'sku' => 'sku'], $expected);
    }

    public function test_dotted_and_numeric_column_keys_are_literal(): void
    {
        $lines = [
            ['meta.sku' => 'literal', 'meta' => ['sku' => 'nested'], '01' => 'leading zero', 0 => 'zero'],
            ['meta' => ['sku' => 'must not be used'], 0 => false],
        ];
        $expected = [
            ['meta.sku' => 'literal', 0 => 'zero', '01' => 'leading zero'],
            ['meta.sku' => null, 0 => false, '01' => null],
        ];
        $this->assertTable($lines, ['meta.sku' => 'SKU', 0 => 'Zero', '01' => 'Code'], ['meta.sku' => 'SKU', 0 => 'Zero', '01' => 'Code'], $expected);
        $this->assertTable($lines, ['meta.sku', 0, '01'], ['meta.sku' => 'meta.sku', 0 => 0, '01' => '01'], $expected);
    }

    public function test_inferred_headers_still_use_only_the_first_row_in_its_order(): void
    {
        $this->assertTable([
            ['price' => '10.00', 'sku' => 'A'],
            ['sku' => 'B', 'quantity' => 2],
        ], [], ['price' => 'price', 'sku' => 'sku'], [
            ['price' => '10.00', 'sku' => 'A'],
            ['price' => null, 'sku' => 'B'],
        ]);
        $this->assertTable([[], ['sku' => 'B']], [], [], [[], []]);
    }

    private function displayer($value): Table
    {
        $grid = new Grid(new GridTableItem());

        return new Table($value, $grid, $grid->column('lines'), new GridTableItem(['id' => 1]));
    }

    public function test_empty_top_level_values_keep_the_empty_output_contract(): void
    {
        foreach ([null, []] as $lines) {
            $this->assertSame('', trim($this->renderLines($lines, ['sku'])->html()));
            $this->assertSame([], $this->tableViews);
        }
        foreach ([null, [], false, 0, 0.0, '', '0'] as $value) {
            $this->assertSame('', $this->displayer($value)->display());
            $this->assertSame('', $this->displayer($value)->display(['sku']));
        }
    }

    public function test_nonempty_nonarray_top_level_values_are_not_newly_supported(): void
    {
        foreach (['sku', true, 1, 1.5, new \stdClass(), collect([['sku' => 'A']])] as $value) {
            try {
                $this->displayer($value)->display(['sku']);
                $this->fail('Nonarray top-level values must retain their TypeError.');
            } catch (\TypeError $exception) {
                $this->assertStringContainsString('array_map()', $exception->getMessage());
            }
        }
    }

    public function test_nonarray_rows_are_not_newly_coerced_to_empty_rows(): void
    {
        foreach ([null, false, 0, '', 'sku', new \stdClass(), collect(['sku' => 'A'])] as $row) {
            try {
                $this->displayer([$row])->display(['sku']);
                $this->fail('Nonarray rows must retain their TypeError.');
            } catch (\TypeError $exception) {
                $this->assertStringContainsString('array_intersect_key()', $exception->getMessage());
            }
        }
    }
}
