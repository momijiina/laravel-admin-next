<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class SelectLoadsIsolationTest extends TestCase
{
    private string $scenario = 'both';
    private array $previousScripts = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/loads/{id}/edit', function ($id) {
                $previous = Admin::$script;
                try {
                    Admin::$script = [];
                    // Consumer scripts may omit their final semicolon.
                    Admin::script("window.selectLoadsSentinel = function () {}\n");
                    $html = $this->form()->edit($id)->render();

                    return response()->json(['html' => $html, 'scriptHtml' => Admin::script()->render()]);
                } finally {
                    Admin::$script = $previous;
                }
            });
            $router->put('/loads/{id}', fn ($id) => $this->form()->update($id));
            $router->get('/countries', fn () => response()->json(request('q') === 'asia'
                ? [['id' => 'JP', 'text' => 'Japan']]
                : [['id' => 'DE', 'text' => 'Germany']]));
            $router->get('/currencies', fn () => response()->json(request('q') === 'asia'
                ? [['id' => 'JPY', 'text' => 'Yen']]
                : [['id' => 'EUR', 'text' => 'Euro']]));
            $router->get('/products', fn () => response()->json(match (request('q')) {
                'furniture' => [['code' => 'chair', 'label' => 'Chair']],
                'electronics' => [['code' => 'phone', 'label' => 'Phone']],
                default => [],
            }));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScripts = Admin::$script;
        Schema::create('select_loads_records', function ($table) {
            $table->increments('id');
            foreach (['region', 'country', 'currency', 'category', 'product', 'note'] as $field) {
                $table->string($field)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScripts;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new SelectLoadsRecord());
        $form->setAction('/loads');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $region = $form->select('region')->options(['europe' => 'Europe', 'asia' => 'Asia']);
        $form->select('country')->options(['DE' => 'Germany', 'JP' => 'Japan']);
        $form->select('currency')->options(['EUR' => 'Euro', 'JPY' => 'Yen']);
        $category = $form->select('category')->options(['furniture' => 'Furniture', 'electronics' => 'Electronics']);
        $form->select('product')->options(['chair' => 'Chair', 'phone' => 'Phone']);
        $form->text('note');

        // The groups deliberately differ in arity, mapping and clearability.
        $groups = $this->scenario === 'reverse' ? ['category', 'region'] : ['region', 'category'];
        foreach ($groups as $group) {
            if ($group === 'region' && $this->scenario !== 'category-only') {
                $this->assertSame($region, $region->loads(['country', 'currency'], ['/countries', '/currencies']));
            }
            if ($group === 'category' && $this->scenario !== 'region-only') {
                $this->assertSame($category, $category->loads(['product'], ['/products'], 'code', 'label', false));
            }
        }

        return $form;
    }

    public static function loaderScenarios(): iterable
    {
        foreach (['region-only', 'category-only', 'both', 'reverse'] as $scenario) {
            foreach ([1, 2] as $initializations) {
                yield $scenario.' / initialize '.$initializations => [$scenario, $initializations];
            }
        }
    }

    #[DataProvider('loaderScenarios')]
    public function test_loaders_keep_their_own_targets_mapping_and_saved_values(string $scenario, int $initializations): void
    {
        $this->scenario = $scenario;
        $original = [
            'region' => 'europe', 'country' => 'DE', 'currency' => 'EUR',
            'category' => 'furniture', 'product' => 'chair', 'note' => 'Keep this note',
        ];
        $record = SelectLoadsRecord::create($original);
        $fixture = $this->get('/loads/'.$record->id.'/edit')->assertOk()->json();
        $fixture['scenario'] = $scenario;
        $fixture['initializations'] = $initializations;
        $fixture['responses'] = [];
        foreach (['/countries', '/currencies', '/products'] as $endpoint) {
            foreach (['asia', 'europe', 'electronics', 'furniture'] as $query) {
                $url = $endpoint.'?q='.$query;
                $fixture['responses'][$url] = $this->get($url)->assertOk()->json();
            }
        }
        $process = new Process(['node', __DIR__.'/javascript/select-loads-isolation.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['shipped', 'modern'], array_keys($results));
        foreach ($results as $jquery => $steps) {
            $record->update($original);
            $expectedStored = $original;
            foreach ($steps as $step) {
                parse_str($step['query'], $values);
                $this->put('/loads/'.$record->id, $values, [
                    'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
                ])->assertOk()->assertJson(['status' => true]);
                // The shipped hidden empty marker submits a cleared selection.
                // Keep the existing native form and HTTP middleware behavior.
                foreach ($expectedStored as $field => $value) {
                    if (array_key_exists($field, $values)) {
                        $expectedStored[$field] = $step['expected'][$field];
                    }
                }
                $this->assertSame($expectedStored, $record->fresh()->only(array_keys($original)), $jquery.' / '.$step['name']);
            }
        }
    }
}

class SelectLoadsRecord extends Model
{
    protected $table = 'select_loads_records';
    protected $guarded = [];
    public $timestamps = false;
}
