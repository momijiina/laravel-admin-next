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
use Symfony\Component\Process\Process;

class GridRemoteSelectRecord extends Model
{
    protected $table = 'grid_remote_select_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridRemoteSelectTest extends TestCase
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
        $router->get('/grid-remote-options', fn () => [
            ['id' => 0, 'text' => 'Zero'],
            ['id' => '1', 'text' => 'One'],
            ['id' => 2, 'text' => 'Two'],
            ['id' => '00', 'text' => 'Leading zeros'],
            ['id' => 'alpha', 'text' => 'Alpha'],
        ]);
        $router->get('/grid-remote-select/{kind}/{naming}/{layout}', function ($kind, $naming, $layout) {
            abort_unless(in_array($kind, ['select', 'multipleSelect'], true), 404);
            abort_unless(in_array($naming, ['plain', 'named'], true), 404);
            abort_unless(in_array($layout, ['container', 'modal'], true), 404);
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                $grid = new Grid(new GridRemoteSelectRecord());
                $grid->paginate(20);
                $grid->model()->orderBy('id');
                if ($naming === 'named') {
                    $grid->setName('catalog');
                }
                $filter = $grid->getFilter();
                $filter->disableIdFilter();
                if ($layout === 'modal') {
                    $filter->useModal();
                }
                $condition = $kind === 'select' ? $filter->equal('code') : $filter->in('code');
                $condition->$kind('/grid-remote-options')->config('width', '100%');
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
                    'scriptHtml' => Admin::script()->render(),
                    'queries' => $queries,
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
        Schema::create('grid_remote_select_records', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
        });
        foreach (['0', '1', '2', '00', 'alpha', null] as $code) {
            GridRemoteSelectRecord::create(['code' => $code]);
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
        foreach (['select', 'multipleSelect'] as $kind) {
            foreach (['plain', 'named'] as $naming) {
                foreach (['container', 'modal'] as $layout) {
                    yield "$kind $naming $layout" => [$kind, $naming, $layout];
                }
            }
        }
    }

    #[DataProvider('modes')]
    public function test_remote_choices_survive_native_resubmission_clear_and_reselection(string $kind, string $naming, string $layout): void
    {
        $path = '/grid-remote-select/'.$kind.'/'.$naming.'/'.$layout;
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        $cases = $kind === 'select'
            ? [['0', ['0'], [1]], ['00', ['00'], [4]]]
            : [[['0', '2'], ['0', '2'], [1, 3]], [['2', '0'], ['0', '2'], [1, 3]], [['', '1'], ['1'], [2]], [[4 => '0', 8 => '2'], ['0', '2'], [1, 3]]];
        foreach ($cases as [$input, $selected, $rows]) {
            $result = $this->get($path.'?'.http_build_query([$name => $input]))->assertOk()->json();
            for ($round = 0; $round < 2; $round++) {
                $this->assertSame($rows, $result['rows']);
                $this->assertQueries($result, $selected, $kind);
                $native = $this->nativeForm($result, $path, $name, $kind);
                $this->assertSame($selected, $native['initial']['selected']);
                $this->assertSame($selected, $native['initial']['displayed']);
                $this->assertSame(array_map(fn ($value) => [$name.($kind === 'select' ? '' : '[]'), $value], $selected), $native['initial']['entries']);
                $result = $this->get($native['initial']['uri'])->assertOk()->json();
            }
            foreach ([$native['cleared']['uri'], $native['reset']] as $reset) {
                $resetResult = $this->get($reset)->assertOk()->json();
                $this->assertSame(range(1, 6), $resetResult['rows']);
                $this->assertQueries($resetResult, [], $kind);
            }
            $this->assertSame(['alpha'], $native['chosen']['selected']);
            $chosen = $this->get($native['chosen']['uri'])->assertOk()->json();
            $this->assertSame([5], $chosen['rows']);
            $this->assertQueries($chosen, ['alpha'], $kind);
        }
        $this->assertSame(6, GridRemoteSelectRecord::count());
    }

    public static function serializedValues(): iterable
    {
        yield 'integer zero' => [0, [0]];
        yield 'string zero' => ['0', ['0']];
        yield 'ordinary scalar' => ['alpha', ['alpha']];
        yield 'leading zeros' => ['00', ['00']];
        yield 'zero list' => [[0, '0', '2'], [0, '0', '2']];
        yield 'blank then choice' => [['', '1'], ['1']];
        yield 'sparse list' => [[4 => '0', 9 => '2'], ['0', '2']];
        yield 'named keys' => [['first' => '0', 'next' => '2'], ['0', '2']];
        yield 'legacy empty values' => [[null, '', false, 0.0, []], []];
        yield 'null' => [null, []];
        yield 'blank' => ['', []];
        yield 'false' => [false, []];
        yield 'float zero' => [0.0, []];
        yield 'empty list' => [[], []];
    }

    #[DataProvider('serializedValues')]
    public function test_remote_selection_is_a_json_list_with_legacy_empty_handling($input, array $expected): void
    {
        $filter = (new Grid(new GridRemoteSelectRecord()))->getFilter()->equal('code');
        $filter->condition(['code' => $input]);
        $presenter = new Select('/grid-remote-options');
        $presenter->setParent($filter);
        Admin::$script = [];
        $this->assertSame([], $presenter->variables()['options']);
        $script = implode("\n", Admin::$script);
        $this->assertSame(1, preg_match('/\\.val\\((.*?)\\)\\.trigger/s', $script, $match));
        $this->assertSame($expected, json_decode($match[1], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(json_encode($expected), $match[1], 'Always emit a JSON list, including after filtering sparse keys.');
    }

    private function assertQueries(array $result, array $values, string $kind): void
    {
        $queries = array_values(array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'from "grid_remote_select_records"')));
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $bindings = $query['bindings'];
            if ($kind === 'multipleSelect') {
                sort($bindings);
                sort($values);
            }
            $this->assertSame($values, $bindings);
            if ($values === []) {
                $this->assertStringNotContainsString(' where ', $query['query']);
            } else {
                $this->assertStringContainsString($kind === 'select' ? '"code" = ?' : '"code" in (', $query['query']);
            }
        }
    }

    private function nativeForm(array $result, string $path, string $name, string $kind): array
    {
        $options = $this->get('/grid-remote-options')->assertOk()->json();
        $process = new Process(['node', __DIR__.'/javascript/grid-remote-select.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode($result + ['options' => $options, 'url' => 'http://localhost'.$path,
            'name' => $name.($kind === 'select' ? '' : '[]')], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
