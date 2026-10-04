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
use Symfony\Component\DomCrawler\Crawler;

class GridInequalityItem extends Model
{
    protected $table = 'grid_inequality_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Rendered GET forms and reset links exercise the longstanding inclusive SQL. */
class GridInequalityFilterTest extends TestCase
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
        $router->get('/grid-inequality/{mode}/{locale}', function ($mode, $locale) {
            abort_unless(in_array($mode, ['gt', 'lt'], true), 404);
            abort_unless(in_array($locale, ['en', 'zh-CN'], true), 404);
            app()->setLocale($locale);
            $grid = new Grid(new GridInequalityItem());
            $grid->paginate(20);
            $grid->model()->orderBy('id');
            $grid->column('id');
            $grid->column('quantity');
            $filter = $grid->getFilter();
            $filter->disableIdFilter();
            $filter->$mode('quantity', $locale === 'en' ? 'Quantity' : '数量');

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
        });
    }

    protected function setUp(): void
    {
        // Retain host values for the package statics touched by these renderers.
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
            Schema::create('grid_inequality_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('quantity');
            });
            foreach ([-2, -1, 0, 1, 2] as $quantity) {
                GridInequalityItem::create(['quantity' => $quantity]);
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

    public function test_gt_label_matches_inclusive_boundaries_and_reset(): void
    {
        $this->assertBoundaries('gt', '>=', [
            ['-2', [-2, -1, 0, 1, 2]],
            ['-1', [-1, 0, 1, 2]],
            ['0', [0, 1, 2]],
            ['1', [1, 2]],
            ['2', [2]],
            ['', [-2, -1, 0, 1, 2]],
        ]);
    }

    public function test_lt_label_matches_inclusive_boundaries_and_reset(): void
    {
        $this->assertBoundaries('lt', '<=', [
            ['-2', [-2]],
            ['-1', [-2, -1]],
            ['0', [-2, -1, 0]],
            ['1', [-2, -1, 0, 1]],
            ['2', [-2, -1, 0, 1, 2]],
            ['', [-2, -1, 0, 1, 2]],
        ]);
    }

    private function assertBoundaries(string $mode, string $operator, array $cases): void
    {
        foreach (['en' => 'Quantity', 'zh-CN' => '数量'] as $locale => $label) {
            $path = '/grid-inequality/'.$mode.'/'.$locale;
            $initial = $this->get($path)->assertOk();
            $this->assertResult($initial, $label, $operator, '', [-2, -1, 0, 1, 2]);
            $html = $initial->json('html');
            foreach ($cases as [$value, $expected]) {
                // Submit the production form, including redisplayed values from
                // the previous request; do not construct the filter query by hand.
                $form = (new Crawler($html, 'http://localhost'.$path))
                    ->filter('form')->form(['quantity' => $value]);
                $this->assertSame('GET', $form->getMethod());
                $response = $this->get($form->getUri())->assertOk();
                $this->assertResult($response, $label, $operator, $value, $expected);
                $html = $response->json('html');

                // Follow the actual reset link and verify both SQL and controls.
                $reset = (new Crawler($html, 'http://localhost'.$path))
                    ->filter('a.btn-default')->link()->getUri();
                $this->assertSame($path, parse_url($reset, PHP_URL_PATH));
                parse_str(parse_url($reset, PHP_URL_QUERY) ?? '', $resetQuery);
                $this->assertSame([], $resetQuery);
                $resetResponse = $this->get($reset)->assertOk();
                $this->assertResult($resetResponse, $label, $operator, '', [-2, -1, 0, 1, 2]);
            }
        }
    }

    private function assertResult($response, string $label, string $operator, string $value, array $expected): void
    {
        $response->assertJsonPath('rows', $expected)->assertJsonPath('total', count($expected));
        $rendered = new Crawler($response->json('html'));
        $this->assertSame($label.' ('.$operator.')', str_replace("\xc2\xa0", ' ', $rendered->filter('label')->text()));
        $this->assertSame($value, $rendered->filter('input[name="quantity"]')->attr('value'));

        $queries = array_values(array_filter($response->json('queries'), function ($query) {
            return str_starts_with($query['query'], 'select * from "grid_inequality_items"');
        }));
        $this->assertCount(1, $queries, 'Inspect the actual row query, not only the paginator count.');
        if ($value === '') {
            $this->assertStringNotContainsString(' where ', $queries[0]['query']);
            $this->assertSame([], $queries[0]['bindings']);
        } else {
            $this->assertStringContainsString('"quantity" '.$operator.' ?', $queries[0]['query']);
            $this->assertSame([$value], $queries[0]['bindings']);
            $this->assertContains((int) $value, $response->json('rows'), 'The matching boundary remains included.');
        }
    }
}
