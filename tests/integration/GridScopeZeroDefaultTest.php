<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter\Scope;
use Encore\Admin\Grid\Tools\FilterButton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class GridScopeZeroDefaultRecord extends Model
{
    protected $table = 'grid_scope_zero_default_records';
    protected $guarded = [];
    public $timestamps = false;
}

class GridScopeZeroDefaultTest extends TestCase
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
        $router->get('/grid-scope-zero/{order}/{keyType}', function ($order, $keyType) {
            abort_unless(in_array($order, ['first', 'last'], true), 404);
            abort_unless(in_array($keyType, ['integer', 'string'], true), 404);
            $grid = new Grid(new GridScopeZeroDefaultRecord());
            $grid->model()->orderBy('id');
            $filter = $grid->getFilter()->disableIdFilter();
            $default = function () use ($filter) {
                $filter->scope('positive', 'Positive records')->where('bucket', 'positive')->asDefault();
            };
            if ($order === 'first') {
                $default();
            }
            $filter->scope($keyType === 'integer' ? 0 : '0', 'Zero records')->where('bucket', 'zero');
            $filter->scope('negative', 'Negative records')->where('bucket', 'negative');
            if ($order === 'last') {
                $default();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $rows = array_column($filter->execute(), 'id');
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $current = $filter->getCurrentScope();

            return [
                'rows' => $rows, 'queries' => $queries,
                'scope' => $current ? $current->key : null,
                'requestScope' => request()->input(Scope::QUERY_NAME),
                'html' => (string) (new FilterButton())->setGrid($grid)->render(),
            ];
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
        Schema::create('grid_scope_zero_default_records', function ($table) {
            $table->increments('id');
            $table->string('bucket');
        });
        foreach (['zero', 'positive', 'negative', 'zero'] as $bucket) {
            GridScopeZeroDefaultRecord::create(['bucket' => $bucket]);
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

    public static function configurations(): iterable
    {
        foreach (['first', 'last'] as $order) {
            foreach (['integer', 'string'] as $keyType) {
                yield "$order $keyType" => [$order, $keyType];
            }
        }
    }

    #[DataProvider('configurations')]
    public function test_zero_scope_links_override_the_default_and_survive_repeated_requests(string $order, string $keyType): void
    {
        $path = "/grid-scope-zero/$order/$keyType";
        $initial = $this->get($path.'?marker=keep')->assertOk()->json();
        $this->assertResult($initial, 'positive', 'Positive records', [2]);

        for ($round = 0; $round < 2; $round++) {
            $zeroLink = $this->link($initial, 'Zero records');
            parse_str((string) parse_url($zeroLink, PHP_URL_QUERY), $query);
            $this->assertSame('0', $query['_scope_']);
            $this->assertSame('keep', $query['marker']);
            $initial = $this->get($zeroLink)->assertOk()->json();
            $this->assertSame('0', $initial['requestScope']);
            $this->assertSame($keyType === 'integer' ? 0 : '0', $initial['scope']);
            $this->assertResult($initial, 'zero', 'Zero records', [1, 4]);
        }

        $negative = $this->get($this->link($initial, 'Negative records'))->assertOk()->json();
        $this->assertResult($negative, 'negative', 'Negative records', [3]);
        $cancel = $this->link($negative, trans('admin.cancel'));
        parse_str((string) parse_url($cancel, PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('_scope_', $query);
        $this->assertSame('keep', $query['marker']);
        $this->assertResult($this->get($cancel)->assertOk()->json(), 'positive', 'Positive records', [2]);
        $this->assertSame(4, GridScopeZeroDefaultRecord::count());
    }

    public static function requestValues(): iterable
    {
        yield 'integer zero' => [0, 0];
        yield 'string zero' => ['0', '0'];
        yield 'positive integer' => [1, 1];
        yield 'negative integer' => [-1, -1];
        yield 'named scope' => ['negative', 'negative'];
        yield 'unknown scope' => ['unknown', 'unknown'];
        yield 'absent or null' => [null, 'positive'];
        yield 'empty string' => ['', 'positive'];
        yield 'false' => [false, 'positive'];
        yield 'empty array' => [[], 'positive'];
        yield 'nonempty array' => [['negative'], ['negative']];
    }

    #[DataProvider('requestValues')]
    public function test_default_assignment_preserves_zero_and_the_existing_other_input_rules($input, $expected): void
    {
        request()->replace(['_scope_' => $input]);
        $scope = new Scope('positive', 'Positive records');
        $this->assertSame($scope, $scope->asDefault());
        $this->assertSame($expected, request()->input('_scope_'));
        // Repeated default setup must not replace an already selected zero either.
        (new Scope('negative', 'Negative records'))->asDefault();
        $this->assertSame($expected, request()->input('_scope_'));
    }

    public function test_unknown_http_scope_still_does_not_apply_the_default(): void
    {
        $result = $this->get('/grid-scope-zero/first/integer?_scope_=unknown')->assertOk()->json();
        $this->assertSame('unknown', $result['requestScope']);
        $this->assertNull($result['scope']);
        $this->assertSame([1, 2, 3, 4], $result['rows']);
        $this->assertSame('', $this->crawler($result)->filter('button.dropdown-toggle > span')->first()->text());
    }

    private function crawler(array $result): Crawler
    {
        return new Crawler($result['html'], 'http://localhost');
    }

    private function link(array $result, string $label): string
    {
        return $this->crawler($result)->selectLink($label)->link()->getUri();
    }

    private function assertResult(array $result, string $bucket, string $label, array $rows): void
    {
        $this->assertSame($rows, $result['rows']);
        $button = $this->crawler($result)->filter('button.dropdown-toggle > span')->first();
        $this->assertSame($label, trim(str_replace("\xc2\xa0", ' ', $button->text())));
        $queries = array_filter($result['queries'], fn ($query) => str_contains($query['query'], 'grid_scope_zero_default_records'));
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('"bucket" = ?', $query['query']);
            $this->assertSame([$bucket], $query['bindings']);
        }
    }
}
