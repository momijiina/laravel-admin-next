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

class GridRadioIntegerDefaultRecord extends Model
{
    protected $table = 'grid_radio_integer_default_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridRadioIntegerDefaultTest extends TestCase
{
    private array $packageState = [];
    private $default = 1;

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
        $router->get('/grid-radio/{naming}/{layout}/{orientation}', function ($naming, $layout, $orientation) {
            abort_unless(in_array($naming, ['plain', 'named'], true), 404);
            abort_unless(in_array($layout, ['container', 'modal'], true), 404);
            abort_unless(in_array($orientation, ['inline', 'stacked'], true), 404);
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                $grid = new Grid(new GridRadioIntegerDefaultRecord());
                $grid->model()->orderBy('id');
                if ($naming === 'named') {
                    $grid->setName('catalog');
                }
                $filter = $grid->getFilter()->disableIdFilter();
                if ($layout === 'modal') {
                    $filter->useModal();
                }
                $presenter = $filter->equal('code')->default($this->default)->radio([
                    0 => 'Zero', 1 => 'One', -1 => 'Minus one', '01' => 'Leading zero', 'alpha' => 'Alpha',
                ]);
                if ($orientation === 'stacked') {
                    $presenter->stacked();
                }
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $rows = array_column($filter->execute(), 'id');
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }

                return ['rows' => $rows, 'html' => (string) $filter->render(),
                    'queries' => $queries, 'scriptHtml' => Admin::script()->render()];
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
        Schema::create('grid_radio_integer_default_records', function ($table) {
            $table->increments('id');
            $table->string('code');
        });
        foreach (['0', '1', '-1', '01', 'alpha'] as $code) {
            GridRadioIntegerDefaultRecord::create(['code' => $code]);
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
                foreach (['inline', 'stacked'] as $orientation) {
                    yield "$naming $layout $orientation" => [$naming, $layout, $orientation];
                }
            }
        }
    }

    #[DataProvider('layouts')]
    public function test_integer_defaults_reach_native_submission_and_queries(string $naming, string $layout, string $orientation): void
    {
        $path = "/grid-radio/$naming/$layout/$orientation";
        $name = $naming === 'named' ? 'catalog_code' : 'code';
        foreach ([1 => 2, -1 => 3] as $default => $row) {
            $this->default = (string) $default;
            $string = $this->get($path)->assertOk()->json();
            $this->default = $default;
            $initial = $this->get($path)->assertOk()->json();
            // A filter default is a presentation default, not an initial SQL condition.
            $this->assertSame([1, 2, 3, 4, 5], $initial['rows']);
            $this->assertSame($string['rows'], $initial['rows']);
            $this->assertSame([(string) $default], $this->checked($initial['html']));
            $this->assertSame($this->checked($string['html']), $this->checked($initial['html']));
            $native = $this->nativeForm($initial, $path, $name);
            $this->assertSame([(string) $default], $native['initial']['checked']);
            $this->assertSame([[$name, (string) $default]], $native['initial']['entries']);
            $this->assertSame($native['initial'], $native['repeated']);
            for ($round = 0; $round < 2; $round++) {
                $submitted = $this->get($native['initial']['uri'])->assertOk()->json();
                $this->assertSame([$row], $submitted['rows']);
                $this->assertSame([(string) $default], $this->checked($submitted['html']));
                foreach ($submitted['queries'] as $query) {
                    $this->assertSame([(string) $default], $query['bindings']);
                }
            }
            foreach ($native['chosen'] as $value => $choice) {
                $this->assertSame([(string) $value], $choice['checked']);
                $this->assertSame([[$name, (string) $value]], $choice['entries']);
                $result = $this->get($choice['uri'])->assertOk()->json();
                $this->assertSame([['0' => 1, '01' => 4, 'alpha' => 5][$value]], $result['rows']);
                $this->assertSame([(string) $value], $this->checked($result['html']));
            }
            $reset = $this->get($native['reset'])->assertOk()->json();
            $this->assertSame([1, 2, 3, 4, 5], $reset['rows']);
            $this->assertSame([(string) $default], $this->checked($reset['html']));
        }
        $this->assertSame(['0', '1', '-1', '01', 'alpha'], GridRadioIntegerDefaultRecord::orderBy('id')->pluck('code')->all());
    }

    public function test_only_integer_values_gain_string_option_matching(): void
    {
        foreach ([
            [0, ['0']], [1, ['1']], [-1, ['-1']],
            [PHP_INT_MAX, [(string) PHP_INT_MAX]], [PHP_INT_MIN, [(string) PHP_INT_MIN]],
            ['0', ['0']], ['1', ['1']], ['01', ['01']], ['alpha', ['alpha']],
            [null, ['']], ['', ['']], [false, []], [true, []], [1.0, []], [[], []], [['1'], []],
        ] as [$value, $expected]) {
            $html = view('admin::filter.radio', [
                'options' => [0 => 'Zero', 1 => 'One', -1 => 'Minus one', '01' => 'Leading zero',
                    'alpha' => 'Alpha', '' => 'Blank', PHP_INT_MAX => 'Maximum', PHP_INT_MIN => 'Minimum'],
                'id' => 'code', 'name' => 'code', 'inline' => true, 'value' => $value,
            ])->render();
            $this->assertSame($expected, $this->checked($html), var_export($value, true));
        }
    }

    public function test_absent_blank_and_zero_default_controls_keep_their_behavior(): void
    {
        $path = '/grid-radio/plain/container/inline';
        foreach ([null, '', 0, '0', false] as $default) {
            $this->default = $default;
            $result = $this->get($path)->assertOk()->json();
            $this->assertSame([], $this->checked($result['html']));
            $this->assertSame([1, 2, 3, 4, 5], $result['rows']);
        }
        $this->default = 1;
        foreach (['', 'missing'] as $value) {
            $result = $this->get($path.'?'.http_build_query(['code' => $value]))->assertOk()->json();
            $this->assertSame($value === '' ? ['1'] : [], $this->checked($result['html']));
            $this->assertSame($value === '' ? [1, 2, 3, 4, 5] : [], $result['rows']);
        }
    }

    private function checked(string $html): array
    {
        return (new Crawler($html))->filter('input[type="radio"][checked]')->each(fn ($node) => $node->attr('value'));
    }

    private function nativeForm(array $result, string $path, string $name): array
    {
        $observed = [];
        foreach (['shipped', 'modern'] as $jquery) {
            $process = new Process(['node', __DIR__.'/javascript/grid-radio-integer-default.cjs']);
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
