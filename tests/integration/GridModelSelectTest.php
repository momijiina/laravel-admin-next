<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter\Presenter\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridModelSelectRecord extends Model
{
    protected $table = 'grid_model_select_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridModelSelectOption extends Model
{
    protected $table = 'grid_model_select_options';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
}

class GridModelSelectTest extends TestCase
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
        $router->get('/grid-model-select/{naming}/{layout}', function ($naming, $layout) {
            abort_unless(in_array($naming, ['plain', 'named'], true), 404);
            abort_unless(in_array($layout, ['container', 'modal'], true), 404);
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                $grid = new Grid(new GridModelSelectRecord());
                $grid->model()->orderBy('id');
                if ($naming === 'named') {
                    $grid->setName('catalog');
                }
                $filter = $grid->getFilter()->disableIdFilter();
                if ($layout === 'modal') {
                    $filter->useModal();
                }
                $filter->equal('code')->select()->model(GridModelSelectOption::class)->config('width', '100%');
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $rows = array_column($filter->execute(), 'id');
                    $html = (string) $filter->render();
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }

                return ['rows' => $rows, 'html' => $html, 'queries' => $queries,
                    'scriptHtml' => Admin::script()->render()];
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
        Schema::create('grid_model_select_records', function ($table) {
            $table->increments('id');
            $table->string('code');
        });
        Schema::create('grid_model_select_options', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('caption');
        });
        foreach (['0' => 'Zero', '1' => 'One', '00' => 'Leading zeros', 'alpha' => 'Alpha'] as $code => $label) {
            GridModelSelectRecord::create(['code' => (string) $code]);
            GridModelSelectOption::create(['id' => (string) $code, 'name' => $label, 'caption' => 'Custom '.$label]);
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
        foreach (['plain', 'named'] as $naming) {
            foreach (['container', 'modal'] as $layout) {
                yield "$naming $layout" => [$naming, $layout];
            }
        }
    }

    #[DataProvider('layouts')]
    public function test_model_choice_survives_native_resubmission_clear_and_reselection(string $naming, string $layout): void
    {
        $path = "/grid-model-select/$naming/$layout";
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        foreach (['0' => [1, 'Zero'], '1' => [2, 'One'], '00' => [3, 'Leading zeros'], 'alpha' => [4, 'Alpha']] as $value => [$id, $label]) {
            $value = (string) $value;
            $result = $this->get($path.'?'.http_build_query([$name => $value]))->assertOk()->json();
            for ($round = 0; $round < 2; $round++) {
                $this->assertSame([$id], $result['rows']);
                $this->assertFilteredQueries($result, $value);
                $this->assertSame([$value], (new Crawler($result['html']))->filter('option[selected]')->each(fn ($node) => $node->attr('value')));
                $native = $this->nativeForm($result, $path, $name, $value);
                $this->assertSame([$value], $native['initial']['selected']);
                $this->assertSame([$label], $native['initial']['labels']);
                $this->assertSame([[$name, $value]], $native['initial']['entries']);
                $result = $this->get($native['initial']['uri'])->assertOk()->json();
            }
            $this->assertSame([$id], $result['rows']);
            $this->assertSame([], $native['cleared']['selected']);
            $this->assertSame([[$name, '']], $native['cleared']['entries']);
            foreach ([$native['cleared']['uri'], $native['reset']] as $uri) {
                $clear = $this->get($uri)->assertOk()->json();
                $this->assertSame([1, 2, 3, 4], $clear['rows']);
                $this->assertCount(1, (new Crawler($clear['html']))->filter('option'), 'Empty filters do not load any model choices.');
            }
            $this->assertSame([$value], $native['chosen']['selected']);
            $this->assertSame([$id], $this->get($native['chosen']['uri'])->assertOk()->json('rows'));
        }
        $this->assertSame(['0', '1', '00', 'alpha'], GridModelSelectRecord::orderBy('id')->pluck('code')->all());
    }

    public static function modelInputs(): iterable
    {
        yield 'integer zero' => [0, [0 => 'Zero']];
        yield 'string zero' => ['0', [0 => 'Zero']];
        yield 'ordinary integer' => [1, [1 => 'One']];
        yield 'ordinary text' => ['alpha', ['alpha' => 'Alpha']];
        yield 'leading zeros' => ['00', ['00' => 'Leading zeros']];
        yield 'missing model' => ['missing', []];
        yield 'null' => [null, []];
        yield 'blank' => ['', []];
        yield 'false' => [false, []];
        yield 'float zero' => [0.0, []];
        yield 'empty array' => [[], []];
        yield 'legacy one record' => [['id' => '0'], [0 => 'Zero']];
        yield 'legacy record list' => [[['id' => '0'], ['id' => 'alpha']], [0 => 'Zero', 'alpha' => 'Alpha']];
    }

    #[DataProvider('modelInputs')]
    public function test_model_options_keep_supported_ids_and_legacy_empty_inputs($input, array $expected): void
    {
        $filter = (new Grid(new GridModelSelectRecord()))->getFilter()->equal('code');
        $filter->condition(['code' => $input]);
        foreach (['name', 'caption'] as $textField) {
            $presenter = new Select([]);
            $presenter->setParent($filter);
            $presenter->model(GridModelSelectOption::class, 'id', $textField);
            $options = $presenter->variables()['options'];
            $this->assertSame($textField === 'name' ? $expected : array_map(fn ($label) => 'Custom '.$label, $expected), $options);
        }
    }

    private function assertFilteredQueries(array $result, string $value): void
    {
        $queries = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_model_select_records"')));
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('"code" = ?', $query['query']);
            $this->assertSame([$value], $query['bindings']);
        }
        $options = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_model_select_options"')));
        $this->assertCount(1, $options);
        $this->assertSame([$value], $options[0]['bindings']);
    }

    private function nativeForm(array $result, string $path, string $name, string $value): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-model-select.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode($result + ['url' => 'http://localhost'.$path, 'name' => $name, 'value' => $value], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
