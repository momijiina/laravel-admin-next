<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column\InputFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridColumnInputZeroRecord extends Model
{
    protected $table = 'grid_column_input_zero_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridColumnInputZeroTest extends TestCase
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
        $router->get('/grid-column-input/{mode}', function ($mode) {
            abort_unless(in_array($mode, ['default', 'equal', 'like', 'date', 'time', 'datetime'], true), 404);
            $grid = new Grid(new GridColumnInputZeroRecord());
            $grid->paginate(20);
            $grid->model()->orderBy('id');
            $column = $grid->column($mode === 'date' || $mode === 'time' || $mode === 'datetime' ? 'occurred_at' : 'code');
            if ($mode === 'default') {
                $column->filter();
            } else {
                $column->filter($mode);
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

            return ['rows' => array_column($rows, 'id'), 'queries' => $queries, 'html' => $column->renderHeader()];
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
        Schema::create('grid_column_input_zero_records', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->dateTime('occurred_at')->nullable();
        });
        foreach (['0', '01', '10', 'A0B', 'ABC', '00', '', null] as $index => $code) {
            GridColumnInputZeroRecord::create([
                'code' => $code,
                'occurred_at' => $index === 0 ? '2026-10-08 09:30:00' : '2026-10-09 11:45:00',
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

    public static function searchModes(): iterable
    {
        yield 'default equal' => ['default', '=', '0', [1]];
        yield 'explicit equal' => ['equal', '=', '0', [1]];
        yield 'like' => ['like', 'like', '%0%', [1, 2, 3, 4, 6]];
    }

    #[DataProvider('searchModes')]
    public function test_zero_header_search_and_native_resubmission_keep_the_query(string $mode, string $operator, string $binding, array $rows): void
    {
        $path = '/grid-column-input/'.$mode;
        $result = $this->get($path.'?code=0')->assertOk()->json();
        for ($round = 0; $round < 3; $round++) {
            $this->assertSame($rows, $result['rows']);
            $this->assertQueries($result, '"code" '.$operator.' ?', [$binding]);
            $this->assertHeader($result, 'code', '0', true);
            $native = $this->nativeForm($result, $path);
            $this->assertSame([['code', '0']], $native['initial']['entries']);
            $this->assertSame([['code', '']], $native['cleared']['entries']);
            $this->assertSame([['code', '0']], $native['reselected']['entries']);
            $result = $this->get($native['initial']['uri'])->assertOk()->json();
        }
        foreach ([$native['cleared']['uri'], $native['reset']] as $reset) {
            $result = $this->get($reset)->assertOk()->json();
            $this->assertSame(range(1, 8), $result['rows']);
            $this->assertQueries($result, null, []);
            $this->assertHeader($result, 'code', '', false);
        }
        $result = $this->get($native['reselected']['uri'])->assertOk()->json();
        $this->assertSame($rows, $result['rows']);
        $this->assertQueries($result, '"code" '.$operator.' ?', [$binding]);
        $this->assertHeader($result, 'code', '0', true);
        foreach (['00' => [6], 'ABC' => [5], 'A0B' => [4]] as $value => $expected) {
            $result = $this->get($path.'?'.http_build_query(['code' => $value]))->assertOk()->json();
            $this->assertSame($expected, $result['rows']);
            $this->assertQueries($result, '"code" '.$operator.' ?', [$mode === 'like' ? '%'.$value.'%' : $value]);
            $this->assertHeader($result, 'code', $value, true);
        }
        $this->assertSame(8, GridColumnInputZeroRecord::count());
    }

    public static function temporalModes(): iterable
    {
        yield 'date' => ['date', '2026-10-08'];
        yield 'time' => ['time', '09:30:00'];
        yield 'datetime' => ['datetime', '2026-10-08 09:30:00'];
    }

    #[DataProvider('temporalModes')]
    public function test_temporal_values_and_legacy_zero_remain_unchanged(string $mode, string $value): void
    {
        $path = '/grid-column-input/'.$mode;
        $result = $this->get($path.'?'.http_build_query(['occurred_at' => $value]))->assertOk()->json();
        $this->assertSame([1], $result['rows']);
        $this->assertQueries($result, 'occurred_at', [$value]);
        $this->assertHeader($result, 'occurred_at', $value, true);
        foreach (['0', ''] as $empty) {
            $result = $this->get($path.'?'.http_build_query(['occurred_at' => $empty]))->assertOk()->json();
            $this->assertSame(range(1, 8), $result['rows']);
            $this->assertQueries($result, null, []);
            $this->assertHeader($result, 'occurred_at', $empty, false);
        }
    }

    public static function bindingInputs(): iterable
    {
        foreach (['equal', 'like', 'date', 'time', 'datetime'] as $mode) {
            foreach (['integer zero' => 0, 'string zero' => '0'] as $label => $value) {
                yield $mode.' '.$label => [$mode, $value, in_array($mode, ['equal', 'like'], true)];
            }
            foreach (['null' => null, 'blank' => '', 'false' => false, 'float zero' => 0.0, 'empty array' => []] as $label => $value) {
                yield $mode.' '.$label => [$mode, $value, false];
            }
            yield $mode.' ordinary' => [$mode, '2026-10-08', true];
        }
    }

    #[DataProvider('bindingInputs')]
    public function test_query_binding_and_active_indicator_agree_for_scalar_zero_and_legacy_empty_values(string $mode, $value, bool $active): void
    {
        $filter = new InputFilter($mode);
        $filter->setParent(new Grid\Column('code', 'Code'));
        $model = new Grid\Model(new GridColumnInputZeroRecord());
        $filter->addBinding($value, $model);
        $property = new \ReflectionProperty($model, 'queries');
        $queries = $property->getValue($model)->all();
        if ($active) {
            $method = $mode === 'date' ? 'whereDate' : ($mode === 'time' ? 'whereTime' : 'where');
            $arguments = $mode === 'like' ? ['code', 'like', '%'.$value.'%'] : ['code', $value];
            $this->assertSame([['method' => $method, 'arguments' => $arguments]], $queries);
        } else {
            $this->assertSame([], $queries);
        }
        // Arrays are not a supported text-rendering input; preserve their binding behavior only.
        if (!is_array($value)) {
            $this->app['request']->query->set('code', $value);
            $this->assertSame($active, (new Crawler($filter->render()))->filter('.dropdown-toggle.text-yellow')->count() === 1);
        }
    }

    private function assertHeader(array $result, string $name, string $value, bool $active): void
    {
        $crawler = new Crawler($result['html']);
        $this->assertSame($value, $crawler->filter('input[name="'.$name.'"]')->attr('value'));
        $this->assertSame($active, $crawler->filter('.dropdown-toggle.text-yellow')->count() === 1);
    }

    private function assertQueries(array $result, ?string $predicate, array $bindings): void
    {
        $queries = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_column_input_zero_records"')));
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

    private function nativeForm(array $result, string $path): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-column-input-zero.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode(['html' => $result['html'], 'url' => 'http://localhost'.$path], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
