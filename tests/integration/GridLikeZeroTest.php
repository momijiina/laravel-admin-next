<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter\EndsWith;
use Encore\Admin\Grid\Filter\Ilike;
use Encore\Admin\Grid\Filter\Like;
use Encore\Admin\Grid\Filter\StartsWith;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridLikeZeroRecord extends Model
{
    protected $table = 'grid_like_zero_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridLikeZeroTest extends TestCase
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
        $router->get('/grid-like-zero/{mode}/{naming}', function ($mode, $naming) {
            abort_unless(in_array($mode, ['like', 'startsWith', 'endsWith'], true), 404);
            abort_unless(in_array($naming, ['plain', 'named'], true), 404);
            $grid = new Grid(new GridLikeZeroRecord());
            $grid->paginate(20);
            $grid->model()->orderBy('id');
            if ($naming === 'named') {
                $grid->setName('catalog');
            }
            $filter = $grid->getFilter();
            $filter->disableIdFilter();
            $filter->$mode('code')->default('fallback');
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $data = $filter->execute();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            return [
                'rows' => array_column($data, 'id'),
                'html' => (string) $filter->render(),
                'queries' => $queries,
            ];
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
        Schema::create('grid_like_zero_records', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
        });
        foreach (['0', '01', '10', 'A0B', 'ABC', '00', '', null] as $code) {
            GridLikeZeroRecord::create(['code' => $code]);
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

    public static function modes(): iterable
    {
        foreach (['plain', 'named'] as $naming) {
            yield "like $naming" => ['like', $naming, '%0%', [1, 2, 3, 4, 6]];
            yield "startsWith $naming" => ['startsWith', $naming, '0%', [1, 2, 6]];
            yield "endsWith $naming" => ['endsWith', $naming, '%0', [1, 3, 6]];
        }
    }

    #[DataProvider('modes')]
    public function test_zero_searches_and_unchanged_native_submissions_keep_the_query(string $mode, string $naming, string $pattern, array $rows): void
    {
        $path = '/grid-like-zero/'.$mode.'/'.$naming;
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        $result = $this->get($path.'?'.http_build_query([$name => '0']))->assertOk()->json();
        for ($round = 0; $round < 3; $round++) {
            $this->assertSame($rows, $result['rows']);
            $this->assertQueries($result, $pattern);
            $this->assertSame('0', (new Crawler($result['html']))->filter('input[name="'.$name.'"]')->attr('value'));
            $native = $this->nativeForm($result, $path, $name);
            $this->assertSame([[$name, '0']], $native['initial']['entries']);
            $result = $this->get($native['initial']['uri'])->assertOk()->json();
        }
        foreach ([$native['cleared']['uri'], $native['reset']] as $reset) {
            $result = $this->get($reset)->assertOk()->json();
            $this->assertSame(range(1, 8), $result['rows']);
            $this->assertQueries($result, null);
        }
        foreach (['00' => [6], 'ABC' => [5]] as $value => $expected) {
            $result = $this->get($path.'?'.http_build_query([$name => $value]))->assertOk()->json();
            $this->assertSame($expected, $result['rows']);
            $this->assertQueries($result, str_replace('0', $value, $pattern));
        }
        $this->assertSame(8, GridLikeZeroRecord::count());
    }

    public static function conditionInputs(): iterable
    {
        foreach ([Like::class => '%{value}%', StartsWith::class => '{value}%',
            EndsWith::class => '%{value}', Ilike::class => '%{value}%'] as $class => $format) {
            foreach (['zero string' => '0', 'zero integer' => 0, 'leading zeros' => '00', 'ordinary' => 'A0B'] as $label => $value) {
                yield class_basename($class).' '.$label => [$class, $value, str_replace('{value}', (string) $value, $format)];
            }
            foreach (['null' => null, 'blank' => '', 'false' => false, 'empty array' => [],
                'blank array' => ['', null], 'legacy zero array' => ['0'], 'legacy float zero' => 0.0] as $label => $value) {
                yield class_basename($class).' '.$label => [$class, $value, null];
            }
        }
    }

    #[DataProvider('conditionInputs')]
    public function test_scalar_zero_conditions_and_legacy_empty_controls(string $class, $value, ?string $pattern): void
    {
        $filter = new $class('code');
        $condition = $filter->condition(['code' => $value]);
        $this->assertSame($pattern === null ? null : ['where' => ['code', $class === Ilike::class ? 'ilike' : 'like', $pattern]], $condition);
        $this->assertNull((new $class('code'))->condition([]));
        if ($pattern !== null) {
            $this->assertSame($value, $filter->getValue());
        }
    }

    private function assertQueries(array $result, ?string $pattern): void
    {
        $queries = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_like_zero_records"')));
        $this->assertCount(2, $queries, 'Check both paginator count and row query.');
        foreach ($queries as $query) {
            $this->assertSame($pattern === null ? [] : [$pattern], $query['bindings']);
            if ($pattern === null) {
                $this->assertStringNotContainsString(' where ', $query['query']);
            } else {
                $this->assertStringContainsString('"code" like ?', $query['query']);
            }
        }
    }

    private function nativeForm(array $result, string $path, string $name): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-like-zero.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode(['html' => $result['html'], 'url' => 'http://localhost'.$path, 'name' => $name], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
