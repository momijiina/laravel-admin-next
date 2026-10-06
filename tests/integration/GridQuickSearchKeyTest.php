<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Tools\QuickSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridQuickSearchKeyItem extends Model
{
    protected $table = 'grid_quick_search_key_items';
    public $timestamps = false;
    protected $guarded = [];
}

class GridQuickSearchKeySubclass extends Grid
{
    public static $searchKey = 'subclass_search';
}

/** Shipped header tools and native GET controls through the HTTP kernel and SQLite. */
class GridQuickSearchKeyTest extends TestCase
{
    private $packageState = [];

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
        $router->get('/grid-search-key/{mode}/{key}', function ($mode, $key) {
            Grid::$searchKey = $key === 'default' ? '__search__' : 'catalog_search';
            $grid = new Grid(new GridQuickSearchKeyItem());
            $grid->disableActions()->disableRowSelector();
            $grid->tools->disableFilterButton();
            $grid->column('id');
            $grid->column('name');
            $grid->column('note');
            $grid->paginate(20);
            $grid->model()->orderBy('id');
            $captured = [];
            if ($mode === 'closure') {
                $grid->quickSearch(function ($model, $query) use (&$captured) {
                    $captured[] = $query;
                    $model->where('name', 'like', '%'.$query.'%');
                });
            } elseif ($mode === 'columns') {
                $grid->quickSearch(['name', 'note']);
            } else {
                $grid->quickSearch('name');
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $grid->build();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            return [
                'rows' => $grid->rows()->map->getKey()->all(),
                'total' => $grid->model()->eloquent()->total(),
                'queries' => $queries,
                'captured' => $captured,
                // Exercise normal Tools::render() and AbstractTool::setGrid().
                'html' => (string) $grid->renderHeaderTools(),
            ];
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script'],
            Grid::class => ['snakeAttributes', 'searchKey'],
            Grid\Column::class => ['htmlAttributes', 'model'],
            GridQuickSearchKeySubclass::class => ['searchKey'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        parent::setUp();
        Schema::create('grid_quick_search_key_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('note');
        });
        foreach ([['zero0', 'a'], ['one1', 'b'], ['two2', '0'], ['0', 'c'], ['10', 'd']] as [$name, $note]) {
            GridQuickSearchKeyItem::create(compact('name', 'note'));
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->packageState as [$property, $value]) {
                $property->setValue(null, $value);
                $this->assertSame($value, $property->getValue(), 'Restore shared static '.$property->getName());
            }
        }
    }

    public static function submissions(): iterable
    {
        foreach (['column', 'columns', 'closure'] as $mode) {
            foreach (['default', 'custom'] as $key) {
                foreach (['1', '', 'missing', '0'] as $query) {
                    yield "$mode $key query=$query" => [$mode, $key, $query];
                }
            }
        }
    }

    #[DataProvider('submissions')]
    public function test_native_form_and_direct_query_use_the_configured_key(string $mode, string $key, string $query): void
    {
        $path = '/grid-search-key/'.$mode.'/'.$key;
        $expectedKey = $key === 'default' ? '__search__' : 'catalog_search';
        // Preserve the existing falsey-query short-circuit, including string zero.
        $expectedRows = match ($query) {
            '1' => [2, 5],
            '', '0' => [1, 2, 3, 4, 5],
            default => [],
        };
        $initial = $this->get($path)->assertOk()->json();
        $this->assertResult($initial, $mode, '', [1, 2, 3, 4, 5]);
        $direct = $this->get($path.'?'.http_build_query([
            $expectedKey => $query, 'context' => 'keep',
        ]))->assertOk()->json();
        $this->assertResult($direct, $mode, $query, $expectedRows);
        $this->assertForm($direct['html'], $expectedKey, $query, ['context' => 'keep']);

        $native = $this->submitForm($initial['html'], $path, $query);
        $this->assertSame([[$expectedKey, $query]], $native['entries']);
        $submitted = $this->get($native['uri'])->assertOk()->json();
        $this->assertResult($submitted, $mode, $query, $expectedRows);
        $this->assertForm($submitted['html'], $expectedKey, $query, []);
        $this->assertSame(
            [
                ['name' => 'zero0', 'note' => 'a'], ['name' => 'one1', 'note' => 'b'],
                ['name' => 'two2', 'note' => '0'], ['name' => '0', 'note' => 'c'],
                ['name' => '10', 'note' => 'd'],
            ],
            GridQuickSearchKeyItem::orderBy('id')->get(['name', 'note'])->toArray()
        );
    }

    public static function rendering(): iterable
    {
        foreach (['header', 'bound', 'standalone', 'subclass'] as $binding) {
            foreach ([false, true] as $late) {
                yield $binding.($late ? ' late change' : ' initial key') => [$binding, $late];
            }
        }
    }

    #[DataProvider('rendering')]
    public function test_render_time_key_selection_and_unrelated_query_preservation(string $binding, bool $late): void
    {
        Grid::$searchKey = '__search__';
        GridQuickSearchKeySubclass::$searchKey = 'subclass_search';
        $class = $binding === 'subclass' ? GridQuickSearchKeySubclass::class : Grid::class;
        $key = $binding === 'subclass' ? 'subclass_search' : 'catalog_search';
        if (!$late) {
            $class::$searchKey = $key;
        }
        $grid = new $class(new GridQuickSearchKeyItem());
        $grid->tools->disableFilterButton();
        if ($binding === 'header' || $binding === 'subclass') {
            $tool = $grid->quickSearch('name');
        } else {
            $tool = new QuickSearch();
            if ($binding === 'bound') {
                $tool->setGrid($grid);
            }
        }
        if ($late) {
            $key = $binding === 'subclass' ? 'late_subclass_search' : 'late_catalog_search';
            $class::$searchKey = $key;
        }
        $other = ['__search__' => 'legacy', 'filter' => ['active' => '1'], 'context' => 'keep'];
        $value = 'A&B "quoted" <tag>';
        $this->app->instance('request', Request::create('/admin/items', 'GET', [$key => $value] + $other));
        $tool->placeholder('Find & keep');
        $html = (string) ($binding === 'header' || $binding === 'subclass'
            ? $grid->renderHeaderTools() : $tool->render());
        $this->assertForm($html, $key, $value, $other);
        $crawler = new Crawler($html);
        $this->assertSame('Find & keep', $crawler->filter('input.grid-quick-search')->attr('placeholder'));
        $this->assertSame($binding === 'standalone' ? null : $grid, $tool->getGrid());
        $this->assertSame($binding === 'subclass' ? '__search__' : $key, Grid::$searchKey);
        $this->assertSame($binding === 'subclass' ? $key : 'subclass_search', GridQuickSearchKeySubclass::$searchKey);
    }

    public function test_repeated_header_rendering_tracks_default_and_custom_keys_without_caching(): void
    {
        $grid = new Grid(new GridQuickSearchKeyItem());
        $grid->tools->disableFilterButton();
        $tool = $grid->quickSearch('name');
        $standalone = new QuickSearch();
        foreach (['catalog_search', '__search__', 'catalog_search', '__search__'] as $key) {
            Grid::$searchKey = $key;
            $this->app->instance('request', Request::create('/admin/items', 'GET', [$key => 'one1']));
            $this->assertForm((string) $grid->renderHeaderTools(), $key, 'one1', []);
            $this->assertForm((string) $standalone->render(), $key, 'one1', []);
        }
        $tool->disable();
        $disabled = new Crawler((string) $grid->renderHeaderTools());
        $this->assertCount(0, $disabled->filter('input.grid-quick-search'));
        $this->assertCount(1, $disabled->filter('.grid-select-all-btn'));
    }

    private function assertForm(string $html, string $key, string $value, array $other): void
    {
        $crawler = new Crawler($html);
        $this->assertCount(1, $crawler->filter('form[pjax-container]'));
        $this->assertCount(1, $crawler->filter('input.grid-quick-search'));
        $input = $crawler->filter('input.grid-quick-search');
        $this->assertSame($key, $input->attr('name'));
        $this->assertSame($value, $input->attr('value'));
        $action = $crawler->filter('form')->attr('action');
        $this->assertSame(request()->url(), strtok($action, '?'));
        parse_str(parse_url($action, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame($other, $query);
    }

    private function assertResult(array $result, string $mode, string $query, array $rows): void
    {
        $this->assertSame($rows, $result['rows']);
        $this->assertSame(count($rows), $result['total']);
        $this->assertSame($mode === 'closure' && $query ? [$query] : [], $result['captured']);
        $queries = array_values(array_filter($result['queries'], function ($item) {
            return str_contains($item['query'], '"grid_quick_search_key_items"');
        }));
        $this->assertCount($rows ? 2 : 1, $queries);
        foreach ($queries as $item) {
            if (!$query) {
                $this->assertStringNotContainsString(' where ', $item['query']);
                $this->assertSame([], $item['bindings']);
            } else {
                $this->assertStringContainsString('"name" like ?', $item['query']);
                if ($mode === 'columns') {
                    $this->assertStringContainsString('or "note" like ?', $item['query']);
                }
                $this->assertSame(array_fill(0, $mode === 'columns' ? 2 : 1, '%'.$query.'%'), $item['bindings']);
            }
        }
    }

    private function submitForm(string $html, string $path, string $query): array
    {
        $url = 'http://localhost'.$path;
        $process = new Process(['node', __DIR__.'/javascript/grid-quick-search-form.cjs']);
        $process->setInput(json_encode(compact('html', 'url', 'query'), JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $crawler = new Crawler($html, $url);
        $name = $crawler->filter('input.grid-quick-search')->attr('name');
        $this->assertSame($crawler->filter('form')->form([$name => $query])->getUri(), $native['uri']);

        return $native;
    }
}
