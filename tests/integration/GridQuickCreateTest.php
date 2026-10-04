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

class GridQuickCreateTest extends TestCase
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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('q', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/grid-quick-create/{mode}', function ($mode) {
                $previousScript = Admin::$script;
                try {
                    Admin::$script = [];
                    Admin::script('window.quickCreateBefore = true;');
                    $html = '';
                    // Two real grids share a resource and the same emitted QuickCreate
                    // handler. A pending second form must not be reset by the first.
                    for ($index = 0; $index < 2; $index++) {
                        $grid = new Grid(new GridQuickCreateItem());
                        $grid->setResource('/grid-quick-create/'.$mode);
                        $grid->column('id');
                        $grid->column('title');
                        $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();
                        $grid->quickCreate(function ($create) {
                            $create->text('title', 'Title');
                        });
                        $html .= $grid->render();
                    }
                    Admin::script('window.quickCreateAfter = true;');

                    return response()->json([
                        'html' => $html, 'scriptHtml' => Admin::script()->render(),
                    ]);
                } finally {
                    Admin::$script = $previousScript;
                }
            });
            $router->post('/grid-quick-create/{mode}', function ($mode) {
                if (request()->input('title') === 'x') {
                    if ($mode === 'rejected') {
                        return response()->json(['status' => false, 'message' => 'Save rejected']);
                    }
                    if ($mode === 'error') {
                        return response()->json(['message' => 'Temporary failure'], 500);
                    }
                }
                $form = new Form(new GridQuickCreateItem());
                $form->text('title')->rules('required|min:3');

                return $form->store();
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
            Schema::create('grid_quick_create_items', function ($table) {
                $table->increments('id');
                $table->string('title');
            });
            GridQuickCreateItem::insert([['title' => 'Existing first'], ['title' => 'Existing second']]);
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

    public static function responses(): array
    {
        $cases = [];
        foreach (['shipped', 'modern'] as $jquery) {
            foreach (['validation', 'rejected', 'error', 'success'] as $mode) {
                $cases[$jquery.' '.$mode] = [$jquery, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('responses')]
    public function test_real_quick_create_retry_and_settled_button_scope(string $jquery, string $mode): void
    {
        $url = '/grid-quick-create/'.$mode;
        $fixture = $this->get($url)->assertOk()->json();
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $failure = $this->post($url, ['title' => 'x'], $headers)->assertStatus($mode === 'error' ? 500 : 200);
        $this->assertSame(2, GridQuickCreateItem::count());
        if ($mode === 'validation' || $mode === 'success') {
            $failure->assertJsonPath('status', false);
            $this->assertArrayHasKey('title', $failure->json('validation'));
        }
        $success = $this->post($url, ['title' => 'Corrected title'], $headers)->assertOk()->assertJsonPath('status', true);
        $this->assertSame('Corrected title', GridQuickCreateItem::latest('id')->first()->title);
        // Obtain genuine HTTP bodies for the production callbacks, then replay
        // every actual serialized DOM request against the same pristine rows.
        GridQuickCreateItem::where('title', 'Corrected title')->delete();
        $fixture += [
            'jquery' => $jquery, 'mode' => $mode, 'url' => $url,
            'failure' => $failure->json(), 'success' => $success->json(),
        ];
        $process = new Process(['node', __DIR__.'/javascript/grid-quick-create.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount($mode === 'success' ? 2 : 4, $result['requests']);
        $this->assertSame(2, $result['reloads']);
        // Replay in response-completion order: the second form remains pending
        // while the first rejects twice, corrects its input, and saves.
        foreach ($result['completed'] as $index) {
            $request = $result['requests'][$index];
            $this->assertSame($url, $request['url']);
            $this->assertSame('POST', $request['type']);
            parse_str($request['data'], $payload);
            $this->assertArrayHasKey('_token', $payload);
            $this->assertSame(['title', '_token'], array_keys($payload));
            $before = GridQuickCreateItem::count();
            $valid = $payload['title'] !== 'x';
            $actual = $this->post($request['url'], $payload, $headers)
                ->assertStatus(!$valid && $mode === 'error' ? 500 : 200);
            $this->assertSame($valid ? $success->json() : $failure->json(), $actual->json());
            $this->assertSame($before + ($valid ? 1 : 0), GridQuickCreateItem::count());
        }
        $this->assertSame([
            'Existing first', 'Existing second', 'Corrected title', 'Other pending title',
        ], GridQuickCreateItem::orderBy('id')->pluck('title')->all());
    }
}

class GridQuickCreateItem extends Model
{
    protected $table = 'grid_quick_create_items';
    public $timestamps = false;
    protected $guarded = [];
}
