<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column\Sorter;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/** Real request bags and production grid consumers; no replacement package classes. */
class GridRequestInputTest extends TestCase
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

    public function test_quick_search_preserves_legacy_input_and_falsy_short_circuit(): void
    {
        $originalKey = Grid::$searchKey;
        try {
            foreach (['__search__', 'grid.search'] as $key) {
                Grid::$searchKey = $key;
                foreach ($this->requests($key) as $request) {
                    $expected = $this->legacyValue($request, $key);
                    $this->app->instance('request', $request);
                    $grid = new Grid(new Administrator());
                    $captured = new \stdClass();
                    $untouched = $captured;
                    $grid->quickSearch(function ($model, $query) use (&$captured, $grid) {
                        $this->assertSame($grid->model(), $model);
                        $captured = $query;
                    });
                    $this->withoutDeprecatedGetter(function () use ($grid) {
                        (new \ReflectionMethod(Grid::class, 'applyQuickSearch'))->invoke($grid);
                    });
                    $this->assertSame($expected ? $expected : $untouched, $captured);
                }
            }
        } finally {
            Grid::$searchKey = $originalKey;
        }
    }

    public function test_sorter_preserves_exact_legacy_input_and_selected_column(): void
    {
        foreach (['_sort', 'grid.sort'] as $key) {
            foreach ($this->requests($key) as $request) {
                $expected = $this->legacyValue($request, $key);
                $this->app->instance('request', $request);
                $sorter = new Sorter($key, 'name', null);
                $sorted = $this->withoutDeprecatedGetter(function () use ($sorter) {
                    return (new \ReflectionMethod(Sorter::class, 'isSorted'))->invoke($sorter);
                });
                $this->assertSame($expected, (new \ReflectionProperty(Sorter::class, 'sort'))->getValue($sorter));
                $this->assertSame(!empty($expected) && isset($expected['column']) && $expected['column'] == 'name', $sorted);
            }
        }
    }

    public function test_quick_search_still_builds_real_eloquent_conditions(): void
    {
        foreach ([false, true] as $parsed) {
            $this->app->instance('request', new Request(['__search__' => $parsed ? 'username:Ada' : 'Ada']));
            $grid = new Grid(new Administrator());
            $grid->column('username');
            $grid->quickSearch($parsed ? null : 'username');
            $query = $this->withoutDeprecatedGetter(function () use ($grid) {
                (new \ReflectionMethod(Grid::class, 'applyQuickSearch'))->invoke($grid);
                return $grid->model()->getQueryBuilder();
            });
            $this->assertSame($parsed ? ['Ada'] : ['%Ada%'], $query->getBindings());
            $this->assertStringContainsString($parsed ? '"username" = ?' : '"username" like ?', $query->toSql());
        }
    }

    public function test_sorter_render_preserves_toggle_cast_and_other_query_values(): void
    {
        foreach (['asc' => 'desc', 'desc' => 'asc'] as $current => $next) {
            $request = Request::create('/admin/users', 'GET', [
                '_sort' => ['column' => 'name', 'type' => $current],
                'filter' => ['active' => '1'], '__search__' => 'Ada',
            ]);
            $this->app->instance('request', $request);
            $html = $this->withoutDeprecatedGetter(function () {
                return (new Sorter('_sort', 'name', 'unsigned'))->render();
            });
            $this->assertStringContainsString('fa-sort-amount-'.$current, $html);
            preg_match('/href="([^"]+)"/', $html, $matches);
            parse_str(parse_url($matches[1], PHP_URL_QUERY), $query);
            $this->assertSame([
                '_sort' => ['column' => 'name', 'type' => $next, 'cast' => 'unsigned'],
                'filter' => ['active' => '1'], '__search__' => 'Ada',
            ], $query);
        }
    }

    private function requests(string $key): iterable
    {
        $values = [[], [$key => null], [$key => ''], [$key => 0], [$key => '0'],
            [$key => false], [$key => true], [$key => 'Ada'], [$key => []],
            [$key => ['column' => 'name', 'type' => 'asc']],
            [$key => ['column' => 'other', 'type' => 'desc']]];
        foreach ($values as $attributes) {
            foreach ($values as $query) {
                foreach ($values as $body) {
                    $request = new Request($query, $body, $attributes);
                    $request->setMethod('POST');
                    yield $request;
                }
            }
        }

        // The original getter uses the request object itself as the absent sentinel.
        $request = new Request([$key => 'query'], [$key => 'body']);
        $request->attributes->set($key, $request);
        yield $request;
        $request = new Request([], [$key => 'body']);
        $request->attributes->set($key, $request);
        yield $request;
        $request = new Request();
        $request->attributes->set($key, $request);
        yield $request;

        // Dotted keys are literal, never Arr::get-style nested lookups.
        yield new Request(['grid' => ['search' => 'nested', 'sort' => ['column' => 'name']]]);
        yield Request::create('/admin/users', 'GET', [$key => 'query']);

        // Bare JSON is a separate bag; createFromBase populates the request bag.
        $server = ['CONTENT_TYPE' => 'application/json'];
        $json = json_encode([$key => ['column' => 'name', 'type' => 'asc']]);
        yield Request::create('/admin/users', 'POST', [], [], [], $server, $json);
        yield Request::createFromBase(SymfonyRequest::create('/admin/users', 'POST', [], [], [], $server, $json));
        $request = Request::create('/admin/users', 'POST', [], [], [], $server, $json);
        $request->request->set($key, 'body');
        yield $request;
    }

    private function legacyValue(Request $request, string $key)
    {
        // Suppress only the intentional reference call, never production execution.
        set_error_handler(function ($severity, $message) {
            return $severity === E_USER_DEPRECATED && str_contains($message, 'Request::get() is deprecated');
        });
        try {
            return $request->get($key);
        } finally {
            restore_error_handler();
        }
    }

    private function withoutDeprecatedGetter(callable $callback)
    {
        // Catch Symfony's @trigger_error as well as unsuppressed diagnostics.
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_USER_DEPRECATED && str_contains($message, 'Request::get() is deprecated')) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
