<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Controllers\HandleController;
use Encore\Admin\Widgets\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class HandleControllerRequestTest extends TestCase
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

    public function test_literal_getter_matches_legacy_for_all_six_keys_and_defaults(): void
    {
        $controller = new HandleController();
        $getter = new \ReflectionMethod(HandleController::class, 'requestValue');
        foreach (['_form_', '_action', 'selectable', 'args', 'renderable', 'key', 'literal.key'] as $key) {
            $default = $key === 'args' ? [] : null;
            foreach ($this->requests($key) as $request) {
                $expected = $this->legacy(fn () => $request->get($key, $default));
                $actual = $this->strict(fn () => $getter->invoke($controller, $request, $key, $default));
                $this->assertSame($expected, $actual, $key);
            }
        }
    }

    public function test_class_resolution_preserves_guards_precedence_and_rejections(): void
    {
        $controller = new HandleController();
        foreach (['_form_' => 'resolveForm', '_action' => 'resolveActionInstance'] as $key => $method) {
            $class = $key === '_form_' ? HandleFormFixture::class : HandleActionFixture::class;
            $encoded = $key === '_action' ? str_replace('\\', '_', $class) : $class;
            $reflect = new \ReflectionMethod(HandleController::class, $method);
            foreach ($this->requests($key, $encoded) as $request) {
                $expected = $this->legacy(fn () => $this->outcome(function () use ($request, $key) {
                    if (!$request->has($key)) {
                        throw new \Exception($key === '_form_' ? 'Invalid form request.' : 'Invalid action request.');
                    }
                    $class = $request->get($key);
                    if ($key === '_action') {
                        $class = str_replace('_', '\\', $class);
                    }
                    if (!class_exists($class)) {
                        throw new \Exception("Form [{$class}] does not exist.");
                    }
                    $instance = app($class);
                    if (!method_exists($instance, 'handle')) {
                        $type = $key === '_form_' ? 'Form' : 'Action';
                        throw new \Exception("{$type} method {$class}::handle() does not exist.");
                    }
                    return get_class($instance);
                }));
                $actual = $this->strict(fn () => $this->outcome(fn () => get_class($reflect->invoke($controller, $request))));
                $this->assertSame($expected, $actual, $key);
            }
            // has() deliberately ignores attributes, unlike the value getter.
            $request = new Request([], [], [$key => $encoded]);
            $this->assertFalse($request->has($key));
            $this->assertSame(['exception', \Exception::class, $key === '_form_' ? 'Invalid form request.' : 'Invalid action request.'],
                $this->strict(fn () => $this->outcome(fn () => $reflect->invoke($controller, $request))));
        }
    }

    public function test_selectable_and_renderable_dispatch_match_legacy_reads(): void
    {
        $controller = new HandleController();
        foreach (['selectable' => 'args', 'renderable' => 'key'] as $selector => $argument) {
            $class = str_replace('\\', '_', HandleRenderFixture::class);
            $method = $selector === 'selectable' ? 'handleSelectable' : 'handleRenderable';
            foreach ([$selector, $argument] as $key) {
                foreach ($this->requests($key, $key === $selector ? $class : ['second' => 'b', 'first' => 'a']) as $request) {
                    if ($key === $argument) {
                        $request->attributes->set($selector, $class);
                    }
                    $expected = $this->legacy(fn () => $this->outcome(function () use ($request, $selector, $argument) {
                        $class = $request->get($selector);
                        $arg = $request->get($argument, $argument === 'args' ? [] : null);
                        $class = str_replace('_', '\\', $class);
                        if (class_exists($class)) {
                            return $selector === 'selectable'
                                ? (new $class(...array_values($arg)))->render()
                                : (new $class())->render($arg);
                        }
                        return $class;
                    }));
                    $actual = $this->strict(fn () => $this->outcome(fn () => $controller->$method($request)));
                    $this->assertSame($expected, $actual, $key);
                }
            }
        }
    }

    public function test_form_dispatch_validates_then_sanitizes_and_handles_injected_instance(): void
    {
        $controller = new HandleController();
        $fixture = new HandleFormFixture();
        $this->app->instance(HandleFormFixture::class, $fixture);
        foreach (['', 'Ada'] as $name) {
            $request = Request::create('/admin/form', 'POST', ['_form_' => HandleFormFixture::class, '_token' => 'token', 'name' => $name]);
            $this->app->instance('request', $request);
            $result = $this->strict(fn () => $controller->handleForm($request));
            if ($name === '') {
                $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $result);
                $this->assertTrue($result->getSession()->get('errors')->has('name'));
                $this->assertTrue($request->request->has('_form_'));
                $this->assertNull($fixture->handled);
            } else {
                $this->assertSame(['name' => 'Ada'], $result);
                $this->assertSame($request, $fixture->handled);
                $this->assertFalse($request->request->has('_token'));
            }
        }
    }

    public function test_action_dispatch_preserves_authorization_validation_model_and_exception_order(): void
    {
        $controller = new HandleController();
        foreach ([false, true] as $authorized) {
            foreach ([false, true] as $throws) {
                $fixture = new HandleRowFixture();
                $fixture->allowed = $authorized;
                $fixture->throws = $throws;
                $this->app->instance(HandleRowFixture::class, $fixture);
                $request = Request::create('/admin/action', 'POST', ['_action' => str_replace('\\', '_', HandleRowFixture::class)]);
                $response = $this->strict(fn () => $controller->handleAction($request));
                $this->assertSame($authorized ? ['retrieve', 'authorize', 'validate', 'handle'] : ['retrieve', 'authorize', 'denied'], $fixture->events);
                if (!$authorized) {
                    $this->assertSame('denied', $response);
                    $this->assertNull($fixture->getRow());
                } else {
                    $this->assertSame($fixture->model, $fixture->getRow());
                    $this->assertSame([$fixture->model, $request], $fixture->arguments);
                    $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
                    $this->assertSame(!$throws, $response->getData(true)['status']);
                }
            }
        }
        $fixture = new HandleActionFixture();
        $this->app->instance(HandleActionFixture::class, $fixture);
        $request = Request::create('/admin/action', 'POST', ['_action' => str_replace('\\', '_', HandleActionFixture::class)]);
        $this->assertNull($this->strict(fn () => $controller->handleAction($request)));
        $this->assertSame([$request], $fixture->arguments);
    }

    public function test_action_validation_failure_stops_handle_and_errors_keep_their_type(): void
    {
        $controller = new HandleController();
        $fixture = new HandleRowFixture();
        $fixture->allowed = true;
        $this->app->instance(HandleRowFixture::class, $fixture);
        $request = Request::create('/admin/action', 'POST', ['_action' => str_replace('\\', '_', HandleRowFixture::class)]);
        $fixture->validationFailure = true;
        $response = $this->strict(fn () => $controller->handleAction($request));
        $this->assertFalse($response->getData(true)['status']);
        $this->assertStringContainsString('Required fixture value', $response->getContent());
        $this->assertSame(['retrieve', 'authorize', 'validate'], $fixture->events);
        $this->assertNull($fixture->arguments);

        $fixture->validationFailure = false;
        $fixture->throws = 'error';
        $outcome = $this->strict(fn () => $this->outcome(fn () => $controller->handleAction($request)));
        $this->assertSame(['exception', \TypeError::class, 'Fixture type error'], $outcome);

        $resolver = new \ReflectionMethod(HandleController::class, 'resolveActionArgs');
        foreach ([null, false, 0, [], new \Illuminate\Support\Collection()] as $model) {
            $this->assertSame(empty($model) ? [$request] : [$model, $request], $resolver->invoke($controller, $request, $model));
        }
    }

    private function requests(string $key, $valid = 'value'): iterable
    {
        $values = [[], [$key => null], [$key => ''], [$key => 0], [$key => '0'], [$key => false],
            [$key => true], [$key => $valid], [$key => []], [$key => ['nested' => 'value']], [$key => \stdClass::class]];
        foreach ($values as $attributes) {
            foreach ($values as $query) {
                foreach ($values as $body) {
                    $request = new Request($query, $body, $attributes);
                    $request->setMethod('POST');
                    yield $request;
                }
            }
        }
        foreach ([new Request([$key => $valid], [$key => 'body']), new Request([], [$key => $valid]), new Request()] as $request) {
            $request->attributes->set($key, $request);
            yield $request;
        }
        yield new Request(['literal' => ['key' => $valid]]);
        yield Request::create('/admin/handle', 'GET', [$key => $valid]);
        $server = ['CONTENT_TYPE' => 'application/json'];
        $json = json_encode([$key => $valid]);
        yield Request::create('/admin/handle', 'POST', [], [], [], $server, $json);
        yield Request::createFromBase(SymfonyRequest::create('/admin/handle', 'POST', [], [], [], $server, $json));
        $request = Request::create('/admin/handle', 'POST', [], [], [], $server, $json);
        $request->request->set($key, 'body');
        yield $request;
    }

    private function outcome(callable $callback): array
    {
        try {
            return ['return', $callback()];
        } catch (\ErrorException $exception) {
            // A deprecated production getter must fail, not match a reference error.
            throw $exception;
        } catch (\Throwable $exception) {
            return ['exception', get_class($exception), $exception->getMessage()];
        }
    }

    private function legacy(callable $callback)
    {
        return $this->diagnostics($callback, false);
    }

    private function strict(callable $callback)
    {
        return $this->diagnostics($callback, true);
    }

    private function diagnostics(callable $callback, bool $strict)
    {
        set_error_handler(function ($severity, $message, $file, $line) use ($strict) {
            if ($severity === E_USER_DEPRECATED && str_contains($message, 'Request::get() is deprecated')) {
                if ($strict) {
                    throw new \ErrorException($message, 0, $severity, $file, $line);
                }
                return true;
            }
            return false;
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}

class HandleFormFixture extends Form
{
    public $handled;

    public function form()
    {
        $this->text('name')->rules('required');
    }

    public function handle(Request $request)
    {
        $this->handled = $request;
        return $request->request->all();
    }
}

class HandleActionFixture extends Action
{
    public $arguments;

    public function handle(...$arguments)
    {
        $this->arguments = $arguments;
    }
}

class HandleRenderFixture
{
    private $arguments;

    public function __construct(...$arguments)
    {
        $this->arguments = $arguments;
    }

    public function render($key = null)
    {
        return [$this->arguments, $key];
    }
}

class HandleRowFixture extends RowAction
{
    public $allowed;
    public $throws;
    public $validationFailure = false;
    public $events = [];
    public $arguments;
    public $model;

    public function retrieveModel(Request $request)
    {
        $this->events[] = 'retrieve';
        return $this->model = new class extends Model {};
    }

    public function passesAuthorization($model = null)
    {
        $this->events[] = 'authorize';
        if ($model !== $this->model) {
            throw new \LogicException('Wrong authorization model');
        }
        return $this->allowed;
    }

    public function failedAuthorization()
    {
        $this->events[] = 'denied';
        return 'denied';
    }

    public function validate(Request $request)
    {
        $this->events[] = 'validate';
        if ($this->validationFailure) {
            throw \Illuminate\Validation\ValidationException::withMessages(['fixture' => 'Required fixture value']);
        }
        return parent::validate($request);
    }

    public function handle(...$arguments)
    {
        $this->events[] = 'handle';
        $this->arguments = $arguments;
        if ($this->throws === 'error') {
            throw new \TypeError('Fixture type error');
        }
        if ($this->throws) {
            throw new \Exception('Fixture exception');
        }
        return (new Response())->toastr()->success('Handled');
    }
}
