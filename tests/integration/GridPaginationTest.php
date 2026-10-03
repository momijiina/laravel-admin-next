<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class GridPaginationItem extends Model
{
    protected $table = 'grid_pagination_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Actual HTTP requests, SQLite rows, and production paginator links. */
class GridPaginationTest extends TestCase
{
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
        $router->get('/grid-pagination', function () {
            $results = [];
            foreach (['orders', 'invoices'] as $name) {
                $grid = new Grid(new GridPaginationItem());
                $named = !request('unnamed');
                if ($named && !request('name_after')) {
                    $grid->setName($name);
                }
                switch (request('mode', 'configured')) {
                    case 'default':
                        break;
                    case 'empty':
                        $grid->model()->paginate();
                        break;
                    case 'columns':
                        $grid->model()->paginate(10, ['id']);
                        break;
                    case 'standard':
                        $grid->model()->paginate(10, ['id'], 'page');
                        break;
                    case 'numeric_total':
                        $grid->model()->paginate(10, ['id'], $name.'_custom', 4, 55);
                        break;
                    case 'explicit':
                        $grid->model()->paginate(10, ['id'], $name.'_custom');
                        break;
                    case 'page':
                        $grid->model()->paginate(10, ['id'], $name.'_custom', 4);
                        break;
                    case 'total':
                        $grid->model()->paginate(10, ['id'], $name.'_custom', 4, function () {
                            return 55;
                        });
                        break;
                    default:
                        $grid->paginate(10);
                }
                if ($named && request('name_after')) {
                    $grid->setName($name);
                }
                $grid->model()->orderBy('id');
                $grid->column('id')->sortable();
                $data = $grid->model()->buildData();
                $paginator = $grid->model()->eloquent();
                $links = (string) $grid->paginator();
                $results[$name] = [
                    'ids' => array_column($data, 'id'),
                    'columns' => array_keys($data[0]),
                    'pageName' => $paginator->getPageName(),
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'modelPerPage' => $grid->model()->getPerPage(),
                    'total' => $paginator->total(),
                    'page2Url' => $paginator->url(2),
                    'nextUrl' => $paginator->nextPageUrl(),
                    'links' => $links,
                ];
            }

            return $results;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('grid_pagination_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('label');
        });
        for ($id = 1; $id <= 65; ++$id) {
            GridPaginationItem::create(['id' => $id, 'label' => 'Row '.$id]);
        }
    }

    public function test_named_page_sizes_and_name_assignment_order_are_independent(): void
    {
        foreach (['configured' => 10, 'default' => 20, 'empty' => 20, 'columns' => 10] as $mode => $size) {
            foreach ([0, 1] as $nameAfter) {
                $response = $this->get('/grid-pagination?'.http_build_query([
                    'mode' => $mode, 'name_after' => $nameAfter,
                    'orders_page' => 2, 'invoices_page' => 3, 'page' => 4,
                ]))->assertOk();
                foreach (['orders' => 2, 'invoices' => 3] as $name => $page) {
                    $response->assertJsonPath($name.'.pageName', $name.'_page')
                        ->assertJsonPath($name.'.page', $page)
                        ->assertJsonPath($name.'.perPage', $size)
                        ->assertJsonPath($name.'.ids', range(($page - 1) * $size + 1, $page * $size));
                    if ($mode === 'columns') {
                        $response->assertJsonPath($name.'.columns', ['id']);
                    }
                }
            }
        }
    }

    public function test_generated_links_preserve_queries_and_only_advance_the_selected_grid(): void
    {
        $query = [
            'orders_page' => 2, 'invoices_page' => 3, 'orders_per_page' => 5,
            'filter' => ['status' => 'open'], '_sort' => ['column' => 'id', 'type' => 'desc'],
        ];
        $response = $this->get('/grid-pagination?'.http_build_query($query))->assertOk();
        $response->assertJsonPath('orders.ids', range(60, 56))
            ->assertJsonPath('orders.perPage', 5)
            ->assertJsonPath('invoices.ids', range(45, 36));
        foreach (['orders' => 3, 'invoices' => 4] as $name => $nextPage) {
            $url = $response->json($name.'.nextUrl');
            parse_str(parse_url($url, PHP_URL_QUERY), $actual);
            $expected = $query;
            $expected[$name.'_page'] = $nextPage;
            $this->assertEquals($expected, $actual);
            $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $response->json($name.'.links'));
            $next = $this->get($url)->assertOk();
            $next->assertJsonPath('orders.ids', $name === 'orders' ? range(55, 51) : range(60, 56))
                ->assertJsonPath('invoices.ids', $name === 'invoices' ? range(35, 26) : range(45, 36));
        }
    }

    public function test_explicit_pagination_arguments_are_preserved_with_page_size_overrides(): void
    {
        foreach (['explicit', 'page', 'total', 'numeric_total'] as $mode) {
            foreach ([0, 1] as $nameAfter) {
                $response = $this->get('/grid-pagination?'.http_build_query([
                    'mode' => $mode, 'name_after' => $nameAfter,
                    'orders_page' => 2, 'invoices_page' => 3,
                    'orders_custom' => 2, 'invoices_custom' => 3, 'orders_per_page' => 5,
                ]))->assertOk();
                foreach (['orders' => 2, 'invoices' => 3] as $name => $requestedPage) {
                    $page = $mode === 'explicit' ? $requestedPage : 4;
                    $size = $name === 'orders' ? 5 : 10;
                    $response->assertJsonPath($name.'.pageName', $name.'_custom')
                        ->assertJsonPath($name.'.page', $page)
                        ->assertJsonPath($name.'.perPage', $size)
                        ->assertJsonPath($name.'.columns', ['id'])
                        ->assertJsonPath($name.'.total', in_array($mode, ['total', 'numeric_total'], true) ? 55 : 65)
                        ->assertJsonPath($name.'.ids', range(($page - 1) * $size + 1, $page * $size));
                    parse_str(parse_url($response->json($name.'.page2Url'), PHP_URL_QUERY), $linkQuery);
                    $this->assertSame('2', $linkQuery[$name.'_custom']);
                }
            }
        }
    }

    public function test_overrides_without_configuration_and_with_selected_columns_keep_grid_names(): void
    {
        foreach (['default', 'columns'] as $mode) {
            $response = $this->get('/grid-pagination?mode='.$mode.'&orders_page=2&orders_per_page=5&invoices_page=3')->assertOk();
            $response->assertJsonPath('orders.ids', range(6, 10))
                ->assertJsonPath('orders.pageName', 'orders_page')
                ->assertJsonPath('orders.perPage', 5);
            if ($mode === 'default') {
                $response->assertJsonPath('orders.modelPerPage', 5);
            } else {
                $response->assertJsonPath('orders.columns', ['id']);
            }
        }
    }

    public function test_explicit_standard_page_name_is_not_replaced(): void
    {
        $response = $this->get('/grid-pagination?mode=standard&page=3&orders_page=2&invoices_page=4')->assertOk();
        foreach (['orders', 'invoices'] as $name) {
            $response->assertJsonPath($name.'.pageName', 'page')
                ->assertJsonPath($name.'.ids', range(21, 30));
        }
    }

    public function test_unnamed_grids_keep_the_standard_page_parameter(): void
    {
        foreach (['default' => 20, 'empty' => 20, 'configured' => 10, 'columns' => 10] as $mode => $size) {
            foreach ([null, 5] as $override) {
                $response = $this->get('/grid-pagination?'.http_build_query([
                    'mode' => $mode, 'unnamed' => 1, 'page' => 2, 'per_page' => $override,
                ]))->assertOk();
                $perPage = $override ?? $size;
                foreach (['orders', 'invoices'] as $name) {
                    $response->assertJsonPath($name.'.pageName', 'page')
                        ->assertJsonPath($name.'.page', 2)
                        ->assertJsonPath($name.'.perPage', $perPage)
                        ->assertJsonPath($name.'.ids', range($perPage + 1, 2 * $perPage));
                }
            }
        }
    }
}
