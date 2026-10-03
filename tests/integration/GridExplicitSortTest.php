<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class GridExplicitSortItem extends Model
{
    protected $table = 'grid_explicit_sort_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Production headers, filters and paginator links followed through HTTP/SQLite. */
class GridExplicitSortTest extends TestCase
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
        $router->get('/grid-explicit-sort', function () {
            $results = [];
            foreach (['orders', 'invoices'] as $name) {
                $grid = new Grid(new GridExplicitSortItem());
                $grid->setName($name)->paginate(5);
                // Sortable columns capture this key when they are configured.
                $grid->model()->setSortName($name.'_sort');
                $grid->model()->orderBy('id');
                $id = $grid->column('id')->sortable();
                $rank = $grid->column('rank')->sortable();
                $filter = $grid->getFilter();
                $filter->disableIdFilter();
                $filter->equal('status');
                $filter->between('id');
                $data = $filter->execute();
                $paginator = $grid->model()->eloquent();
                $results[$name] = [
                    'ids' => array_column($data, 'id'),
                    'total' => $paginator->total(),
                    'idHeader' => $id->renderHeader(),
                    'rankHeader' => $rank->renderHeader(),
                    'links' => (string) $grid->paginator(),
                    'nextUrl' => $paginator->nextPageUrl(),
                    'filterHtml' => $filter->render(),
                ];
            }

            return $results;
        });
    }

    protected function setUp(): void
    {
        // Snapshot only the package statics these renderers/columns mutate.
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
            Schema::create('grid_explicit_sort_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('rank');
                $table->string('status');
            });
            for ($id = 1; $id <= 80; ++$id) {
                GridExplicitSortItem::create([
                    'id' => $id, 'rank' => 81 - $id, 'status' => $id % 2 ? 'closed' : 'open',
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

    private function gridQuery(): array
    {
        return [
            'orders_sort' => ['column' => 'id', 'type' => 'asc'],
            'invoices_sort' => ['column' => 'id', 'type' => 'desc'],
            'orders_status' => 'open', 'invoices_status' => 'closed',
            'orders_id' => ['start' => 10, 'end' => 70],
            'invoices_id' => ['start' => 11, 'end' => 69],
            'orders_page' => 2, 'invoices_page' => 3,
            'orders_per_page' => 5, 'invoices_per_page' => 5,
            'context' => ['view' => 'summary'],
        ];
    }

    private function requestGrid(array $query)
    {
        return $this->get('/grid-explicit-sort?'.http_build_query($query))->assertOk();
    }

    private function headerUrl(string $html): string
    {
        preg_match('/href="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'The production header must contain a sort link.');

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    private function assertQuery(string $url, array $expected): void
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $actual);
        $this->assertEquals($expected, $actual);
    }

    public function test_explicit_sort_toggles_keep_other_sort_and_applied_filters(): void
    {
        $query = $this->gridQuery();
        $response = $this->requestGrid($query);
        $response->assertJsonPath('orders.ids', [20, 22, 24, 26, 28])
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41])
            ->assertJsonPath('orders.total', 31)
            ->assertJsonPath('invoices.total', 30);
        $this->assertStringContainsString('fa-sort-amount-asc', $response->json('orders.idHeader'));
        $url = $this->headerUrl($response->json('orders.idHeader'));
        $query['orders_sort']['type'] = 'desc';
        $this->assertQuery($url, $query);
        $next = $this->get($url)->assertOk();
        $next->assertJsonPath('orders.ids', [60, 58, 56, 54, 52])
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41]);
        $this->assertStringContainsString('fa-sort-amount-desc', $next->json('orders.idHeader'));
        $url = $this->headerUrl($next->json('orders.idHeader'));
        $this->assertQuery($url, $this->gridQuery());
        $this->get($url)->assertOk()
            ->assertJsonPath('orders.ids', [20, 22, 24, 26, 28])
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41]);
    }

    public function test_switching_columns_and_sorting_the_second_grid_are_independent(): void
    {
        $query = $this->gridQuery();
        $response = $this->requestGrid($query);
        $url = $this->headerUrl($response->json('orders.rankHeader'));
        $query['orders_sort'] = ['column' => 'rank', 'type' => 'desc'];
        $this->assertQuery($url, $query);
        $next = $this->get($url)->assertOk();
        $next->assertJsonPath('orders.ids', [20, 22, 24, 26, 28]);
        // Rank is unique and inverse to ID: toggling it must change the row order.
        $url = $this->headerUrl($next->json('orders.rankHeader'));
        $query['orders_sort']['type'] = 'asc';
        $this->assertQuery($url, $query);
        $next = $this->get($url)->assertOk();
        $next->assertJsonPath('orders.ids', [60, 58, 56, 54, 52])
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41]);
        $url = $this->headerUrl($next->json('invoices.idHeader'));
        $query['invoices_sort']['type'] = 'asc';
        $this->assertQuery($url, $query);
        $this->get($url)->assertOk()
            ->assertJsonPath('orders.ids', [60, 58, 56, 54, 52])
            ->assertJsonPath('invoices.ids', [31, 33, 35, 37, 39]);
    }

    public function test_rendered_page_and_per_page_links_keep_both_sorts_and_filters(): void
    {
        $query = $this->gridQuery();
        $response = $this->requestGrid($query);
        $url = $response->json('orders.nextUrl');
        $expected = $query;
        $expected['orders_page'] = 3;
        $this->assertQuery($url, $expected);
        $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $response->json('orders.links'));
        $this->get($url)->assertOk()
            ->assertJsonPath('orders.ids', [30, 32, 34, 36, 38])
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41]);
        preg_match('/<option value="([^"]+)"[^>]*>10<\/option>/', $response->json('orders.links'), $matches);
        $this->assertNotEmpty($matches, 'The production page-size selector must offer 10 rows.');
        $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $expected = $query;
        $expected['orders_per_page'] = 10;
        $this->assertQuery($url, $expected);
        $this->get($url)->assertOk()
            ->assertJsonPath('orders.ids', range(30, 48, 2))
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41]);
    }

    public function test_filter_reset_keeps_explicit_sorts_and_other_grid_state(): void
    {
        $query = $this->gridQuery();
        $query['orders_sort']['type'] = 'desc';
        $response = $this->requestGrid($query);
        $url = $this->headerUrl($response->json('orders.filterHtml'));
        $this->assertStringContainsString('fa-undo', $response->json('orders.filterHtml'));
        unset($query['orders_status'], $query['orders_id'], $query['orders_page']);
        $this->assertQuery($url, $query);
        $this->get($url)->assertOk()
            ->assertJsonPath('orders.ids', [80, 79, 78, 77, 76])
            ->assertJsonPath('orders.total', 80)
            ->assertJsonPath('invoices.ids', [49, 47, 45, 43, 41])
            ->assertJsonPath('invoices.total', 30);
    }
}
