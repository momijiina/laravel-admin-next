<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column;
use Encore\Admin\Grid\Exporters\CsvExporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ObjectDisplayRecord extends Model
{
    protected $table = 'object_display_records';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['payload' => 'object'];
}

class ObjectDisplayFixture extends TestCase
{
    public const TEXT = "日本語 Café & <em>quoted \"text\"</em>\nnext";

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('o', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('object_display_records', function ($table) {
            $table->increments('id');
            $table->text('payload');
            $table->text('text');
        });
        for ($id = 1; $id <= 5; $id++) {
            ObjectDisplayRecord::create(['id' => $id, 'text' => self::TEXT, 'payload' => (object) [
                'label' => self::TEXT, 'nested' => (object) ['zero' => 0, 'null' => null],
                'list' => [(object) ['label' => 'nested & <b>text</b>']],
            ]]);
        }
    }

    public function makeGrid($scenario = 'display')
    {
        $request = Request::create('/admin/objects', 'GET', $scenario === 'page' ? ['page' => 2, 'per_page' => 2] : []);
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        $grid = new Grid(new ObjectDisplayRecord());
        $grid->column('id', 'ID');
        $column = $grid->column('payload', 'Payload');
        $grid->column('text', 'Text');
        $grid->model()->usePaginate(false);
        if ($scenario === 'list') {
            ObjectDisplayRecord::query()->update(['payload' => json_encode([(object) ['label' => self::TEXT]])]);
        }
        if (!in_array($scenario, ['none', 'export-only'])) {
            $column->display(function ($value) use ($scenario) {
                if (str_starts_with($scenario, 'scalar-')) {
                    return ['null' => null, 'false' => false, 'true' => true, 'zero' => 0, 'float' => 1.25, 'empty' => ''][substr($scenario, 7)];
                }
                if ($scenario === 'identity') {
                    return $value;
                }
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            });
        }
        if ($scenario === 'defined') {
            Column::define('payload', function ($value) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            });
        }
        if ($scenario === 'filtered') {
            $grid->filter(function ($filter) { $filter->equal('id'); });
            $request->query->set('id', 3);
        }
        return $grid;
    }

    public function exportFixture($scenario): void
    {
        $this->setUp();
        $grid = $this->makeGrid($scenario);
        $exporter = new CsvExporter($grid);
        if ($scenario === 'page') {
            $grid->model()->setPerPage(2);
            $exporter->withScope('page:2');
        }
        if ($scenario === 'selected') {
            $exporter->withScope('selected:2,4');
        }
        if ($scenario === 'custom' || $scenario === 'export-only') {
            $exporter->only(['id', 'payload'])->title('payload', function () { return 'Custom payload'; });
            $exporter->column('payload', function ($value, $original) use ($scenario) {
                if ($scenario === 'custom' && json_decode($value) != $original) {
                    throw new \LogicException('Display/export callback order changed');
                }
                return $original->label;
            });
        }
        $exporter->filename('objects')->export();
    }
}
