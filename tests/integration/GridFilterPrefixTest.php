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

class GridFilterPrefixItem extends Model
{
    protected $table = 'grid_filter_prefix_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Expose only the protected normalization boundary, without replacing it. */
class GridFilterPrefixProbe extends Grid\Filter
{
    public function normalize(array $inputs): array
    {
        $this->sanitizeInputs($inputs);

        return $inputs;
    }
}

/** Production forms -> native FormData -> HTTP kernel -> SQLite and redisplay. */
class GridFilterPrefixTest extends TestCase
{
    private $packageState = [];

    private const RECORDS = [
        ['id' => 1, 'user_id' => 2, 'user_user_id' => 7, 'owner_user_code' => 'team_user_red'],
        ['id' => 2, 'user_id' => 9, 'user_user_id' => 2, 'owner_user_code' => 'team_user_blue'],
        ['id' => 3, 'user_id' => 2, 'user_user_id' => 9, 'owner_user_code' => 'team_user_red'],
        ['id' => 4, 'user_id' => 0, 'user_user_id' => 0, 'owner_user_code' => '0'],
    ];

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
        $router->get('/grid-filter-prefix/{name}/{mode}', function ($name, $mode) {
            abort_unless(in_array($name, ['plain', 'user', 'orders', 'pair'], true), 404);
            abort_unless(in_array($mode, ['id', 'noid', 'repeated', 'interior', 'between', 'in'], true), 404);
            if ($name === 'pair') {
                return [
                    'user' => $this->renderGrid('user', $mode),
                    'orders' => $this->renderGrid('orders', $mode),
                ];
            }

            return $this->renderGrid($name === 'plain' ? '' : $name, $mode);
        });
    }

    private function renderGrid(string $name, string $mode): array
    {
        $grid = new Grid(new GridFilterPrefixItem());
        if ($name !== '') {
            $grid->setName($name);
        }
        $grid->paginate(20);
        $grid->model()->orderBy('id');
        $filter = $grid->getFilter();
        if ($mode !== 'id') {
            $filter->disableIdFilter();
        }
        if ($mode === 'between') {
            $filter->between('user_id', 'User ID');
        } elseif ($mode === 'in') {
            $filter->in('user_id', 'User ID')->multipleSelect([0 => 'Zero', 2 => 'Two', 9 => 'Nine']);
        } else {
            $column = ['repeated' => 'user_user_id', 'interior' => 'owner_user_code'][$mode] ?? 'user_id';
            $filter->equal($column, 'Value');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = $filter->execute();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return [
            'rows' => $data,
            'total' => $grid->model()->eloquent()->total(),
            'html' => (string) $filter->render(),
            'queries' => $queries,
        ];
    }

    protected function setUp(): void
    {
        // Restore existing host values, including nonempty/seeded static state.
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
            Schema::create('grid_filter_prefix_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('user_user_id');
                $table->string('owner_user_code');
            });
            foreach (self::RECORDS as $record) {
                GridFilterPrefixItem::create($record);
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

    public static function idModes(): iterable
    {
        yield 'default ID enabled' => ['id'];
        yield 'default ID disabled' => ['noid'];
    }

    #[DataProvider('idModes')]
    public function test_named_user_id_targets_its_own_column(string $mode): void
    {
        $this->assertSubmission('user', $mode, ['user_user_id' => '2'], [1, 3], '"user_id" = ?', ['2']);
    }

    public function test_id_and_user_id_remain_distinct_conditions(): void
    {
        $this->assertSubmission('user', 'id', ['user_id' => '1', 'user_user_id' => '2'],
            [1], '"id" = ? and "user_id" = ?', ['1', '2']);
    }

    public static function repeatedAndInteriorColumns(): iterable
    {
        yield 'repeated leading text' => ['repeated', 'user_user_user_id', '7', [1], '"user_user_id" = ?'];
        yield 'interior text and value' => ['interior', 'user_owner_user_code', 'team_user_red', [1, 3], '"owner_user_code" = ?'];
    }

    #[DataProvider('repeatedAndInteriorColumns')]
    public function test_only_one_leading_namespace_is_removed(string $mode, string $name, string $value, array $ids, string $where): void
    {
        $this->assertSubmission('user', $mode, [$name => $value], $ids, $where, [$value]);
    }

    public static function ordinaryControls(): iterable
    {
        foreach (['plain' => 'user_id', 'orders' => 'orders_user_id'] as $name => $input) {
            foreach (['id', 'noid'] as $mode) {
                yield "$name $mode" => [$name, $mode, $input];
            }
        }
    }

    #[DataProvider('ordinaryControls')]
    public function test_unnamed_and_unrelated_names_keep_ordinary_filtering(string $name, string $mode, string $input): void
    {
        $this->assertSubmission($name, $mode, [$input => '2'], [1, 3], '"user_id" = ?', ['2']);
    }

    public function test_named_default_id_still_targets_id(): void
    {
        $this->assertSubmission('user', 'id', ['user_id' => '2'], [2], '"id" = ?', ['2']);
    }

    public static function blankAndZero(): iterable
    {
        yield 'blank is ignored' => ['', [1, 2, 3, 4], '', []];
        yield 'string zero is retained' => ['0', [4], '"user_id" = ?', ['0']];
    }

    #[DataProvider('blankAndZero')]
    public function test_named_blank_and_zero_keep_existing_meaning(string $value, array $ids, string $where, array $bindings): void
    {
        $this->assertSubmission('user', 'id', ['user_user_id' => $value], $ids, $where, $bindings);
    }

    public static function ranges(): iterable
    {
        yield 'paired with zero' => [['0', '2'], [1, 3, 4], '"user_id" between ? and ?', ['0', '2']];
        yield 'blank lower and zero upper' => [['', '0'], [4], '"user_id" <= ?', ['0']];
    }

    #[DataProvider('ranges')]
    public function test_between_array_endpoints_retain_the_column_prefix(array $bounds, array $ids, string $where, array $bindings): void
    {
        $this->assertSubmission('user', 'between', [
            'user_user_id[start]' => $bounds[0], 'user_user_id[end]' => $bounds[1],
        ], $ids, $where, $bindings);
    }

    public static function selections(): iterable
    {
        yield 'multiple including zero' => [['0', '2'], [1, 3, 4], '"user_id" in (?, ?)', ['0', '2']];
        yield 'empty selection' => [[], [1, 2, 3, 4], '', []];
    }

    #[DataProvider('selections')]
    public function test_in_multiple_select_retains_array_values(array $selected, array $ids, string $where, array $bindings): void
    {
        $this->assertSubmission('user', 'in', ['user_user_id[]' => $selected], $ids, $where, $bindings);
    }

    public function test_wrong_namespace_and_unprefixed_queries_remain_ignored(): void
    {
        $path = '/grid-filter-prefix/user/noid';
        // user_id is the named ID control, which is disabled in this fixture;
        // it must not be reused as an unprefixed user_id column condition.
        $query = ['orders_user_id' => '9', 'id' => '2', 'user_id' => '2', 'xuser_user_id' => '9', 'userx_user_id' => '9'];
        $result = $this->get($path.'?'.http_build_query($query))->assertOk()->json();
        $this->assertResult($result, [1, 2, 3, 4], ['user_user_id' => ''], '', []);
        $reset = $this->resetUrl($result['html'], $path);
        $this->assertSame($query, $this->parseQuery($reset));
        $this->assertResult($this->get($reset)->assertOk()->json(), [1, 2, 3, 4], ['user_user_id' => ''], '', []);
        $this->assertRecordsUnchanged();
    }

    public function test_two_named_grids_filter_and_reset_independently(): void
    {
        $path = '/grid-filter-prefix/pair/noid';
        $initial = $this->get($path)->assertOk()->json();
        $values = ['user' => '2', 'orders' => '9'];
        $ids = ['user' => [1, 3], 'orders' => [2]];
        $query = [];
        foreach ($values as $name => $value) {
            $this->assertResult($initial[$name], [1, 2, 3, 4], [$name.'_user_id' => ''], '', []);
            $submission = $this->submitForm($initial[$name]['html'], $path, [$name.'_user_id' => $value]);
            $query += $this->parseQuery($submission['uri']);
        }
        // Combine independent native payloads explicitly; this is not a PJAX test.
        $query['context'] = 'summary';
        $result = $this->get($path.'?'.http_build_query($query))->assertOk()->json();
        foreach ($values as $name => $value) {
            $this->assertResult($result[$name], $ids[$name], [$name.'_user_id' => $value], '"user_id" = ?', [$value]);
            $reset = $this->resetUrl($result[$name]['html'], $path);
            $expectedQuery = $query;
            unset($expectedQuery[$name.'_user_id']);
            $this->assertSame($expectedQuery, $this->parseQuery($reset));
            $after = $this->get($reset)->assertOk()->json();
            $this->assertResult($after[$name], [1, 2, 3, 4], [$name.'_user_id' => ''], '', []);
            $other = $name === 'user' ? 'orders' : 'user';
            $this->assertResult($after[$other], $ids[$other], [$other.'_user_id' => $values[$other]],
                '"user_id" = ?', [$values[$other]]);
        }
        $this->assertRecordsUnchanged();
    }

    public function test_sanitizer_preserves_literal_dotted_keys_and_values_after_one_prefix(): void
    {
        $expected = [
            'id' => '1', 'user_id' => '2', 'user_user_id' => '7',
            'owner_user_code' => 'team_user_red', 'owner.user_id' => 'value_user_stays',
            'owner.profile.user_code' => 'deep_user_value', 'user_owner.user_user_id.0' => '0',
            'user_id.start' => '0', 'user_id.end' => '9',
            'name' => ['user_key' => 'user_value'], 'blank' => '', 'null' => null, 'zero' => 0, 'false' => false,
        ];
        // Literal namespaces also cover a longer name and regexp metacharacters.
        foreach (['user', 'team_user', 'user.+', '用户'] as $name) {
            $filter = (new GridFilterPrefixProbe((new Grid(new GridFilterPrefixItem()))->model()))->setName($name);
            $inputs = [];
            foreach ($expected as $key => $value) {
                $inputs[$name.'_'.$key] = $value;
            }
            $inputs += ['orders_user_id' => '9', 'id' => '9', 'owner_user_code' => '9',
                'x'.$name.'_user_id' => '9', $name.'x_user_id' => '9'];
            $this->assertSame($expected, $filter->normalize($inputs));
        }
    }

    public function test_unnamed_and_existing_falsey_name_sanitizers_leave_inputs_unchanged(): void
    {
        $inputs = [
            'user_user_id' => '2', 'owner.user_id' => 'literal_user_value',
            'owner.profile.user_code' => ['user_child' => '0'], 'orders_user_id' => '9',
            'blank' => '', 'null' => null, 'zero' => 0, 'false' => false, 0 => 'numeric key',
        ];
        foreach (['', '0'] as $name) {
            $filter = (new GridFilterPrefixProbe((new Grid(new GridFilterPrefixItem()))->model()))->setName($name);
            $this->assertSame($inputs, $filter->normalize($inputs));
        }
    }

    private function assertSubmission(string $name, string $mode, array $values, array $ids, string $where, array $bindings): void
    {
        $path = '/grid-filter-prefix/'.$name.'/'.$mode;
        $blank = $this->blankControls($name, $mode);
        $initial = $this->get($path)->assertOk()->json();
        $this->assertResult($initial, [1, 2, 3, 4], $blank, '', []);
        $values = array_replace($blank, $values);
        $submission = $this->submitForm($initial['html'], $path, $values);
        $result = $this->get($submission['uri'])->assertOk()->json();
        $this->assertResult($result, $ids, $values, $where, $bindings);

        $reset = $this->resetUrl($result['html'], $path);
        $this->assertSame($path, parse_url($reset, PHP_URL_PATH));
        $this->assertSame([], $this->parseQuery($reset));
        $this->assertResult($this->get($reset)->assertOk()->json(), [1, 2, 3, 4], $blank, '', []);
        $this->assertRecordsUnchanged();
    }

    private function blankControls(string $name, string $mode): array
    {
        $prefix = $name === 'plain' ? '' : $name.'_';
        if ($mode === 'between') {
            return [$prefix.'user_id[start]' => '', $prefix.'user_id[end]' => ''];
        }
        if ($mode === 'in') {
            return [$prefix.'user_id[]' => []];
        }
        $column = ['repeated' => 'user_user_id', 'interior' => 'owner_user_code'][$mode] ?? 'user_id';

        return ($mode === 'id' ? [$prefix.'id' => ''] : []) + [$prefix.$column => ''];
    }

    private function submitForm(string $html, string $path, array $values): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-filter-prefix-form.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode(['html' => $html, 'url' => 'http://localhost'.$path, 'values' => $values], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($values), $native['names']);
        $entries = [];
        foreach ($values as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $entry) {
                $entries[] = [$name, $entry];
            }
        }
        $this->assertSame($entries, $native['entries']);
        $this->assertSame($path, parse_url($native['uri'], PHP_URL_PATH));

        return $native;
    }

    private function assertResult(array $result, array $ids, array $values, string $where, array $bindings): void
    {
        $this->assertSame($ids, array_column($result['rows'], 'id'));
        $this->assertSame(count($ids), $result['total']);
        $this->assertSame(array_values(array_filter(self::RECORDS, fn ($row) => in_array($row['id'], $ids, true))), $result['rows']);
        $controls = (new Crawler($result['html']))->filter('input[type="text"], select[multiple]');
        $redisplay = [];
        foreach ($controls as $control) {
            $input = new Crawler($control);
            $redisplay[$input->attr('name')] = $control->tagName === 'select'
                ? $input->filter('option[selected]')->each(fn (Crawler $option) => $option->attr('value'))
                : $input->attr('value');
        }
        $this->assertSame($values, $redisplay);
        $countQueries = array_values(array_filter($result['queries'], fn ($query) =>
            preg_match('/^select count\(\*\) as (?:"aggregate"|aggregate) from "grid_filter_prefix_items"/', $query['query'])));
        $rowQueries = array_values(array_filter($result['queries'], fn ($query) =>
            str_starts_with($query['query'], 'select * from "grid_filter_prefix_items"')));
        $this->assertCount(1, $countQueries);
        $this->assertCount($ids === [] ? 0 : 1, $rowQueries);
        foreach (array_merge($countQueries, $rowQueries) as $query) {
            $this->assertSame($bindings, $query['bindings']);
            preg_match('/ where (.*?)(?: order by | limit |$)/', $query['query'], $matches);
            $this->assertSame($where, $matches[1] ?? '', $query['query']);
        }
    }

    private function parseQuery(string $uri): array
    {
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function resetUrl(string $html, string $path): string
    {
        return (new Crawler($html, 'http://localhost'.$path))->filter('a.btn-default')->link()->getUri();
    }

    private function assertRecordsUnchanged(): void
    {
        $this->assertSame(self::RECORDS, GridFilterPrefixItem::orderBy('id')->get()->toArray());
    }
}
