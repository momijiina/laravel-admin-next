<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridGroupOperatorItem extends Model
{
    protected $table = 'grid_group_operator_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** One ordinary unnamed Grid: real Group controls, emitted handlers and HTTP/SQLite. */
class GridGroupOperatorTest extends TestCase
{
    private $packageState = [];
    private const OPERATORS = ['=', '!=', '>', '<', '>=', '<='];
    private const LABELS = ['Equal (=)', 'Not equal (!=)', 'Greater (>)', 'Less (<)', 'At least (>=)', 'At most (<=)'];
    private const ROWS = [[3], [1, 2, 4, 5], [4, 5], [1, 2], [3, 4, 5], [1, 2, 3]];

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
        $router->get('/grid-group-operator/{mode}', function ($mode) {
            abort_unless(in_array($mode, ['text', 'datetime'], true), 404);
            $previousScript = Admin::$script;
            try {
                Admin::$script = [];
                $grid = new Grid(new GridGroupOperatorItem());
                $grid->paginate(20);
                $grid->model()->orderBy('id');
                $filter = $grid->getFilter();
                $filter->disableIdFilter();
                $group = $filter->group($this->column($mode), 'Boundary', function ($group) {
                    foreach (['equal', 'notEqual', 'gt', 'lt', 'nlt', 'ngt'] as $index => $method) {
                        $group->$method(self::LABELS[$index]);
                    }
                });
                if ($mode === 'datetime') {
                    $group->datetime();
                }

                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $data = $filter->execute();
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }
                $html = (string) $filter->render();

                return [
                    'rows' => array_column($data, 'id'),
                    'total' => $grid->model()->eloquent()->total(),
                    'html' => $html, 'scriptHtml' => (string) Admin::script(), 'queries' => $queries,
                ];
            } finally {
                Admin::$script = $previousScript;
            }
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'model'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            Schema::create('grid_group_operator_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('quantity');
                $table->dateTime('occurred_at');
            });
            foreach ([-2, -1, 0, 1, 2] as $quantity) {
                GridGroupOperatorItem::create([
                    'quantity' => $quantity,
                    'occurred_at' => '2026-10-'.str_pad((string) ($quantity + 7), 2, '0', STR_PAD_LEFT).' 12:00:00',
                ]);
            }
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

    public static function operators(): iterable
    {
        foreach (['text', 'datetime'] as $mode) {
            foreach (self::OPERATORS as $index => $operator) {
                yield "$mode $operator" => [$mode, $index];
            }
        }
    }

    #[DataProvider('operators')]
    public function test_unchanged_native_submissions_retain_the_selected_operator(string $mode, int $index): void
    {
        $column = $this->column($mode);
        $value = $this->boundary($mode);
        $result = $this->requestGrid($mode, [$column => $value, $column.'_group' => (string) $index]);
        $this->assertResult($result, $mode, $value, $index, self::ROWS[$index]);

        for ($round = 0; $round < 2; $round++) {
            $native = $this->nativeForm($result, $mode)['initial'];
            $submitted = $this->get($native['uri'])->assertOk()->json();
            // On the original views a nonzero operator silently becomes equality.
            $this->assertSame(self::ROWS[$index], $submitted['rows'],
                'Unchanged submission of '.self::OPERATORS[$index].' emitted operator '.$native['operator']);
            $this->assertNative($native, $mode, $value, $index);
            $this->assertRenderedOperator($result, $mode, $index);
            $this->assertResult($submitted, $mode, $value, $index, self::ROWS[$index]);
            $result = $submitted;
        }
        $this->assertSame(5, GridGroupOperatorItem::count());
    }

    public static function browserModes(): iterable
    {
        foreach (['text', 'datetime'] as $mode) {
            foreach (['shipped', 'modern'] as $jquery) {
                yield "$mode $jquery" => [$mode, $jquery];
            }
        }
    }

    #[DataProvider('browserModes')]
    public function test_actual_operator_clicks_update_native_submission_and_survive_redisplay(string $mode, string $jquery): void
    {
        $column = $this->column($mode);
        $value = $this->boundary($mode);
        $result = $this->requestGrid($mode, [$column => $value, $column.'_group' => '4']);
        $this->assertResult($result, $mode, $value, 4, self::ROWS[4]);
        $clicks = [5, 0, 2, 2];
        $native = $this->nativeForm($result, $mode, $jquery, $clicks);
        $this->assertSame($jquery === 'shipped' ? '2.1.4' : '3.7.1', $native['jqueryVersion']);
        foreach ($clicks as $position => $index) {
            $selection = $native['clicked'][$position];
            $this->assertNative($selection, $mode, $value, $index);
            $submitted = $this->get($selection['uri'])->assertOk()->json();
            $this->assertResult($submitted, $mode, $value, $index, self::ROWS[$index]);
            $this->assertRenderedOperator($submitted, $mode, $index);
            $unchanged = $this->nativeForm($submitted, $mode, $jquery)['initial'];
            $this->assertNative($unchanged, $mode, $value, $index);
            $this->assertResult($this->get($unchanged['uri'])->assertOk()->json(), $mode, $value, $index, self::ROWS[$index]);
        }
    }

    public static function fallbackOperators(): iterable
    {
        foreach (['text', 'datetime'] as $mode) {
            foreach (['absent' => null, 'empty' => '', 'unknown number' => '99', 'unknown scalar' => 'bogus'] as $name => $index) {
                yield "$mode $name" => [$mode, $index];
            }
        }
    }

    #[DataProvider('fallbackOperators')]
    public function test_existing_scalar_fallback_does_not_change_condition_semantics(string $mode, ?string $index): void
    {
        $column = $this->column($mode);
        $value = $this->boundary($mode);
        $query = [$column => $value];
        if ($index !== null) {
            $query[$column.'_group'] = $index;
        }
        $result = $this->requestGrid($mode, $query);
        // Missing/unknown operators do not apply a condition on the initial GET.
        // The existing first-option fallback applies only on the next submission.
        $this->assertResult($result, $mode, $value, 0, [1, 2, 3, 4, 5], false);
        $this->assertRenderedOperator($result, $mode, 0);
        $native = $this->nativeForm($result, $mode)['initial'];
        $this->assertNative($native, $mode, $value, 0);
        $this->assertResult($this->get($native['uri'])->assertOk()->json(), $mode, $value, 0, self::ROWS[0]);
    }

    public static function emptyValues(): iterable
    {
        foreach (['text', 'datetime'] as $mode) {
            foreach (['absent' => null, 'blank' => ''] as $name => $value) {
                yield "$mode $name" => [$mode, $value];
            }
        }
    }

    #[DataProvider('emptyValues')]
    public function test_empty_values_keep_the_operator_without_applying_a_condition(string $mode, ?string $value): void
    {
        $column = $this->column($mode);
        $query = [$column.'_group' => '4'];
        if ($value !== null) {
            $query[$column] = $value;
        }
        $result = $this->requestGrid($mode, $query);
        for ($round = 0; $round < 2; $round++) {
            $this->assertResult($result, $mode, '', 4, [1, 2, 3, 4, 5], false);
            $this->assertRenderedOperator($result, $mode, 4);
            $native = $this->nativeForm($result, $mode)['initial'];
            $this->assertNative($native, $mode, '', 4);
            $result = $this->get($native['uri'])->assertOk()->json();
        }
        $this->assertResult($result, $mode, '', 4, [1, 2, 3, 4, 5], false);
    }

    private function column(string $mode): string
    {
        return $mode === 'text' ? 'quantity' : 'occurred_at';
    }

    private function boundary(string $mode): string
    {
        return $mode === 'text' ? '0' : '2026-10-07 12:00:00';
    }

    private function requestGrid(string $mode, array $query): array
    {
        return $this->get('/grid-group-operator/'.$mode.'?'.http_build_query($query))->assertOk()->json();
    }

    private function nativeForm(array $result, string $mode, string $jquery = 'shipped', array $clicks = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-group-operator.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode([
            'html' => $result['html'], 'scriptHtml' => $result['scriptHtml'],
            'url' => 'http://localhost/grid-group-operator/'.$mode,
            'mode' => $mode, 'column' => $this->column($mode), 'jquery' => $jquery, 'clicks' => $clicks,
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNative(array $native, string $mode, string $value, int $index): void
    {
        $column = $this->column($mode);
        $this->assertSame([[$column.'_group', (string) $index], [$column, $value]], $native['entries']);
        $this->assertSame((string) $index, $native['operator']);
        $this->assertSame(self::LABELS[$index], $native['label']);
        $this->assertSame($value, $native['value']);
        parse_str(parse_url($native['uri'], PHP_URL_QUERY) ?? '', $query);
        $this->assertSame([$column.'_group' => (string) $index, $column => $value], $query);
    }

    private function assertRenderedOperator(array $result, string $mode, int $index): void
    {
        $column = $this->column($mode);
        $hidden = (new Crawler($result['html']))->filter('input[name="'.$column.'_group"]');
        $this->assertCount(1, $hidden);
        $this->assertSame('hidden', $hidden->attr('type'));
        $this->assertSame($column.'-filter-group-operation', $hidden->attr('class'));
        $this->assertSame((string) $index, $hidden->attr('value'));
    }

    private function assertResult(array $result, string $mode, string $value, int $index, array $rows, bool $filtered = true): void
    {
        $column = $this->column($mode);
        $this->assertSame($rows, $result['rows']);
        $this->assertSame(count($rows), $result['total']);
        $crawler = new Crawler($result['html']);
        $this->assertSame(self::LABELS[$index], $crawler->filter('.'.$column.'-filter-group-label')->text());
        $this->assertSame($value, $crawler->filter('input[name="'.$column.'"]')->attr('value'));
        $this->assertSame(self::LABELS, $crawler->filter('.'.$column.'-filter-group li a')->each(fn (Crawler $link) => $link->text()));
        $this->assertSame(['0', '1', '2', '3', '4', '5'], $crawler->filter('.'.$column.'-filter-group li a')->each(fn (Crawler $link) => $link->attr('data-index')));
        $queries = array_values(array_filter($result['queries'], fn ($query) =>
            str_contains($query['query'], 'from "grid_group_operator_items"')));
        $this->assertCount(2, $queries, 'Check both the real paginator count and row query.');
        $this->assertMatchesRegularExpression('/^select count\(\*\) as (?:"aggregate"|aggregate) /', $queries[0]['query']);
        $this->assertStringStartsWith('select * from "grid_group_operator_items"', $queries[1]['query']);
        foreach ($queries as $query) {
            $this->assertSame($filtered ? [$value] : [], $query['bindings']);
            if ($filtered) {
                $this->assertStringContainsString('"'.$column.'" '.self::OPERATORS[$index].' ?', $query['query']);
            } else {
                $this->assertStringNotContainsString(' where ', $query['query']);
            }
        }
    }
}
