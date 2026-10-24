<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin as AdminManager;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonSessionFlashTest extends TestCase
{
    private array $packageState = [];
    private array $loadedFlashes = [];
    private array $flashPayloads = [];

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Admin' => Admin::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.connection', 'testing');
        $app['config']->set('session.table', 'sessions');
        $app['config']->set('session.lottery', [0, 100]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.limiter', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
        $router->middleware(['web', 'admin'])->get('admin', function () {
            return response($this->renderNotifications())->header('X-Admin-Username', Admin::user()->username);
        });
        $router->middleware('web')->group(function ($router) {
            $router->post('flash/toastr', function (Request $request) {
                admin_toastr($request->input('message', ''), $request->input('type', 'success'), $request->input('options', []));

                return redirect('/flash/render');
            });
            $router->post('flash/alerts', function (Request $request) {
                foreach (['success', 'error', 'info', 'warning'] as $type) {
                    $helper = 'admin_'.$type;
                    $helper($type.' '.$request->input('title', ''), $request->input('message', ''));
                }

                return redirect('/flash/render');
            });
            $router->post('flash/payload', function () {
                foreach ($this->flashPayloads as $key => $value) {
                    session()->flash($key, $value);
                }

                return redirect('/flash/render');
            });
            $router->get('flash/render', fn () => $this->renderNotifications());
        });
    }

    protected function setUp(): void
    {
        foreach ([
            AdminManager::class => ['script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs', 'baseCss', 'minifyIgnores'],
            Form::class => ['collectedAssets', 'snakeAttributes'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }

        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
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

    public static function serializers(): iterable
    {
        yield 'PHP serialization' => ['php'];
        yield 'JSON serialization' => ['json'];
    }

    #[DataProvider('serializers')]
    public function test_login_redirect_renders_toast_after_database_cookie_round_trip(string $serialization): void
    {
        $this->useSerialization($serialization);
        $login = $this->post('/admin/auth/login', ['username' => 'admin', 'password' => 'admin'])
            ->assertRedirect('/admin');
        $this->assertInstanceOf(MessageBag::class, session()->get('toastr'));
        $this->assertFlashRepresentation($serialization, $this->storedSession($login)['toastr']);

        $this->nextRequest($login);
        $page = $this->get('/admin')->assertOk()->assertHeader('X-Admin-Username', 'admin')
            ->assertSee("toastr.success('".trans('admin.login_successful')."', null, []);", false)
            ->assertSessionMissing('toastr');
        $this->assertFlashRepresentation($serialization, $this->loadedFlashes['toastr']);

        $this->nextRequest($page);
        $this->get('/admin')->assertOk()->assertHeader('X-Admin-Username', 'admin')
            ->assertDontSee('toastr.success', false);
    }

    #[DataProvider('serializers')]
    public function test_alert_helpers_render_and_expire_after_database_cookie_round_trip(string $serialization): void
    {
        $this->useSerialization($serialization);
        $title = '<b>Saved</b> & "quoted"';
        $message = '<strong>Details & more</strong>';
        $flash = $this->post('/flash/alerts', compact('title', 'message'))->assertRedirect('/flash/render');
        $stored = $this->storedSession($flash);
        foreach (['success', 'error', 'info', 'warning'] as $type) {
            $this->assertInstanceOf(MessageBag::class, session()->get($type));
            $this->assertFlashRepresentation($serialization, $stored[$type]);
        }

        $this->nextRequest($flash);
        $page = $this->get('/flash/render')->assertOk();
        foreach (['success', 'error', 'info', 'warning'] as $type) {
            $page->assertSee(e($type.' '.$title), false)->assertSee($message, false)
                ->assertSee('alert-'.($type === 'error' ? 'danger' : $type), false);
            $this->assertFlashRepresentation($serialization, $this->loadedFlashes[$type]);
        }

        $this->nextRequest($page);
        $this->get('/flash/render')->assertOk()->assertDontSee('alert-dismissable', false);
    }

    #[DataProvider('serializers')]
    public function test_toast_types_options_and_preescaped_messages_keep_their_rendering(string $serialization): void
    {
        $this->useSerialization($serialization);
        $message = "Already\\'quoted &amp; <em>HTML</em>\\nnext";
        $options = [
            'closeButton' => true, 'newestOnTop' => false, 'timeOut' => 1250,
            'extendedTimeOut' => 0, 'closeDuration' => 0.75, 'positionClass' => 'toast-top-center',
        ];

        foreach (['success', 'error', 'info', 'warning'] as $type) {
            $flash = $this->post('/flash/toastr', compact('message', 'type', 'options'))
                ->assertRedirect('/flash/render');
            $this->assertSame($options, session()->get('toastr')->get('options', []));
            $this->nextRequest($flash);
            $page = $this->get('/flash/render')->assertOk()
                ->assertSee("toastr.{$type}('{$message}', null, ".json_encode($options).");", false);
            $this->nextRequest($page);
        }
    }

    #[DataProvider('serializers')]
    public function test_array_payloads_preserve_nested_options_and_duplicate_values(string $serialization): void
    {
        $this->useSerialization($serialization);
        $options = [
            'closeButton' => true, 'progressBar' => true,
            'timeOut' => 0, 'extendedTimeOut' => 0,
            'iconClasses' => ['success' => 'custom-success', 'info' => 'custom-info'],
        ];
        $this->flashPayloads = ['toastr' => [
            'type' => ['info'], 'message' => ['0'], 'options' => $options,
        ]];
        $flash = $this->post('/flash/payload')->assertRedirect('/flash/render');
        $this->assertSame($this->flashPayloads['toastr'], $this->storedSession($flash)['toastr']);
        $this->nextRequest($flash);
        $this->get('/flash/render')->assertOk()
            ->assertSee("toastr.info('0', null, ".json_encode($options).");", false);
    }

    #[DataProvider('serializers')]
    public function test_absent_and_empty_toasts_keep_existing_defaults(string $serialization): void
    {
        $this->useSerialization($serialization);
        $absent = $this->get('/flash/render')->assertOk();
        $this->assertSame('', trim($absent->getContent()));
        $this->nextRequest($absent);

        // Empty helper arguments still produce a MessageBag during the writing request.
        $empty = $this->post('/flash/toastr')->assertRedirect('/flash/render');
        $this->assertInstanceOf(MessageBag::class, session()->get('toastr'));
        $this->nextRequest($empty);
        $page = $this->get('/flash/render')->assertOk()->assertSee("toastr.success('', null, []);", false);
        $this->nextRequest($page);

        // Also preserve defaults for an older payload with no message/type/options keys.
        $this->flashPayloads = ['toastr' => new MessageBag];
        $emptyBag = $this->post('/flash/payload')->assertRedirect('/flash/render');
        $this->nextRequest($emptyBag);
        $this->get('/flash/render')->assertOk()->assertSee("toastr.success('', null, []);", false);
    }

    #[DataProvider('serializers')]
    public function test_named_validation_error_bag_and_explicit_error_precedence_survive_serialization(string $serialization): void
    {
        $this->useSerialization($serialization);
        foreach ([false, true] as $explicitError) {
            $this->flashPayloads = ['errors' => (new ViewErrorBag)
                ->put('error', new MessageBag(['name' => '<strong>Validation detail</strong>']))
                ->put('default', new MessageBag(['name' => 'Default bag detail']))];
            if ($explicitError) {
                $this->flashPayloads['error'] = new MessageBag(['title' => 'Explicit error', 'message' => 'Explicit detail']);
            }
            $flash = $this->post('/flash/payload')->assertRedirect('/flash/render');
            $this->nextRequest($flash);
            $page = $this->get('/flash/render')->assertOk()->assertDontSee('Default bag detail');
            $this->assertInstanceOf(ViewErrorBag::class, $this->loadedFlashes['errors']);
            $this->assertTrue($this->loadedFlashes['errors']->hasBag('error'));
            if ($explicitError) {
                $page->assertSee('Explicit detail')->assertDontSee('Validation detail');
            } else {
                $page->assertSee('<strong>Validation detail</strong>', false);
            }
            $this->nextRequest($page);
        }
    }

    public function test_php_sessions_preserve_custom_get_providers_and_message_bag_formatting(): void
    {
        $this->useSerialization('php');
        $this->flashPayloads = [
            'toastr' => new JsonSessionFlashGetProvider([
                'type' => ['warning'], 'message' => ['Custom toast'], 'options' => ['timeOut' => 72],
            ]),
            'success' => new JsonSessionFlashGetProvider([
                'title' => ['<em>Custom title</em>'], 'message' => ['<strong>Custom detail</strong>'],
            ]),
            'warning' => (new MessageBag(['title' => 'Title', 'message' => 'Detail']))->setFormat('Formatted :message'),
        ];
        $flash = $this->post('/flash/payload')->assertRedirect('/flash/render');
        $this->nextRequest($flash);
        $this->get('/flash/render')->assertOk()
            ->assertSee("toastr.warning('Provided Custom toast', null, {\"timeOut\":72});", false)
            ->assertSee('Provided &lt;em&gt;Custom title&lt;/em&gt;', false)
            ->assertSee('Provided <strong>Custom detail</strong>', false)
            ->assertSee('Formatted Title')->assertSee('Formatted Detail');
        $this->assertInstanceOf(JsonSessionFlashGetProvider::class, $this->loadedFlashes['toastr']);
        $this->assertSame([['type', null], ['message', null], ['options', []]], $this->loadedFlashes['toastr']->calls);
        $this->assertSame([['title', null], ['message', null]], $this->loadedFlashes['success']->calls);
    }

    private function renderNotifications(): string
    {
        $this->loadedFlashes = session()->only(['toastr', 'success', 'error', 'info', 'warning', 'errors']);

        return view('admin::partials.alerts')->render().view('admin::partials.toastr')->render();
    }

    private function useSerialization(string $serialization): void
    {
        config(['session.serialization' => $serialization]);
        $this->resetRequestState();
        $this->assertInstanceOf(DatabaseSessionHandler::class, session()->getHandler());
    }

    private function nextRequest(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);
        $store = session()->driver();
        $guard = Admin::guard();
        $this->resetRequestState();
        $this->assertNotSame($store, session()->driver());
        $this->assertNotSame($guard, Admin::guard());
        $this->assertSame([], session()->all());
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    private function resetRequestState(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
    }

    private function storedSession(TestResponse $response): array
    {
        $id = $response->getCookie(config('session.cookie'))->getValue();
        $payload = DB::table('sessions')->where('id', $id)->value('payload');
        $this->assertIsString($payload);
        $serialized = base64_decode($payload, true);

        return config('session.serialization') === 'json'
            ? json_decode($serialized, true, 512, JSON_THROW_ON_ERROR)
            : unserialize($serialized);
    }

    private function assertFlashRepresentation(string $serialization, $flash): void
    {
        if ($serialization === 'json') {
            $this->assertIsArray($flash);
        } else {
            $this->assertInstanceOf(MessageBag::class, $flash);
        }
    }
}

class JsonSessionFlashGetProvider
{
    public array $calls = [];

    public function __construct(private array $values)
    {
    }

    public function get($key, $format = null): array
    {
        $this->calls[] = [$key, $format];
        $values = $this->values[$key] ?? [];

        return in_array($key, ['title', 'message'], true)
            ? array_map(fn ($message) => 'Provided '.$message, $values)
            : $values;
    }
}
