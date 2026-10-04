<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class GridInlineMultipleSelectTest extends TestCase
{
    private array $packageState = [];
    private const OPTIONS = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three',
        '001' => 'Leading zeros', 'alpha' => 'Alpha', 'beta' => 'Beta',
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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/grid-inline-multiple/{id}', function ($id) {
                $previousScript = Admin::$script;
                $previousHtml = Admin::$html;
                try {
                    Admin::$script = Admin::$html = [];
                    Admin::script('window.inlineBefore = true;');
                    $grid = new Grid(new GridInlineMultipleItem());
                    $grid->model()->where('id', $id);
                    $grid->setResource('/grid-inline-multiple');
                    $grid->column('id');
                    $grid->column('choices')->multipleSelect(self::OPTIONS);
                    $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();
                    $html = $grid->render();
                    Admin::script('window.inlineAfter = true;');

                    return response()->json([
                        'html' => $html.Admin::html()->render(),
                        'scriptHtml' => Admin::script()->render(),
                    ]);
                } finally {
                    Admin::$script = $previousScript;
                    Admin::$html = $previousHtml;
                }
            });
            $router->put('/grid-inline-multiple/{id}', function ($id) {
                $form = new Form(new GridInlineMultipleItem());
                $form->multipleSelect('choices')->options(self::OPTIONS);

                return $form->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'html', 'style'],
            Form::class => ['snakeAttributes'],
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
            Schema::create('grid_inline_multiple_items', function ($table) {
                $table->increments('id');
                $table->text('choices');
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

    private function widget(array $fixture, string $jquery): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-inline-multiple-select.cjs']);
        $process->setInput(json_encode($fixture + ['jquery' => $jquery], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function selectedIds(): array
    {
        return [
            'integer IDs' => [[1, 2], ['1', '2'], ['3' => true], ['1', '2', '3'], 'One;Two', 'One;Two;Three'],
            'integer zero' => [[0], ['0'], ['3' => true], ['0', '3'], 'Zero', 'Zero;Three'],
            'mixed zero and string' => [[0, 'alpha'], ['0', 'alpha'], ['3' => true], ['0', '3', 'alpha'], 'Zero;Alpha', 'Zero;Three;Alpha'],
            'numeric strings' => [['1', '2'], ['1', '2'], ['3' => true], ['1', '2', '3'], 'One;Two', 'One;Two;Three'],
            'leading-zero string' => [['001'], ['001'], ['3' => true], ['3', '001'], 'Leading zeros', 'Three;Leading zeros'],
            'distinct integer and leading-zero IDs' => [[1, '001'], ['1', '001'], ['1' => false, '3' => true], ['3', '001'], 'One;Leading zeros', 'Three;Leading zeros'],
            'ordinary string IDs' => [['alpha'], ['alpha'], ['beta' => true], ['alpha', 'beta'], 'Alpha', 'Alpha;Beta'],
            'empty array' => [[], [], ['3' => true], ['3'], '', 'Three'],
        ];
    }

    #[DataProvider('selectedIds')]
    public function test_real_grid_popover_cancel_reopen_and_http_update_preserve_ids(
        array $stored,
        array $initial,
        array $changes,
        array $saved,
        string $label,
        string $savedLabel
    ): void {
        foreach (['shipped', 'modern'] as $jquery) {
            $item = GridInlineMultipleItem::create(['choices' => $stored])->fresh();
            $this->assertSame($stored, $item->choices);
            $url = '/grid-inline-multiple/'.$item->id;
            $fixture = $this->get($url)->assertOk()->json() + [
                'stored' => $stored, 'initial' => $initial, 'changes' => $changes,
                'saved' => $saved, 'label' => $label, 'savedLabel' => $savedLabel,
                'url' => $url,
            ];
            $result = $this->widget($fixture, $jquery);
            // Open/cancel changes neither storage nor the original integer metadata.
            $this->assertSame($stored, $item->fresh()->choices);
            $this->assertSame($initial, $result['initial']);
            $this->assertSame($url, $result['request']['url']);
            $this->assertSame('POST', $result['request']['type']);
            $this->assertSame([
                '_token' => 'test-token', '_method' => 'PUT',
                '_edit_inline' => true, 'choices' => $saved,
            ], $result['request']['data']);
            parse_str($result['request']['query'], $values);
            $this->assertSame($saved, $values['choices']);
            // Replay the actual jQuery-serialized request through web middleware and Form.
            $response = $this->post($result['request']['url'], $values, [
                'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
            ])->assertOk()->assertJson(['status' => true]);
            $this->assertSame($saved, $item->fresh()->choices);
            $this->assertSame(json_encode($saved), $item->fresh()->getRawOriginal('choices'));
            // Recreate the same editor and deliver the real HTTP response to its callback.
            $completed = $this->widget($fixture + ['response' => $response->json()], $jquery);
            $this->assertSame($result['request'], $completed['request']);
            $this->assertSame($saved, $completed['reopened']);
            $this->assertSame($savedLabel, $completed['label']);
            // A fresh server render must also retain the persisted choices and labels.
            $reloaded = $this->get($url)->assertOk()->json() + [
                'stored' => $saved, 'initial' => $saved, 'label' => $savedLabel,
                'url' => $url, 'inspectOnly' => true,
            ];
            $this->assertSame($saved, $this->widget($reloaded, $jquery)['initial']);
        }
    }
}

class GridInlineMultipleItem extends Model
{
    protected $table = 'grid_inline_multiple_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['choices' => 'array'];
}
