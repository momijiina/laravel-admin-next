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

class GridBetweenNameItem extends Model
{
    protected $table = 'grid_between_name_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Actual rendered GET controls, native FormData, HTTP requests and SQLite SQL. */
class GridBetweenNameTest extends TestCase
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
        $router->get('/grid-between/{name}/{mode}', function ($name, $mode) {
            abort_unless(in_array($name, ['plain', 'orders', 'pair'], true), 404);
            abort_unless(in_array($mode, ['text', 'datetime'], true), 404);
            if ($name === 'pair') {
                return [
                    'orders' => $this->renderGrid('orders', $mode),
                    'invoices' => $this->renderGrid('invoices', $mode),
                ];
            }

            return $this->renderGrid($name === 'plain' ? '' : $name, $mode);
        });
    }

    private function renderGrid(string $name, string $mode): array
    {
        $grid = new Grid(new GridBetweenNameItem());
        if ($name !== '') {
            $grid->setName($name);
        }
        $grid->paginate(20);
        $grid->model()->orderBy('id');
        $filter = $grid->getFilter();
        $filter->disableIdFilter();
        $between = $filter->between('quantity', 'Quantity');
        if ($mode === 'datetime') {
            // Isolate shared form naming; this deliberately keeps a numeric column.
            $between->datetime();
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
            'rows' => array_column($data, 'quantity'),
            'total' => $grid->model()->eloquent()->total(),
            'html' => (string) $filter->render(),
            'queries' => $queries,
        ];
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
            Schema::create('grid_between_name_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('quantity');
            });
            foreach ([-2, -1, 0, 1, 2] as $quantity) {
                GridBetweenNameItem::create(['quantity' => $quantity]);
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

    public static function ranges(): iterable
    {
        foreach (['plain', 'orders'] as $name) {
            foreach (['text', 'datetime'] as $mode) {
                foreach ([
                    'paired' => [['0', '1'], [0, 1]],
                    'lower only' => [['0', ''], [0, 1, 2]],
                    'upper only' => [['', '0'], [-2, -1, 0]],
                    'zero width' => [['0', '0'], [0]],
                    'empty' => [['', ''], [-2, -1, 0, 1, 2]],
                    'reversed' => [['1', '0'], []],
                ] as $label => [$bounds, $rows]) {
                    yield "$name $mode $label" => [$name, $mode, $bounds, $rows];
                }
            }
        }
    }

    #[DataProvider('ranges')]
    public function test_rendered_range_submission_redisplay_and_reset(string $name, string $mode, array $bounds, array $rows): void
    {
        $path = '/grid-between/'.$name.'/'.$mode;
        $initial = $this->get($path)->assertOk();
        $this->assertResult($initial->json(), ['', ''], [-2, -1, 0, 1, 2]);
        $submission = $this->submitForm($initial->json('html'), $path, $bounds);
        $base = $name === 'plain' ? 'quantity' : 'orders_quantity';
        $this->assertSame([$base.'[start]', $base.'[end]'], $submission['names']);
        $this->assertSame([$base => ['start' => $bounds[0], 'end' => $bounds[1]]], $submission['query']);
        $response = $this->get($submission['uri'])->assertOk();
        $this->assertResult($response->json(), $bounds, $rows);

        $reset = $this->resetUrl($response->json('html'), $path);
        $this->assertSame($path, parse_url($reset, PHP_URL_PATH));
        $this->assertSame([], $this->parseFormQuery($reset));
        $this->assertResult($this->get($reset)->assertOk()->json(), ['', ''], [-2, -1, 0, 1, 2]);
        $this->assertSame([-2, -1, 0, 1, 2], GridBetweenNameItem::orderBy('id')->pluck('quantity')->all());
    }

    public static function views(): iterable
    {
        yield 'text' => ['text'];
        yield 'datetime' => ['datetime'];
    }

    #[DataProvider('views')]
    public function test_existing_prefixed_query_api_and_unprefixed_isolation(string $mode): void
    {
        $path = '/grid-between/orders/'.$mode;
        $response = $this->get($path.'?'.http_build_query([
            'orders_quantity' => ['start' => '0', 'end' => '1'],
        ]))->assertOk();
        $this->assertResult($response->json(), ['0', '1'], [0, 1]);

        // Wrong-namespace inputs remain ignored by the existing sanitizer.
        $response = $this->get($path.'?'.http_build_query([
            'quantity' => ['start' => '-2', 'end' => '-1'],
        ]))->assertOk();
        $this->assertSame([-2, -1, 0, 1, 2], $response->json('rows'));
        $this->assertSql($response->json('queries'), ['', '']);
    }

    #[DataProvider('views')]
    public function test_two_named_forms_produce_independent_queries_and_reset_links(string $mode): void
    {
        $path = '/grid-between/pair/'.$mode;
        $initial = $this->get($path)->assertOk();
        $orders = $this->submitForm($initial->json('orders.html'), $path, ['0', '1']);
        $invoices = $this->submitForm($initial->json('invoices.html'), $path, ['-2', '-1']);
        $this->assertSame(['orders_quantity[start]', 'orders_quantity[end]'], $orders['names']);
        $this->assertSame(['invoices_quantity[start]', 'invoices_quantity[end]'], $invoices['names']);
        // Combine the two independently emitted payloads; no PJAX behavior is simulated.
        $query = $orders['query'] + $invoices['query'] + ['context' => 'summary'];
        $response = $this->get($path.'?'.http_build_query($query))->assertOk();
        $this->assertResult($response->json('orders'), ['0', '1'], [0, 1]);
        $this->assertResult($response->json('invoices'), ['-2', '-1'], [-2, -1]);

        foreach (['orders', 'invoices'] as $resetName) {
            $reset = $this->resetUrl($response->json($resetName.'.html'), $path);
            $expectedQuery = $query;
            unset($expectedQuery[$resetName.'_quantity']);
            $this->assertSame($expectedQuery, $this->parseFormQuery($reset));
            $after = $this->get($reset)->assertOk();
            $this->assertResult($after->json($resetName), ['', ''], [-2, -1, 0, 1, 2]);
            $other = $resetName === 'orders' ? 'invoices' : 'orders';
            $this->assertResult($after->json($other), $other === 'orders' ? ['0', '1'] : ['-2', '-1'],
                $other === 'orders' ? [0, 1] : [-2, -1]);
        }
    }

    public static function formattedNames(): iterable
    {
        foreach (['' => '', 'orders' => 'orders_'] as $name => $prefix) {
            foreach (['quantity' => 'quantity', 'owner.quantity' => 'owner[quantity]',
                'owner.profile.quantity' => 'owner[profile][quantity]'] as $column => $base) {
                foreach (['text', 'datetime'] as $mode) {
                    yield ($name ?: 'plain')." $column $mode" => [$name, $column, $prefix.$base, $mode];
                }
            }
        }
    }

    #[DataProvider('formattedNames')]
    public function test_dotted_name_formatting_and_existing_ids(string $name, string $column, string $base, string $mode): void
    {
        $grid = new Grid(new GridBetweenNameItem());
        $between = $grid->getFilter()->between($column, 'Quantity');
        // Naming after filter creation must still be reflected at render time.
        if ($name !== '') {
            $grid->setName($name);
        }
        if ($mode === 'datetime') {
            $between->datetime();
        }
        $inputs = (new Crawler((string) $between->render()))->filter('input[type="text"]');
        $this->assertSame([$base.'[start]', $base.'[end]'], $inputs->each(fn (Crawler $input) => $input->attr('name')));
        $id = str_replace('.', '_', $column);
        $this->assertSame(['start' => $id.'_start', 'end' => $id.'_end'], $between->getId());
        foreach (['start', 'end'] as $index => $endpoint) {
            $this->assertSame('', $inputs->eq($index)->attr('value'));
            if ($mode === 'datetime') {
                $this->assertSame($id.'_'.$endpoint, $inputs->eq($index)->attr('id'));
            } else {
                $this->assertContains($id.'_'.$endpoint, explode(' ', $inputs->eq($index)->attr('class')));
            }
        }
    }

    private function submitForm(string $html, string $path, array $bounds): array
    {
        $crawler = new Crawler($html, 'http://localhost'.$path);
        $names = $crawler->filter('input[type="text"]')->each(fn (Crawler $input) => $input->attr('name'));
        $this->assertCount(2, $names);
        $form = $crawler->filter('form')->form(array_combine($names, $bounds));
        $this->assertSame('GET', $form->getMethod());
        $process = new Process(['node', __DIR__.'/javascript/grid-between-form.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode(['html' => $html, 'url' => 'http://localhost'.$path, 'bounds' => $bounds], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($names, $native['names']);
        $this->assertSame([[$names[0], $bounds[0]], [$names[1], $bounds[1]]], $native['entries']);
        $native['query'] = $this->parseFormQuery($native['uri']);
        $this->assertSame($this->parseFormQuery($form->getUri()), $native['query']);

        return $native;
    }

    private function parseFormQuery(string $uri): array
    {
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function resetUrl(string $html, string $path): string
    {
        return (new Crawler($html, 'http://localhost'.$path))->filter('a.btn-default')->link()->getUri();
    }

    private function assertResult(array $result, array $bounds, array $rows): void
    {
        $this->assertSame($rows, $result['rows']);
        $this->assertSame(count($rows), $result['total']);
        $inputs = (new Crawler($result['html']))->filter('input[type="text"]');
        $this->assertSame($bounds, $inputs->each(fn (Crawler $input) => $input->attr('value')));
        $this->assertSql($result['queries'], $bounds, $rows !== []);
    }

    private function assertSql(array $queries, array $bounds, bool $hasRows = true): void
    {
        $rowQueries = array_values(array_filter($queries, fn ($query) =>
            str_starts_with($query['query'], 'select * from "grid_between_name_items"')));
        $countQueries = array_values(array_filter($queries, fn ($query) =>
            preg_match('/^select count\(\*\) as (?:"aggregate"|aggregate) from "grid_between_name_items"/', $query['query'])));
        $this->assertCount(1, $countQueries);
        // Laravel omits the row query when the paginator count is zero.
        $this->assertCount($hasRows ? 1 : 0, $rowQueries);
        $bindings = array_values(array_filter($bounds, fn ($bound) => $bound !== ''));
        foreach (array_merge($countQueries, $rowQueries) as $query) {
            $this->assertSame($bindings, $query['bindings']);
            if ($bindings === []) {
                $this->assertStringNotContainsString(' where ', $query['query']);
            } elseif (count($bindings) === 2) {
                $this->assertStringContainsString('"quantity" between ? and ?', $query['query']);
            } else {
                $this->assertStringContainsString('"quantity" '.($bounds[0] === '' ? '<=' : '>=').' ?', $query['query']);
            }
        }
    }
}
