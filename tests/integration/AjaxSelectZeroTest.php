<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class AjaxSelectZeroRecord extends Model
{
    protected $table = 'ajax_select_zero_records';
    protected $guarded = [];
    public $timestamps = false;
}

class AjaxSelectZeroMultiRecord extends AjaxSelectZeroRecord
{
    protected $casts = ['choice' => 'array'];
}

class AjaxSelectZeroTest extends TestCase
{
    private const IDS = ['0', '7', '00', '日本語"<&', 'page-two'];
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->get('/ajax-zero-options/{mapping}/{zero}', function ($mapping, $zero) {
            [$id, $text] = $this->mapping($mapping);
            $rows = [];
            foreach ([$zero === 'numeric' ? 0 : '0', 7, '00', self::IDS[3], 'page-two'] as $index => $value) {
                $rows[] = [$id => $value, $text => '<b class="ajax-zero-label">Choice '.$index.'</b> &amp;',
                    'extra' => ['index' => $index]];
            }
            $page = (int) request('page', 1);

            return ['data' => $page === 1 ? array_slice($rows, 0, 3) : array_slice($rows, 3),
                'next_page_url' => $page === 1 ? '/ajax-zero-options/'.$mapping.'/'.$zero.'?page=2' : null];
        });
        $router->middleware('web')->get('/ajax-zero/{surface}/{kind}/{mapping}/{zero}', function ($surface, $kind, $mapping, $zero) {
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                if ($surface === 'form') {
                    $html = $this->form($kind, $mapping, $zero)->render();
                    $rows = $queries = [];
                } else {
                    $grid = new Grid(new AjaxSelectZeroRecord());
                    $grid->model()->orderBy('id');
                    $filter = $grid->getFilter()->disableIdFilter();
                    $condition = $kind === 'multipleSelect' ? $filter->in('choice') : $filter->equal('choice');
                    $field = $condition->$kind(['stored' => 'Stored'])->default($kind === 'multipleSelect' ? ['stored'] : 'stored');
                    $this->configure($field, $mapping, $zero);
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    try {
                        $rows = array_column($filter->execute(), 'choice');
                        $queries = DB::getQueryLog();
                    } finally {
                        DB::disableQueryLog();
                    }
                    $html = (string) $filter->render();
                }

                return ['html' => $html, 'scriptHtml' => Admin::script()->render(), 'rows' => $rows, 'queries' => $queries];
            } finally {
                Admin::$script = $previous;
            }
        });
        $router->middleware('web')->post('/ajax-zero-store/{kind}/{mapping}/{zero}', function ($kind, $mapping, $zero) {
            return $this->form($kind, $mapping, $zero)->store();
        });
    }

    protected function setUp(): void
    {
        foreach ([Admin::class => ['script'], Form::class => ['collectedAssets', 'snakeAttributes'], Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'model']] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        parent::setUp();
        $this->withoutExceptionHandling();
        $this->app['view']->share('errors', new ViewErrorBag());
        Schema::create('ajax_select_zero_records', function ($table) {
            $table->increments('id');
            $table->text('choice')->nullable();
        });
        foreach (array_merge(['stored'], self::IDS) as $value) {
            AjaxSelectZeroRecord::create(['choice' => $value]);
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

    private function mapping(string $mapping): array
    {
        return $mapping === 'custom' ? ['code', 'label'] : ['id', 'text'];
    }

    private function configure($field, string $mapping, string $zero): void
    {
        $field->config('width', '100%')->config('closeOnSelect', false);
        $field->ajax('/ajax-zero-options/'.$mapping.'/'.$zero, ...$this->mapping($mapping));
    }

    private function form(string $kind, string $mapping, string $zero): Form
    {
        $form = new Form($kind === 'multipleSelect' ? new AjaxSelectZeroMultiRecord() : new AjaxSelectZeroRecord());
        $form->setAction('/ajax-zero-store/'.$kind.'/'.$mapping.'/'.$zero);
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $field = $form->$kind('choice')->options(['stored' => 'Stored'])->default($kind === 'multipleSelect' ? ['stored'] : 'stored');
        $this->configure($field, $mapping, $zero);

        return $form;
    }

    public static function widgets(): iterable
    {
        foreach (['form', 'grid'] as $surface) {
            foreach (['select', 'multipleSelect'] as $kind) {
                foreach (['default', 'custom'] as $mapping) {
                    foreach (['numeric', 'string'] as $zero) {
                        yield "$surface $kind $mapping $zero" => [$surface, $kind, $mapping, $zero];
                    }
                }
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_ajax_zero_native_selection_submission_and_http_replay(string $surface, string $kind, string $mapping, string $zero): void
    {
        $path = '/ajax-zero/'.$surface.'/'.$kind.'/'.$mapping.'/'.$zero;
        $fixture = $this->get($path)->assertOk()->json();
        $url = '/ajax-zero-options/'.$mapping.'/'.$zero;
        $responses = [];
        foreach ([1, 2] as $page) {
            $responses[] = $this->get($url.'?q=needle&page='.$page)->assertOk()->json();
        }
        $process = new Process(['node', __DIR__.'/javascript/ajax-select-zero.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode($fixture + [
            'url' => $url, 'responses' => $responses, 'ids' => self::IDS,
            'multiple' => $kind === 'multipleSelect', 'mapping' => $this->mapping($mapping),
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($results as $snapshots) {
            foreach ($snapshots as $snapshot) {
                $selected = $snapshot['selected'];
                if ($surface === 'form') {
                    parse_str($snapshot['query'], $values);
                    $this->post('/ajax-zero-store/'.$kind.'/'.$mapping.'/'.$zero, $values,
                        ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                        ->assertOk()->assertJson(['status' => true]);
                    $model = $kind === 'multipleSelect' ? new AjaxSelectZeroMultiRecord() : new AjaxSelectZeroRecord();
                    $this->assertSame($kind === 'multipleSelect' ? $selected : ($selected[0] ?? null), $model->latest('id')->firstOrFail()->choice);
                } else {
                    $replay = $this->get($path.'?'.$snapshot['query'])->assertOk()->json();
                    $this->assertSame($selected ?: array_merge(['stored'], self::IDS), $replay['rows']);
                    $this->assertCount(2, $replay['queries']);
                    foreach ($replay['queries'] as $query) {
                        $this->assertSame($selected, $query['bindings']);
                        if ($selected !== []) {
                            $this->assertStringContainsString($kind === 'multipleSelect' ? '"choice" in (' : '"choice" = ?', $query['query']);
                        }
                    }
                }
            }
        }
    }
}
