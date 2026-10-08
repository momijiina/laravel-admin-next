<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridCheckboxFilterRecord extends Model
{
    protected $table = 'grid_checkbox_filter_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridCheckboxFilterTest extends TestCase
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
        $router->get('/grid-checkbox/{operator}/{naming}/{layout}', function ($operator, $naming, $layout) {
            abort_unless(in_array($operator, ['in', 'notIn'], true), 404);
            abort_unless(in_array($naming, ['plain', 'named'], true), 404);
            abort_unless(in_array($layout, ['container', 'modal'], true), 404);
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                $grid = new Grid(new GridCheckboxFilterRecord());
                if ($naming === 'named') {
                    $grid->setName('catalog');
                }
                $grid->model()->orderBy('id');
                $filter = $grid->getFilter()->disableIdFilter();
                if ($layout === 'modal') {
                    $filter->useModal();
                }
                $filter->$operator('code')->checkbox([0 => 'Zero', 1 => 'One', 'alpha' => 'Alpha']);
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $rows = array_column($filter->execute(), 'id');
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }

                return [
                    'rows' => $rows,
                    'queries' => $queries,
                    'html' => (string) $filter->render(),
                    'scriptHtml' => Admin::script()->render(),
                ];
            } finally {
                Admin::$script = $previous;
            }
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
        Schema::create('grid_checkbox_filter_records', function ($table) {
            $table->increments('id');
            $table->string('code');
        });
        foreach (['0', '1', 'alpha'] as $code) {
            GridCheckboxFilterRecord::create(['code' => $code]);
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

    public static function layouts(): iterable
    {
        foreach (['in', 'notIn'] as $operator) {
            foreach (['plain', 'named'] as $naming) {
                foreach (['container', 'modal'] as $layout) {
                    yield "$operator $naming $layout" => [$operator, $naming, $layout];
                }
            }
        }
    }

    public static function scalarLinks(): iterable
    {
        foreach (self::layouts() as $label => $layout) {
            foreach (['0', '1', 'alpha'] as $value) {
                yield "$label $value" => [...$layout, $value];
            }
        }
    }

    #[DataProvider('scalarLinks')]
    public function test_scalar_links_render_and_resubmit_like_one_item_arrays(string $operator, string $naming, string $layout, string $value): void
    {
        $path = "/grid-checkbox/$operator/$naming/$layout";
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        $array = $this->get($path.'?'.http_build_query([$name => [$value]]))->assertOk()->json();
        $scalar = $this->get($path.'?'.http_build_query([$name => $value]))->assertOk()->json();
        $expectedRows = $this->expectedRows($operator, [$value]);
        $this->assertSame($expectedRows, $array['rows']);
        $this->assertSame($expectedRows, $scalar['rows']);
        $this->assertSame(array_column($array['queries'], 'bindings'), array_column($scalar['queries'], 'bindings'));
        $this->assertSame([$value], $this->checked($array['html']));
        $this->assertSame([$value], $this->checked($scalar['html']));

        for ($round = 0; $round < 2; $round++) {
            $native = $this->nativeForm($scalar, $path, $name);
            $this->assertSame([$value], $native['initial']['checked']);
            $this->assertSame([[$name.'[]', $value]], $native['initial']['entries']);
            $scalar = $this->get($native['initial']['uri'])->assertOk()->json();
            $this->assertSame($expectedRows, $scalar['rows']);
            $this->assertSame([$value], $this->checked($scalar['html']));
        }
        $this->assertSame([], $native['cleared']['checked']);
        $this->assertSame([], $native['cleared']['entries']);
        $clear = $this->get($native['cleared']['uri'])->assertOk()->json();
        $this->assertSame([1, 2, 3], $clear['rows']);
        $this->assertSame([], $this->checked($clear['html']));
        $this->assertSame(['0', 'alpha'], $native['chosen']['checked']);
        $chosen = $this->get($native['chosen']['uri'])->assertOk()->json();
        $this->assertSame($this->expectedRows($operator, ['0', 'alpha']), $chosen['rows']);
        $this->assertSame(['0', 'alpha'], $this->checked($chosen['html']));
        $this->assertSame(['0', '1', 'alpha'], GridCheckboxFilterRecord::orderBy('id')->pluck('code')->all());
    }

    #[DataProvider('layouts')]
    public function test_array_blank_and_absent_query_controls_keep_their_behavior(string $operator, string $naming, string $layout): void
    {
        $path = "/grid-checkbox/$operator/$naming/$layout";
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        foreach ([
            [[], []],
            [[$name => ''], []],
            [[$name => []], []],
            [[$name => ['0', 'alpha']], ['0', 'alpha']],
            [[$name => [3 => '1', 9 => 'alpha']], ['1', 'alpha']],
        ] as [$query, $selected]) {
            $result = $this->get($path.'?'.http_build_query($query))->assertOk()->json();
            $this->assertSame($this->expectedRows($operator, $selected), $result['rows']);
            $this->assertSame($selected, $this->checked($result['html']));
        }
    }

    public function test_view_retains_loose_membership_and_null_empty_behavior(): void
    {
        foreach ([[[0], ['0', '00']], [null, []], [[], []], [['alpha'], ['alpha']]] as [$value, $expected]) {
            $html = view('admin::filter.checkbox', [
                'options' => [0 => 'Zero', '00' => 'Leading zeros', 'alpha' => 'Alpha'],
                'id' => 'code', 'name' => 'code', 'inline' => true, 'value' => $value,
            ])->render();
            $this->assertSame($expected, $this->checked($html));
        }
    }

    private function expectedRows(string $operator, array $values): array
    {
        if ($values === []) {
            return [1, 2, 3];
        }
        $rows = [];
        foreach (['0', '1', 'alpha'] as $index => $code) {
            if (in_array($code, $values, true) === ($operator === 'in')) {
                $rows[] = $index + 1;
            }
        }

        return $rows;
    }

    private function checked(string $html): array
    {
        return (new Crawler($html))->filter('input[type="checkbox"][checked]')
            ->each(fn ($node) => $node->attr('value'));
    }

    private function nativeForm(array $result, string $path, string $name): array
    {
        $observed = [];
        foreach (['shipped', 'modern'] as $jquery) {
            $process = new Process(['node', __DIR__.'/javascript/grid-checkbox-filter.cjs']);
            $process->setTimeout(30);
            $process->setInput(json_encode([
                'html' => $result['html'], 'scriptHtml' => $result['scriptHtml'],
                'url' => 'http://localhost'.$path, 'name' => $name, 'jquery' => $jquery,
            ], JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $observed[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertSame($observed[0], $observed[1]);

        return $observed[0];
    }
}
