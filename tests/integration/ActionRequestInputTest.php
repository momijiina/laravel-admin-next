<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Controllers\HandleController;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class ActionRequestInputTest extends TestCase
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

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['db']->connection()->getSchemaBuilder()->create('action_records', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->softDeletes();
        });
        foreach ([1, 2, 3] as $id) {
            ActionRecord::create(['id' => $id, 'name' => 'record '.$id]);
        }
        SoftActionRecord::findOrFail(3)->delete();
    }

    public function test_row_resolution_matches_legacy_request_values_and_failures(): void
    {
        $this->assertLegacyParity(new class extends RowAction {});
    }

    public function test_batch_resolution_matches_legacy_request_values_and_failures(): void
    {
        $this->assertLegacyParity(new class extends BatchAction {});
    }

    private function assertLegacyParity($action): void
    {
        foreach (['_key', '_model'] as $key) {
            foreach ($this->requests($key) as $index => $request) {
                $expected = $this->outcome(function () use ($request, $action) {
                    return $this->legacyRetrieve($request, $action instanceof BatchAction);
                }, true);
                $actual = $this->outcome(function () use ($request, $action) {
                    return $action->retrieveModel($request);
                });
                $this->assertSame($expected, $actual, $key.' request '.$index);
            }
        }
    }

    public function test_real_models_comma_lists_and_soft_deleted_rows_are_preserved(): void
    {
        $row = new class extends RowAction {};
        $batch = new class extends BatchAction {};
        foreach ([ActionRecord::class, SoftActionRecord::class] as $model) {
            $request = new Request(['_key' => 3, '_model' => $this->encoded($model)]);
            $resolved = $this->strict(function () use ($row, $request) { return $row->retrieveModel($request); });
            $this->assertSame($model, get_class($resolved));
            $this->assertSame(3, $resolved->getKey());
            foreach (['1,3', [1, 3]] as $keys) {
                $request->query->set('_key', $keys);
                $resolved = $this->strict(function () use ($batch, $request) { return $batch->retrieveModel($request); });
                $this->assertSame([1, 3], $resolved->modelKeys());
                $this->assertSame([$model, $model], $resolved->map(function ($record) { return get_class($record); })->all());
            }
        }
    }

    public function test_missing_ids_and_invalid_models_do_not_fall_back_to_body_values(): void
    {
        foreach ([new class extends RowAction {}, new class extends BatchAction {}] as $action) {
            foreach ([999, '1,999'] as $missing) {
                $request = new Request(['_key' => $missing, '_model' => $this->encoded(ActionRecord::class)],
                    ['_key' => 1, '_model' => $this->encoded(ActionRecord::class)]);
                $outcome = $this->outcome(function () use ($action, $request) { return $action->retrieveModel($request); });
                $this->assertSame('exception', $outcome[0]);
                $this->assertSame(\Illuminate\Database\Eloquent\ModelNotFoundException::class, $outcome[1]);
            }
            $request = new Request(['_key' => 1, '_model' => 'MissingActionModel'],
                ['_model' => $this->encoded(ActionRecord::class)]);
            $outcome = $this->outcome(function () use ($action, $request) { return $action->retrieveModel($request); });
            $this->assertSame('exception', $outcome[0]);
            foreach ([null, '', 0, '0', false, []] as $falsey) {
                $request->attributes->set('_key', $falsey);
                $this->assertFalse($this->strict(function () use ($action, $request) { return $action->retrieveModel($request); }));
            }
        }
    }

    public function test_denied_authorization_never_reaches_row_or_batch_handler(): void
    {
        foreach ([new DeniedRowAction(), new DeniedBatchAction()] as $action) {
            // Resolve only the fixture instance here: production dispatch and
            // Authorizable still execute, without unrelated _action getter notices.
            $controller = new class($action) extends HandleController {
                private $action;
                public function __construct($action) { $this->action = $action; }
                protected function resolveActionInstance(Request $request) { return $this->action; }
            };
            $request = new Request(['_key' => 1, '_model' => $this->encoded(ActionRecord::class)],
                ['_key' => 2, '_model' => $this->encoded(SoftActionRecord::class)]);
            $response = $this->strict(function () use ($controller, $request) { return $controller->handleAction($request); });
            $this->assertFalse($action->handled);
            $this->assertSame([ActionRecord::class, 1], $action->authorizedModel);
            $this->assertSame($action->failedAuthorization()->getData(true), $response->getData(true));
            if ($action instanceof RowAction) {
                $this->assertNull($action->getRow());
            }
        }
    }

    private function requests(string $key): iterable
    {
        $valid = $key === '_key' ? 1 : $this->encoded(ActionRecord::class);
        $other = $key === '_key' ? '_model' : '_key';
        $fixed = $other === '_model' ? $this->encoded(ActionRecord::class) : 1;
        $values = [[], [$key => null], [$key => ''], [$key => 0], [$key => '0'],
            [$key => false], [$key => true], [$key => $valid], [$key => []],
            [$key => [1, 2]], [$key => ['nested' => [1]]], [$key => 'missing.class']];
        foreach ($values as $attributes) {
            foreach ($values as $query) {
                foreach ($values as $body) {
                    $request = new Request($query, $body + [$other => $fixed], $attributes);
                    $request->setMethod('POST');
                    yield $request;
                }
            }
        }
        foreach ([[$key => $valid], []] as $query) {
            foreach ([[$key => $valid], []] as $body) {
                $request = new Request($query, $body + [$other => $fixed]);
                $request->attributes->set($key, $request);
                yield $request;
            }
        }
        // Dotted/nested impostor keys must not supply the literal _key/_model.
        yield new Request([$key.'.value' => $valid, 'nested' => [$key => $valid]], [$other => $fixed]);
        yield Request::create('/admin/actions', 'GET', [$key => $valid, $other => $fixed]);
        $server = ['CONTENT_TYPE' => 'application/json'];
        $json = json_encode([$key => $valid, $other => $fixed]);
        yield Request::create('/admin/actions', 'POST', [], [], [], $server, $json);
        yield Request::createFromBase(SymfonyRequest::create('/admin/actions', 'POST', [], [], [], $server, $json));
        $request = Request::create('/admin/actions', 'POST', [], [], [], $server, $json);
        $request->request->replace([$key => $valid, $other => $fixed]);
        $request->setJson(new \Symfony\Component\HttpFoundation\InputBag([$key => 'different']));
        yield $request;
    }

    private function encoded(string $model): string
    {
        return str_replace('\\', '_', $model);
    }

    private function legacyRetrieve(Request $request, bool $batch)
    {
        if (!$key = $request->get('_key')) {
            return false;
        }
        $modelClass = str_replace('_', '\\', $request->get('_model'));
        if ($batch && is_string($key)) {
            $key = explode(',', $key);
        }
        if (in_array(SoftDeletes::class, class_uses_deep($modelClass))) {
            return $modelClass::withTrashed()->findOrFail($key);
        }
        return $modelClass::findOrFail($key);
    }

    private function outcome(callable $callback, bool $legacy = false): array
    {
        try {
            $result = $this->strict($callback, $legacy);
            if ($result instanceof Collection) {
                return ['collection', $result->map(function ($model) { return [get_class($model), $model->getAttributes()]; })->all()];
            }
            if ($result instanceof Model) {
                return ['model', get_class($result), $result->getAttributes()];
            }
            return ['value', $result];
        } catch (\Throwable $exception) {
            return ['exception', get_class($exception), $exception->getMessage()];
        }
    }

    private function strict(callable $callback, bool $legacy = false)
    {
        set_error_handler(function ($severity, $message, $file, $line) use ($legacy) {
            // Only the intentional old-getter oracle may suppress this warning.
            if ($legacy && $severity === E_USER_DEPRECATED && str_contains($message, 'Request::get() is deprecated')) {
                return true;
            }
            // Invalid shapes retain their failures, including native diagnostics.
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}

class ActionRecord extends Model
{
    protected $table = 'action_records';
    public $timestamps = false;
    protected $guarded = [];
}

class SoftActionRecord extends ActionRecord
{
    use SoftDeletes;
}

trait DeniedActionHandler
{
    public $handled = false;
    public $authorizedModel;

    public function authorize($user, $model)
    {
        if ($model instanceof Collection) {
            $model = $model->first();
        }
        $this->authorizedModel = [get_class($model), $model->getKey()];
        return false;
    }

    public function handle($model, Request $request)
    {
        $this->handled = true;
    }
}

class DeniedRowAction extends RowAction
{
    use DeniedActionHandler;
}

class DeniedBatchAction extends BatchAction
{
    use DeniedActionHandler;
}
