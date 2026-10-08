<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column\RangeFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridColumnRangeZeroRecord extends Model
{
    protected $table = 'grid_column_range_zero_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridColumnRangeZeroTest extends TestCase
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->get('/grid-column-range/{mode}', function ($mode) {
            abort_unless(in_array($mode, ['default', 'equal', 'date', 'time', 'datetime'], true), 404);
            $grid = new Grid(new GridColumnRangeZeroRecord());
            $grid->paginate(20);
            $grid->model()->orderBy('id');
            $column = $grid->column(in_array($mode, ['default', 'equal'], true) ? 'quantity' : 'occurred_at');
            if ($mode === 'default') {
                $column->filter('range');
            } else {
                $column->filter('range', $mode);
            }
            $grid->getFilter()->disableIdFilter();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $grid->applyQuery();
                $rows = $grid->getFilter()->execute();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            return ['rows' => array_column($rows, 'quantity'), 'queries' => $queries, 'html' => $column->renderHeader()];
        });
    }

    protected function setUp(): void
    {
        foreach ([Admin::class => ['script'], Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'model']] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        parent::setUp();
        $this->withoutExceptionHandling();
        Schema::create('grid_column_range_zero_records', function ($table) {
            $table->increments('id');
            $table->integer('quantity');
            $table->dateTime('occurred_at');
        });
        foreach (range(-3, 3) as $quantity) {
            GridColumnRangeZeroRecord::create([
                'quantity' => $quantity,
                'occurred_at' => '2026-10-'.sprintf('%02d', $quantity + 5).' 09:30:00',
            ]);
        }
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

    public static function numericRanges(): iterable
    {
        $ranges = [
            'zero start' => [['start' => '0', 'end' => '2'], [0, 1, 2], 'between ? and ?', ['0', '2']],
            'zero end' => [['start' => '-2', 'end' => '0'], [-2, -1, 0], 'between ? and ?', ['-2', '0']],
            'both zero' => [['start' => '0', 'end' => '0'], [0], 'between ? and ?', ['0', '0']],
            // Preserve the existing strict one-sided comparison operators.
            'zero start only' => [['start' => '0', 'end' => ''], [1, 2, 3], '> ?', ['0']],
            'zero end only' => [['start' => '', 'end' => '0'], [-3, -2, -1], '< ?', ['0']],
            'positive' => [['start' => '1', 'end' => '2'], [1, 2], 'between ? and ?', ['1', '2']],
            'negative' => [['start' => '-2', 'end' => '-1'], [-2, -1], 'between ? and ?', ['-2', '-1']],
            'cross zero' => [['start' => '-1', 'end' => '1'], [-1, 0, 1], 'between ? and ?', ['-1', '1']],
            'positive start only' => [['start' => '1', 'end' => ''], [2, 3], '> ?', ['1']],
            'negative end only' => [['start' => '', 'end' => '-1'], [-3, -2], '< ?', ['-1']],
            'blank' => [['start' => '', 'end' => ''], range(-3, 3), null, []],
        ];
        foreach (['default', 'equal'] as $mode) {
            foreach ($ranges as $label => $range) {
                yield $mode.' '.$label => array_merge([$mode], $range);
            }
        }
    }

    #[DataProvider('numericRanges')]
    public function test_native_numeric_range_submissions_keep_zero_bounds(string $mode, array $bounds, array $rows, ?string $operator, array $bindings): void
    {
        $path = '/grid-column-range/'.$mode;
        $result = $this->get($path)->assertOk()->json();
        $native = $this->nativeForm($result, $path, $bounds);
        $this->assertSame([['quantity[start]', $bounds['start']], ['quantity[end]', $bounds['end']]], $native['initial']['entries']);
        $result = $this->get($native['initial']['uri'])->assertOk()->json();
        for ($round = 0; $round < 3; $round++) {
            $this->assertSame($rows, $result['rows']);
            $this->assertQueries($result, $operator === null ? null : '"quantity" '.$operator, $bindings);
            $this->assertHeader($result, 'quantity', $bounds, $operator !== null);
            $native = $this->nativeForm($result, $path);
            $this->assertSame([['quantity[start]', $bounds['start']], ['quantity[end]', $bounds['end']]], $native['initial']['entries']);
            $result = $this->get($native['initial']['uri'])->assertOk()->json();
        }
        foreach ([$native['cleared']['uri'], $native['reset']] as $reset) {
            $result = $this->get($reset)->assertOk()->json();
            $this->assertSame(range(-3, 3), $result['rows']);
            $this->assertQueries($result, null, []);
            $this->assertHeader($result, 'quantity', ['start' => '', 'end' => ''], false);
        }
        $result = $this->get($native['reselected']['uri'])->assertOk()->json();
        $this->assertSame([0], $result['rows']);
        $this->assertQueries($result, '"quantity" between ? and ?', ['0', '0']);
        $this->assertHeader($result, 'quantity', ['start' => '0', 'end' => '0'], true);
        $this->assertSame(range(-3, 3), GridColumnRangeZeroRecord::orderBy('id')->pluck('quantity')->all());
    }

    public static function directBounds(): iterable
    {
        foreach (['equal', 'date', 'time', 'datetime', 'like', 'custom'] as $mode) {
            foreach (['integer zero' => 0, 'string zero' => '0', 'null' => null, 'blank' => '',
                'false' => false, 'float zero' => 0.0, 'empty array' => []] as $label => $value) {
                foreach (['start', 'end', 'both'] as $position) {
                    yield $mode.' '.$label.' '.$position => [$mode, $position, $value, $mode === 'equal' && ($value === 0 || $value === '0')];
                }
            }
        }
    }

    #[DataProvider('directBounds')]
    public function test_query_binding_and_indicator_agree_for_zero_and_legacy_empty_bounds(string $mode, string $position, $value, bool $active): void
    {
        $filter = new RangeFilter($mode);
        $filter->setParent(new Grid\Column('quantity', 'Quantity'));
        $bounds = $position === 'both' ? ['start' => $value, 'end' => $value] : [$position => $value];
        $model = new Grid\Model(new GridColumnRangeZeroRecord());
        $filter->addBinding($bounds, $model);
        $queries = (new \ReflectionProperty($model, 'queries'))->getValue($model)->all();
        if (!$active) {
            $this->assertSame([], $queries);
        } elseif ($position === 'both') {
            $this->assertSame([['method' => 'whereBetween', 'arguments' => ['quantity', [$value, $value]]]], $queries);
        } else {
            $this->assertSame([['method' => 'where', 'arguments' => ['quantity', $position === 'start' ? '>' : '<', $value]]], $queries);
        }
        // Array-valued bounds are not a supported input rendering format.
        if (!is_array($value)) {
            $this->app['request']->query->set('quantity', $bounds);
            $this->assertSame($active, (new Crawler($filter->render()))->filter('.dropdown-toggle.text-yellow')->count() === 1);
        }
    }

    public static function temporalModes(): iterable
    {
        foreach (['date', 'time', 'datetime'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('temporalModes')]
    public function test_temporal_range_queries_and_zero_handling_remain_unchanged(string $mode): void
    {
        $path = '/grid-column-range/'.$mode;
        // Range query dispatch has always compared the underlying column directly.
        $start = '2026-10-04 09:30:00';
        $end = '2026-10-06 09:30:00';
        foreach ([
            [['start' => $start, 'end' => $end], [-1, 0, 1], 'between ? and ?', [$start, $end]],
            [['start' => $start, 'end' => ''], [0, 1, 2, 3], '> ?', [$start]],
            [['start' => '0', 'end' => $end], [-3, -2, -1, 0], '< ?', [$end]],
            [['start' => $start, 'end' => '0'], [0, 1, 2, 3], '> ?', [$start]],
            [['start' => '0', 'end' => '0'], range(-3, 3), null, []],
            [['start' => '', 'end' => ''], range(-3, 3), null, []],
        ] as [$bounds, $rows, $operator, $bindings]) {
            $result = $this->get($path.'?'.http_build_query(['occurred_at' => $bounds]))->assertOk()->json();
            $this->assertSame($rows, $result['rows']);
            $this->assertQueries($result, $operator === null ? null : '"occurred_at" '.$operator, $bindings);
            $this->assertHeader($result, 'occurred_at', $bounds, $operator !== null);
        }
    }

    private function assertHeader(array $result, string $name, array $bounds, bool $active): void
    {
        $crawler = new Crawler($result['html']);
        foreach ($bounds as $key => $value) {
            $this->assertSame($value, $crawler->filter('input[name="'.$name.'['.$key.']"]')->attr('value'));
        }
        $this->assertSame($active, $crawler->filter('.dropdown-toggle.text-yellow')->count() === 1);
    }

    private function assertQueries(array $result, ?string $predicate, array $bindings): void
    {
        $queries = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_column_range_zero_records"')));
        $this->assertCount(2, $queries, 'Check both paginator count and row queries.');
        foreach ($queries as $query) {
            $this->assertSame($bindings, $query['bindings']);
            if ($predicate === null) {
                $this->assertStringNotContainsString(' where ', $query['query']);
            } else {
                $this->assertStringContainsString($predicate, $query['query']);
                $this->assertStringContainsString(' where ', $query['query']);
            }
        }
    }

    private function nativeForm(array $result, string $path, ?array $bounds = null): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-column-range-zero.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode(['html' => $result['html'], 'url' => 'http://localhost'.$path, 'bounds' => $bounds], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
