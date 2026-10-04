<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Displayers\Carousel;
use Encore\Admin\Widgets\Carousel as CarouselWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class GridCarouselItem extends Model
{
    protected $table = 'grid_carousel_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['images' => 'array'];
}

/** Real Grid rendering with nullable array casts, plus existing displayer contracts. */
class GridCarouselTest extends TestCase
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
        $app['config']->set('admin.upload.disk', 'carousel');
        $app['config']->set('filesystems.disks.carousel', [
            'driver' => 'local', 'root' => storage_path('app/carousel'),
            'url' => 'https://storage.example.test/uploads',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->get('/grid-carousel/{options}', function ($options) {
            $grid = new Grid(new GridCarouselItem());
            $grid->model()->orderBy('id');
            if (request()->has('id')) {
                $grid->model()->where('id', request('id'));
            }
            $grid->column('id', 'ID');
            $images = $grid->column('images', 'Images');
            if ($options === 'custom') {
                $images->carousel(420, 280, 'https://cdn.example.test/gallery/');
            } else {
                $images->carousel();
            }
            $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();

            return $grid->render();
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            Schema::create('grid_carousel_items', function (Blueprint $table) {
                $table->increments('id');
                $table->text('images')->nullable();
            });
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

    private function createItem($images): GridCarouselItem
    {
        return GridCarouselItem::create(['images' => $images])->fresh();
    }

    private function gridHtml(?int $id = null, string $options = 'default'): Crawler
    {
        $response = $this->get('/grid-carousel/'.$options.($id === null ? '' : '?id='.$id))->assertOk();
        $html = new Crawler($response->getContent());
        $this->assertCount(1, $html->filter('table.grid-table'));

        return $html;
    }

    private function displayer($images): Carousel
    {
        $grid = new Grid(new GridCarouselItem());
        $row = new GridCarouselItem(['id' => 7]);

        return new Carousel($images, $grid, $grid->column('images', 'Images'), $row);
    }

    private function assertCarousel(Crawler $html, int $id, array $images, array $indexes = []): void
    {
        $carousel = $html->filter('#carousel-images-'.$id);
        $this->assertCount(1, $carousel);
        $this->assertSame($images, $carousel->filter('.carousel-inner img')->extract(['src']));
        $this->assertSame($indexes ?: array_map('strval', array_keys($images)), $carousel->filter('.carousel-indicators li')->extract(['data-slide-to']));
        $this->assertCount(count($images) ? 1 : 0, $carousel->filter('.carousel-inner .item.active'));
        $this->assertSame(['#carousel-images-'.$id, '#carousel-images-'.$id], $carousel->filter('a.carousel-control')->extract(['href']));
    }

    public function test_sql_null_array_cast_renders_an_empty_cell(): void
    {
        $item = $this->createItem(null);
        $this->assertNull(DB::table('grid_carousel_items')->where('id', $item->id)->value('images'));
        $this->assertNull($item->images);
        $html = $this->gridHtml($item->id);
        $this->assertCount(1, $html->filter('tbody tr'));
        $this->assertSame((string) $item->id, trim($html->filter('td.column-id')->text()));
        $this->assertSame('', trim($html->filter('td.column-images')->html()));
        $this->assertCount(0, $html->filter('.carousel'));
        $this->assertSame('', $this->displayer(null)->display());
    }

    public function test_empty_array_keeps_the_same_empty_cell(): void
    {
        $item = $this->createItem([]);
        $this->assertSame('[]', $item->getRawOriginal('images'));
        $this->assertSame([], $item->images);
        $html = $this->gridHtml($item->id);
        $this->assertSame('', trim($html->filter('td.column-images')->html()));
        $this->assertCount(0, $html->filter('.carousel'));
        $this->assertSame('', $this->displayer([])->display());
    }

    public function test_populated_array_keeps_default_dimensions_and_image_order(): void
    {
        $images = ['https://example.test/one.jpg', 'https://example.test/two.jpg'];
        $item = $this->createItem($images);
        $this->assertSame($images, $item->images);
        $html = $this->gridHtml($item->id);
        $this->assertCarousel($html, $item->id, $images);
        $this->assertStringContainsString('width:300px;', $html->filter('.carousel')->attr('style'));
        foreach ($html->filter('.carousel-inner img')->extract(['style']) as $style) {
            $this->assertStringContainsString('max-width:300px;max-height:200px;', $style);
        }
    }

    public function test_sparse_and_associative_array_casts_are_reindexed_in_insertion_order(): void
    {
        foreach ([
            [8 => 'https://example.test/one.jpg', 3 => 'https://example.test/two.jpg'],
            ['cover' => 'https://example.test/one.jpg', 'detail' => 'https://example.test/two.jpg'],
        ] as $images) {
            $item = $this->createItem($images);
            $this->assertSame($images, $item->images);
            $this->assertCarousel($this->gridHtml($item->id), $item->id, array_values($images));
        }
    }

    public function test_null_row_does_not_prevent_other_rows_from_rendering(): void
    {
        $null = $this->createItem(null);
        $empty = $this->createItem([]);
        $populated = $this->createItem(['https://example.test/one.jpg']);
        $html = $this->gridHtml();
        $this->assertSame([(string) $null->id, (string) $empty->id, (string) $populated->id], array_map('trim', $html->filter('td.column-id')->extract(['_text'])));
        $this->assertCount(1, $html->filter('.carousel'));
        $this->assertCarousel($html, $populated->id, $populated->images);
    }

    public function test_custom_dimensions_server_and_existing_url_normalization(): void
    {
        $item = $this->createItem(['/one.jpg', 'two.jpg', 'https://example.test/three.jpg', 'data:image/png;base64,AAAA']);
        $html = $this->gridHtml($item->id, 'custom');
        // The view still passes every image through url(), including data images.
        $this->assertCarousel($html, $item->id, ['https://cdn.example.test/gallery/one.jpg', 'https://cdn.example.test/gallery/two.jpg', 'https://example.test/three.jpg', 'http://localhost/data:image/png;base64,AAAA']);
        $this->assertStringContainsString('width:420px;', $html->filter('.carousel')->attr('style'));
        foreach ($html->filter('.carousel-inner img')->extract(['style']) as $style) {
            $this->assertStringContainsString('max-width:420px;max-height:280px;', $style);
        }
    }

    public function test_relative_paths_use_the_configured_storage_disk(): void
    {
        $item = $this->createItem(['one.jpg', 'gallery/two.jpg']);
        $this->assertCarousel($this->gridHtml($item->id), $item->id, ['https://storage.example.test/uploads/one.jpg', 'https://storage.example.test/uploads/gallery/two.jpg']);
    }

    public function test_arrayable_conversion_preserves_empty_and_sparse_collections(): void
    {
        $this->assertSame('', $this->displayer(collect())->display());
        $images = [8 => 'https://example.test/one.jpg', 3 => 'https://example.test/two.jpg'];
        $displayer = $this->displayer(collect($images));
        $widget = $displayer->display();
        $this->assertInstanceOf(CarouselWidget::class, $widget);
        $this->assertSame(array_values($images), $displayer->getValue());
        $this->assertCarousel(new Crawler($widget->render()), 7, array_values($images));
    }

    public function test_existing_falsey_member_filtering_does_not_reindex_a_second_time(): void
    {
        $images = ['https://example.test/one.jpg', null, '', false, 0, '0', 'https://example.test/two.jpg'];
        $item = $this->createItem($images);
        $this->assertCarousel($this->gridHtml($item->id), $item->id, [$images[0], $images[6]], ['0', '6']);
        // A nonempty input whose members are all filtered still returns a widget.
        $widget = $this->displayer([null, '', false, 0, '0'])->display();
        $this->assertInstanceOf(CarouselWidget::class, $widget);
        $this->assertCarousel(new Crawler($widget->render()), 7, []);
    }

    public function test_unsupported_non_null_values_still_raise_type_errors(): void
    {
        foreach (['', 'one.jpg', false, true, 0, 1, 1.5, new \stdClass()] as $value) {
            try {
                $this->displayer($value)->display();
                $this->fail('Only null, arrays and Arrayable values are supported.');
            } catch (\TypeError $exception) {
                $this->assertStringContainsString('array_values()', $exception->getMessage());
            }
        }
    }
}
