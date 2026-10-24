<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin as AdminManager;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class LoginThrottleTest extends TestCase
{
    private array $packageState = [];
    private array $attempts = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('l', 32)));
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('auth.guards.secondary', ['driver' => 'session', 'provider' => 'admin']);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.limiter', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
        $router->middleware('web')->group(function ($router) {
            $router->post('throttle/custom-username', [LoginThrottleNameController::class, 'postLogin']);
            $router->post('throttle/custom-guard', [LoginThrottleGuardController::class, 'postLogin']);
            $router->post('throttle/custom-validation', [LoginThrottleValidationController::class, 'postLogin']);
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
        $this->freezeSecond();
        $this->app['events']->listen(Attempting::class, function (Attempting $event) {
            $this->attempts[] = [$event->guard, $event->credentials, $event->remember];
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            parent::tearDown();
        } finally {
            foreach ($this->packageState as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    public static function defaultConfigurations(): iterable
    {
        yield 'shipped configuration' => [false];
        yield 'previously published configuration' => [true];
    }

    #[DataProvider('defaultConfigurations')]
    public function test_five_failures_block_the_sixth_attempt_even_with_correct_credentials(bool $removeSettings): void
    {
        if ($removeSettings) {
            $auth = config('admin.auth');
            unset($auth['login_throttle']);
            config(['admin.auth' => $auth]);
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
            $this->assertCount($attempt, $this->attempts);
        }

        $this->login(['password' => 'admin', 'remember' => '1'])
            ->assertRedirect('/admin/auth/login')
            ->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])])
            ->assertCookieMissing(Admin::guard()->getRecallerName());
        $this->assertCount(5, $this->attempts);
        $this->assertGuest('admin');
        $this->assertEmpty(Administrator::first()->getRememberToken());
        $this->assertDatabaseCount('admin_operation_log', 0);

        $this->travel(59)->seconds();
        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 1, 'minutes' => 1])]);
        $this->assertCount(5, $this->attempts);
        $this->travel(1)->seconds();
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(6, $this->attempts);
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
    }

    public function test_custom_limits_expire_from_the_first_failure_without_extension_by_retries(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 2, 'admin.auth.login_throttle.decay_seconds' => 10]);
        $this->failedLogin();
        $this->travel(4)->seconds();
        $this->failedLogin();
        $this->travel(5)->seconds();
        $this->login(['password' => 'admin'])
            ->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 1, 'minutes' => 1])]);
        $this->assertCount(2, $this->attempts);
        $this->travel(1)->seconds();
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(3, $this->attempts);
    }

    public function test_database_cache_persists_limits_across_store_recreation_and_clears_after_success(): void
    {
        Schema::create('login_throttle_cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });
        Schema::create('login_throttle_cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
        $this->useDatabaseLoginLimiter();
        config(['admin.auth.login_throttle.max_attempts' => 2, 'admin.auth.login_throttle.decay_seconds' => 10]);
        $store = $this->app['cache']->store('throttle_database')->getStore();
        $limiter = RateLimiter::getFacadeRoot();
        $this->assertInstanceOf(DatabaseStore::class, $store);

        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertDatabaseCount('login_throttle_cache', 2);
        $this->recreateDatabaseLoginLimiter();
        $this->assertNotSame($store, $this->app['cache']->store('throttle_database')->getStore());
        $this->assertNotSame($limiter, RateLimiter::getFacadeRoot());
        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->travel(9)->seconds();
        $this->login(['password' => 'admin'])
            ->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 1, 'minutes' => 1])]);
        $this->assertCount(2, $this->attempts);
        $this->assertGuest('admin');

        $this->travel(1)->seconds();
        $this->recreateDatabaseLoginLimiter();
        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(4, $this->attempts);
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
        $this->assertDatabaseCount('login_throttle_cache', 0);
        $this->get('/admin/auth/logout')->assertRedirect('/admin');
        $this->recreateDatabaseLoginLimiter();
        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(6, $this->attempts);
        $this->assertDatabaseCount('login_throttle_cache', 0);
    }

    public function test_missing_database_cache_table_does_not_bypass_the_limiter(): void
    {
        $this->useDatabaseLoginLimiter();
        $this->withoutExceptionHandling();

        try {
            $this->login(['password' => 'admin']);
            $this->fail('A missing configured cache table must surface its database error.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('login_throttle_cache', $exception->getSql());
        }

        $this->assertCount(0, $this->attempts);
        $this->assertGuest('admin');
    }

    public function test_success_clears_failures_and_preserves_session_intended_redirect_and_remember(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 2]);
        $this->failedLogin();
        $sessionId = session()->getId();
        $this->withSession(['url.intended' => '/admin/after-login']);
        $this->login(['password' => 'admin', 'remember' => '1'])
            ->assertRedirect('/admin/after-login')
            ->assertCookie(Admin::guard()->getRecallerName());
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotEmpty(Administrator::first()->getRememberToken());
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
        $this->get('/admin/auth/logout')->assertRedirect('/admin');
        $this->assertGuest('admin');

        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertCount(4, $this->attempts);
        $this->login(['password' => 'admin'])->assertRedirect('/admin/auth/login');
        $this->assertCount(4, $this->attempts);
        $this->assertGuest('admin');
    }

    public function test_disabled_throttling_allows_retries_and_keeps_input_redacted(): void
    {
        config(['admin.auth.login_throttle.enabled' => false, 'admin.auth.login_throttle.max_attempts' => 1]);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')])
                ->assertSessionMissing('_old_input.password');
        }
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(7, $this->attempts);
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
    }

    public function test_invalid_limits_fall_back_to_positive_defaults(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 0, 'admin.auth.login_throttle.decay_seconds' => 'invalid']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->failedLogin()->assertSessionHasErrors(['username' => trans('auth.failed')]);
        }
        $this->login(['password' => 'admin'])
            ->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertCount(5, $this->attempts);
        $this->travel(60)->seconds();
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
    }

    public static function independentIdentities(): iterable
    {
        yield 'different username' => ['username'];
        yield 'different IP' => ['ip'];
        yield 'configured guard' => ['configured-guard'];
        yield 'overridden guard' => ['overridden-guard'];
    }

    #[DataProvider('independentIdentities')]
    public function test_username_ip_and_guard_buckets_are_independent(string $identity): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        $this->failedLogin();
        $this->login(['password' => 'admin'])->assertRedirect('/admin/auth/login');
        $this->assertCount(1, $this->attempts);

        $credentials = ['password' => 'admin'];
        $url = '/admin/auth/login';
        $guard = 'admin';
        if ($identity === 'username') {
            Administrator::create(['username' => 'other', 'name' => 'Other', 'password' => Hash::make('admin')]);
            $credentials['username'] = 'other';
        } elseif ($identity === 'ip') {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        } elseif ($identity === 'configured-guard') {
            config(['admin.auth.guard' => 'secondary']);
            $guard = 'secondary';
        } else {
            $url = '/throttle/custom-guard';
            $guard = 'secondary';
        }

        $this->login($credentials, $url)->assertRedirect('/admin');
        $this->assertCount(2, $this->attempts);
        $this->assertAuthenticated($guard);
        Auth::guard($guard)->logout();
        config(['admin.auth.guard' => 'admin']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->login(['password' => 'admin'])->assertRedirect('/admin/auth/login');
        $this->assertCount(2, $this->attempts, 'Success in a different bucket must not reset the blocked bucket.');
        $this->assertGuest('admin');
    }

    public function test_case_variants_share_the_same_limit(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        $this->failedLogin();
        $this->login(['username' => 'ADMIN', 'password' => 'admin'])
            ->assertSessionHasErrors(['username' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertCount(1, $this->attempts);
        $this->assertGuest('admin');
    }

    public function test_custom_username_is_validated_counted_and_flashed_under_its_own_name(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        $url = '/throttle/custom-username';
        $this->from('/admin/auth/login')->post($url, ['name' => 'Administrator', 'password' => 'wrong'])
            ->assertSessionHasErrors(['name' => trans('auth.failed')])
            ->assertSessionHas('_old_input', ['name' => 'Administrator']);
        $this->post($url, ['name' => 'Administrator', 'password' => 'admin'])
            ->assertSessionHasErrors(['name' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertCount(1, $this->attempts);
        $this->travel(60)->seconds();
        $this->post($url, ['name' => 'Administrator', 'password' => 'admin'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
    }

    public static function malformedCredentials(): iterable
    {
        foreach (['username', 'password'] as $field) {
            foreach (['array' => ['nested-secret'], 'integer' => 123, 'boolean' => true, 'null' => null] as $label => $value) {
                yield $field.' '.$label => [$field, $value];
            }
        }
    }

    #[DataProvider('malformedCredentials')]
    public function test_malformed_credentials_are_validation_errors_without_consuming_attempts(string $field, $value): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        $credentials = ['username' => 'admin', 'password' => 'admin'];
        $credentials[$field] = $value;
        $this->postJson('/admin/auth/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertCount(0, $this->attempts);
        $this->login(['password' => 'admin'])->assertRedirect('/admin');
        $this->assertCount(1, $this->attempts);
    }

    public static function failureKinds(): iterable
    {
        yield 'invalid credentials' => ['credentials'];
        yield 'throttled credentials' => ['throttle'];
        yield 'validation failure' => ['validation'];
    }

    #[DataProvider('failureKinds')]
    public function test_failures_flash_only_the_username_and_boolean_remember_state(string $kind): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        if ($kind === 'throttle') {
            $this->failedLogin();
        }
        $this->login([
            'password' => $kind === 'validation' ? ['nested-password-secret'] : 'password-secret',
            'remember' => ['private' => 'remember-secret'],
            'password_confirmation' => 'confirmation-secret',
            'current_password' => 'current-secret',
            '_token' => 'csrf-secret',
            'nested' => ['access_token' => 'token-secret'],
        ])->assertRedirect('/admin/auth/login')->assertSessionHasErrors($kind === 'validation' ? 'password' : 'username')
            ->assertSessionHas('_old_input', ['username' => 'admin', 'remember' => true]);

        $html = $this->get('/admin/auth/login')->assertOk()->getContent();
        $crawler = new Crawler($html);
        $this->assertSame('admin', $crawler->filter('input[name="username"]')->attr('value'));
        $this->assertCount(1, $crawler->filter('input[name="remember"][checked]'));
        $this->assertNull($crawler->filter('input[name="password"]')->attr('value'));
        $this->assertStringNotContainsString('-secret', $html);
        $this->assertGuest('admin');
    }

    public function test_unchecked_remember_state_and_malformed_username_are_safe_to_redisplay(): void
    {
        $this->login(['username' => ['nested-secret'], 'remember' => '0'])
            ->assertSessionHasErrors('username')
            ->assertSessionHas('_old_input', ['remember' => false]);
        $this->get('/admin/auth/login')->assertOk()->assertDontSee('nested-secret');
        $this->login(['remember' => '0'])
            ->assertSessionHas('_old_input', ['username' => 'admin', 'remember' => false]);
        $html = $this->get('/admin/auth/login')->assertOk()->getContent();
        $this->assertCount(0, (new Crawler($html))->filter('input[name="remember"][checked]'));
    }

    public function test_custom_validation_exception_response_is_preserved(): void
    {
        config(['integration.login_validation_response' => true]);
        $this->login(['password' => ['password-secret']], '/throttle/custom-validation')
            ->assertStatus(409)
            ->assertContent('Custom login validation response')
            ->assertHeader('X-Login-Validation', 'custom')
            ->assertSessionMissing('_old_input')
            ->assertSessionMissing('errors');
        $this->assertCount(0, $this->attempts);
        $this->assertGuest('admin');
    }

    public function test_custom_validation_redirect_and_error_bag_preserve_safe_old_input(): void
    {
        $this->login([
            'password' => ['password-secret'],
            'remember' => '1',
            '_token' => 'csrf-secret',
            'nested' => ['access_token' => 'token-secret'],
        ], '/throttle/custom-validation')
            ->assertRedirect('/custom/login')
            ->assertSessionHasErrorsIn('custom_login', ['password'])
            ->assertSessionHas('errors', function ($errors) {
                return !$errors->hasBag('default');
            })
            ->assertSessionHas('_old_input', ['username' => 'admin', 'remember' => true]);
        $this->assertCount(0, $this->attempts);
        $this->assertGuest('admin');
    }

    public function test_string_zero_credentials_are_valid(): void
    {
        Administrator::first()->update(['username' => '0', 'password' => Hash::make('0')]);
        $this->login(['username' => '0', 'password' => '0'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs(Administrator::first(), 'admin');
        $this->assertCount(1, $this->attempts);
    }

    public function test_throttle_message_uses_seconds_and_rounded_up_minutes_from_the_application_translation(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1, 'admin.auth.login_throttle.decay_seconds' => 125]);
        $this->failedLogin();
        Lang::addLines(['auth.throttle' => 'Wait :seconds seconds (:minutes minutes).'], 'en');
        $this->login()->assertSessionHasErrors(['username' => 'Wait 125 seconds (3 minutes).']);
        $this->assertCount(1, $this->attempts);
    }

    public function test_missing_translation_has_an_english_fallback(): void
    {
        config(['admin.auth.login_throttle.max_attempts' => 1]);
        $this->app['translator']->setLocale('throttle-test-missing');
        $this->app['translator']->setFallback('throttle-test-missing');
        $this->failedLogin();
        $this->login()->assertSessionHasErrors([
            'username' => 'Too many login attempts. Please try again in 60 seconds.',
        ]);
        $this->assertCount(1, $this->attempts);
    }

    private function failedLogin()
    {
        return $this->login()->assertRedirect('/admin/auth/login')->assertSessionHasErrors('username');
    }

    private function useDatabaseLoginLimiter(): void
    {
        config([
            'cache.limiter' => 'throttle_database',
            'cache.stores.throttle_database' => [
                'driver' => 'database',
                'connection' => 'testing',
                'table' => 'login_throttle_cache',
                'lock_table' => 'login_throttle_cache_locks',
                'prefix' => 'login-throttle-test:',
            ],
        ]);
        $this->recreateDatabaseLoginLimiter();
    }

    private function recreateDatabaseLoginLimiter(): void
    {
        $this->app['cache']->purge('throttle_database');
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
    }

    private function login(array $input = [], string $url = '/admin/auth/login')
    {
        return $this->from('/admin/auth/login')->post($url, $input + ['username' => 'admin', 'password' => 'wrong']);
    }
}

class LoginThrottleNameController extends AuthController
{
    protected function username()
    {
        return 'name';
    }
}

class LoginThrottleGuardController extends AuthController
{
    protected function guard()
    {
        return Auth::guard('secondary');
    }
}

class LoginThrottleValidationController extends AuthController
{
    protected function loginValidator(array $data)
    {
        return parent::loginValidator($data)->after(function ($validator) {
            if ($validator->errors()->isEmpty()) {
                return;
            }

            $response = config('integration.login_validation_response')
                ? response('Custom login validation response', 409, ['X-Login-Validation' => 'custom'])
                : null;

            throw (new ValidationException($validator, $response, 'custom_login'))->redirectTo('/custom/login');
        });
    }
}
