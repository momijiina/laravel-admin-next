<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class CsvHeadersRecord extends Model
{
    protected $table = 'csv_header_records';
    public $timestamps = false;
    protected $guarded = [];
}

class CsvHeadersFixture extends TestCase
{
    public const TEXT = "日本語 Café, \"quoted\"\nnext";
    public const TITLE = "日本語, \"title\"\nnext";

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('session.driver', 'array');
    }

    public function exportFixture(array $options, string $tracePath): void
    {
        $this->setUp();
        $this->withoutExceptionHandling();
        // Treat runtime diagnostics as failures, rather than CSV content.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        Schema::create('csv_header_records', function ($table) {
            $table->increments('id');
            $table->integer('category');
            $table->text('text');
            $table->integer('zero');
        });
        if (($options['empty'] ?? '') !== 'table') {
            for ($id = 1; $id <= (($options['large'] ?? false) ? 212 : 12); $id++) {
                CsvHeadersRecord::create(['id' => $id, 'category' => $id % 2, 'text' => self::TEXT, 'zero' => 0]);
            }
        }
        $trace = [];
        register_shutdown_function(function () use (&$trace, $tracePath) {
            file_put_contents($tracePath, json_encode($trace, JSON_THROW_ON_ERROR));
        });
        DB::listen(function ($query) use (&$trace) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'csv_header_records')) {
                $trace[] = ['query', $query->sql];
            }
        });
        $named = $options['named'] ?? true;
        $prefix = $named ? 'records_' : '';
        $scope = $options['scope'] ?? 'all';
        $query = [
            $prefix.'page' => $scope === 'page' && empty($options['empty']) ? 2 : 1,
            $prefix.'per_page' => 2,
            $prefix.'category' => ($options['empty'] ?? '') === 'filter' ? 9 : 1,
            $named ? 'records_sort' : '_sort' => ['column' => 'id', 'type' => 'desc'],
            '_export_' => ['all' => 'all', 'page' => 'page:1', 'selected' => ($options['empty'] ?? '') === 'selected' ? 'selected:999' : 'selected:2,6,7'][$scope],
        ];
        if (array_key_exists('request_columns', $options)) {
            $query['_columns_'] = $options['request_columns'];
        }
        $this->app['router']->get('/admin/csv-headers', function () use ($options, $named, &$trace) {
            $grid = new Grid(new CsvHeadersRecord());
            // Select the supported driver explicitly; the legacy null resolver is separate.
            $grid->exporter(new Grid\Exporters\CsvExporter());
            if ($named) {
                $grid->setName('records');
                $grid->model()->setSortName('records_sort');
            }
            if (!($options['no_columns'] ?? false)) {
                $grid->column('id', 'ID')->sortable();
                $grid->column('category', 'Category');
                $grid->column('text', 'Text')->display(function ($value) use (&$trace) {
                    $trace[] = ['display', $this->id];
                    return $value;
                });
                $grid->column('zero', 'Zero');
            }
            $grid->paginate(2);
            $grid->filter(function ($filter) { $filter->equal('category'); });
            $grid->hideColumns($options['hidden'] ?? []);
            $grid->export(function ($exporter) use ($grid, $options, &$trace) {
                $trace[] = ['configure'];
                if (array_key_exists('only', $options)) {
                    $exporter->only($options['only']);
                }
                if (array_key_exists('except', $options)) {
                    $exporter->except($options['except']);
                }
                $exporter->title('text', function ($title) use ($grid, $options, &$trace) {
                    $trace[] = ['title', $title, $grid->columnNames];
                    return $options['title'] ?? $title;
                });
                $exporter->column('text', function ($value, $original) use (&$trace) {
                    if ($value !== $original) {
                        throw new \LogicException('Display/export callback order changed');
                    }
                    $trace[] = ['column'];
                    return $value;
                });
            });
            return $grid->render();
        });
        $response = $this->app->make(Kernel::class)->handle(Request::create('/admin/csv-headers?'.http_build_query($query), 'GET'));
        throw new \RuntimeException('Exporter must send and exit, got HTTP '.$response->getStatusCode());
    }
}
