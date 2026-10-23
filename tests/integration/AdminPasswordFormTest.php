<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin as AdminManager;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Illuminate\Support\Facades\Hash;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class AdminPasswordFormTest extends TestCase
{
    private array $packageState = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('admin.upload.disk', 'local');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
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

    public static function updateEndpoints(): iterable
    {
        yield 'settings PUT' => ['setting', 'PUT'];
        yield 'users PUT' => ['users', 'PUT'];
        yield 'users PATCH' => ['users', 'PATCH'];
    }

    public static function profileUpdates(): iterable
    {
        foreach (self::updateEndpoints() as $endpoint => [$screen, $method]) {
            foreach ([
                'omitted' => [],
                'blank' => ['password' => '', 'password_confirmation' => ''],
                'null' => ['password' => null, 'password_confirmation' => null],
            ] as $case => $passwords) {
                yield $endpoint.' '.$case => [$screen, $method, $passwords];
            }
        }
    }

    public static function passwordUpdates(): iterable
    {
        foreach (self::updateEndpoints() as $endpoint => [$screen, $method]) {
            foreach (['replacement-secret', '0', 'stored-hash'] as $password) {
                yield $endpoint.' '.$password => [$screen, $method, $password];
            }
        }
    }

    public static function invalidUpdates(): iterable
    {
        foreach (self::updateEndpoints() as $endpoint => [$screen, $method]) {
            foreach ([
                'mismatch' => [['password' => 'replacement-secret', 'password_confirmation' => 'different-secret'], 'password'],
                'missing confirmation' => [['password' => 'replacement-secret'], 'password'],
                'confirmation only' => [['password_confirmation' => 'orphan-secret'], 'password_confirmation'],
                'blank password' => [['password' => '', 'password_confirmation' => 'orphan-secret'], 'password_confirmation'],
                'array password' => [['password' => ['secret'], 'password_confirmation' => ['secret']], 'password'],
            ] as $case => [$passwords, $error]) {
                yield $endpoint.' '.$case => [$screen, $method, $passwords, $error];
            }
        }
    }

    public static function invalidCreations(): iterable
    {
        yield 'omitted' => [[]];
        yield 'blank' => [['password' => '', 'password_confirmation' => '']];
        yield 'null' => [['password' => null, 'password_confirmation' => null]];
        yield 'missing confirmation' => [['password' => 'created-secret']];
        yield 'mismatch' => [['password' => 'created-secret', 'password_confirmation' => 'different-secret']];
        yield 'confirmation only' => [['password_confirmation' => 'orphan-secret']];
        yield 'array password' => [['password' => ['secret'], 'password_confirmation' => ['secret']]];
    }

    public static function createdPasswords(): iterable
    {
        yield 'ordinary password' => ['created-secret'];
        yield 'zero password' => ['0'];
    }

    public function test_builtin_edit_forms_render_blank_optional_passwords_without_the_stored_hash(): void
    {
        $user = $this->loginAdministrator();

        foreach (['setting', 'users'] as $screen) {
            $html = $this->get($this->editUrl($screen, $user))->assertOk()->getContent();
            $this->assertStringNotContainsString($user->password, $html);
            $this->assertBlankPasswordControls($html, true);
        }
    }

    #[DataProvider('profileUpdates')]
    public function test_profile_edits_preserve_the_exact_hash_when_password_is_blank_or_omitted(string $screen, string $method, array $passwords): void
    {
        $user = $this->loginAdministrator();
        $url = $this->updateUrl($screen, $user);
        $input = ['username' => $user->username, 'name' => 'Changed profile'] + $passwords;

        $this->from($this->editUrl($screen, $user))->call($method, $url, $input)
            ->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('Changed profile', $fresh->name);
        $this->assertSame($user->password, $fresh->password);
        $this->assertTrue(Hash::check('admin', $fresh->password));
        $this->assertPasswordLogRedacted($url, $method, $input);
    }

    #[DataProvider('passwordUpdates')]
    public function test_confirmed_password_changes_are_hashed_and_used_by_the_real_login(string $screen, string $method, string $password): void
    {
        $user = $this->loginAdministrator();
        $password = $password === 'stored-hash' ? $user->password : $password;
        $url = $this->updateUrl($screen, $user);
        $input = [
            'username' => $user->username, 'name' => 'Changed password',
            'password' => $password, 'password_confirmation' => $password,
        ];

        $this->from($this->editUrl($screen, $user))->call($method, $url, $input)
            ->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('Changed password', $fresh->name);
        $this->assertNotSame($password, $fresh->password);
        $this->assertNotSame($user->password, $fresh->password);
        $this->assertTrue(Hash::check($password, $fresh->password));
        $this->assertFalse(Hash::check('admin', $fresh->password));
        $this->assertPasswordLogRedacted($url, $method, $input);
        $html = $this->get($this->editUrl($screen, $fresh))->assertOk()->getContent();
        $this->assertStringNotContainsString($fresh->password, $html);
        $this->assertBlankPasswordControls($html, true);

        $this->get('/admin/auth/logout')->assertRedirect('/admin');
        $this->assertGuest('admin');
        $this->from('/admin/auth/login')->post('/admin/auth/login', [
            'username' => $user->username, 'password' => 'admin',
        ])->assertRedirect('/admin/auth/login')->assertSessionHasErrors('username');
        $this->assertGuest('admin');
        $this->post('/admin/auth/login', [
            'username' => $user->username, 'password' => $password,
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($fresh, 'admin');
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_password_updates_leave_the_record_unchanged_and_render_blank_on_retry(string $screen, string $method, array $passwords, string $error): void
    {
        $user = $this->loginAdministrator();
        $url = $this->updateUrl($screen, $user);
        $edit = $this->editUrl($screen, $user);
        $input = ['username' => $user->username, 'name' => 'Must not be saved'] + $passwords;

        $this->from($edit)->call($method, $url, $input)
            ->assertRedirect($edit)->assertSessionHasErrors($error);

        $this->assertSame($user->getAttributes(), $user->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_users', 1);
        $this->assertPasswordLogRedacted($url, $method, $input);
        $html = $this->get($edit)->assertOk()->getContent();
        $this->assertBlankPasswordControls($html, true);
        $this->assertStringNotContainsString($user->password, $html);
        $this->assertStringNotContainsString('replacement-secret', $html);
        $this->assertStringNotContainsString('different-secret', $html);
        $this->assertStringNotContainsString('orphan-secret', $html);
    }

    #[DataProvider('invalidCreations')]
    public function test_creation_requires_a_confirmed_string_password_before_inserting_a_user(array $passwords): void
    {
        $this->loginAdministrator();
        $input = ['username' => 'new-admin', 'name' => 'New administrator'] + $passwords;

        $this->from('/admin/auth/users/create')->post('/admin/auth/users', $input)
            ->assertRedirect('/admin/auth/users/create')->assertSessionHasErrors('password');

        $this->assertDatabaseCount('admin_users', 1);
        $this->assertDatabaseMissing('admin_users', ['username' => 'new-admin']);
        $this->assertPasswordLogRedacted('/admin/auth/users', 'POST', $input);
        $html = $this->get('/admin/auth/users/create')->assertOk()->getContent();
        $this->assertBlankPasswordControls($html, false);
        $this->assertStringNotContainsString('created-secret', $html);
        $this->assertStringNotContainsString('different-secret', $html);
        $this->assertStringNotContainsString('orphan-secret', $html);
    }

    #[DataProvider('createdPasswords')]
    public function test_rendered_creation_form_saves_a_hash_and_the_new_user_can_log_in(string $password): void
    {
        $this->loginAdministrator();
        $html = $this->get('/admin/auth/users/create')->assertOk()->getContent();
        $this->assertBlankPasswordControls($html, false);
        $form = (new Crawler($html, 'http://localhost/admin/auth/users/create'))->filter('form.form-horizontal')->form();
        $form['username'] = 'new-admin';
        $form['name'] = 'New administrator';
        $form['password'] = $password;
        $form['password_confirmation'] = $password;
        $input = $form->getPhpValues();

        $this->post($form->getUri(), $input)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('admin_users', 2);
        $created = Administrator::where('username', 'new-admin')->firstOrFail();
        $this->assertSame('New administrator', $created->name);
        $this->assertNotSame($password, $created->password);
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertPasswordLogRedacted('/admin/auth/users', 'POST', $input);
        $this->get('/admin/auth/logout')->assertRedirect('/admin');
        $this->post('/admin/auth/login', [
            'username' => 'new-admin', 'password' => $password,
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($created, 'admin');
    }

    private function loginAdministrator(): Administrator
    {
        $this->post('/admin/auth/login', ['username' => 'admin', 'password' => 'admin'])->assertRedirect('/admin');
        $user = Administrator::firstOrFail();
        $this->assertAuthenticatedAs($user, 'admin');

        return $user;
    }

    private function updateUrl(string $screen, Administrator $user): string
    {
        return $screen === 'setting' ? '/admin/auth/setting' : '/admin/auth/users/'.$user->getKey();
    }

    private function editUrl(string $screen, Administrator $user): string
    {
        return $this->updateUrl($screen, $user).($screen === 'setting' ? '' : '/edit');
    }

    private function assertBlankPasswordControls(string $html, bool $editing): void
    {
        $crawler = new Crawler($html);

        foreach (['password', 'password_confirmation'] as $name) {
            $control = $crawler->filter('input[name="'.$name.'"]');
            $this->assertCount(1, $control);
            $this->assertSame('password', $control->attr('type'));
            $this->assertSame('', $control->attr('value'));
            if ($editing) {
                $this->assertFalse($control->getNode(0)->hasAttribute('required'));
            }
        }
    }

    private function assertPasswordLogRedacted(string $url, string $method, array $input): void
    {
        $log = OperationLog::where('path', ltrim($url, '/'))->where('method', $method)->latest('id')->firstOrFail();
        $logged = json_decode($log->input, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($input['name'], $logged['name']);

        foreach (['password', 'password_confirmation'] as $name) {
            if (array_key_exists($name, $input)) {
                $this->assertSame('[REDACTED]', $logged[$name]);
            } else {
                $this->assertArrayNotHasKey($name, $logged);
            }
        }
    }
}
