<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

class CreateUserCommandTest extends TestCase
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
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
    }

    public static function selectedRoles(): iterable
    {
        yield 'single restricted role' => [
            ['Administrator', 'Editor', 'Auditor'], ['Editor [29]'], [29],
        ];
        yield 'multiple restricted roles' => [
            ['Administrator', 'Editor', 'Auditor'], ['Editor [29]', 'Auditor [41]'], [29, 41],
        ];
        yield 'administrator explicitly selected' => [
            ['Administrator', 'Editor'], ['Administrator [17]'], [17],
        ];
        yield 'only available role' => [['Editor'], ['Editor [17]'], [17]];
        yield 'all roles explicitly selected' => [
            ['Administrator', 'Editor'], ['Administrator [17]', 'Editor [29]'], [17, 29],
        ];
        yield 'distinct numeric-looking names' => [['0', '00', '0.0'], ['0 [17]'], [17]];
        yield 'unicode name' => [['Administrator', '編集担当'], ['編集担当 [29]'], [29]];
    }

    #[DataProvider('selectedRoles')]
    public function test_creation_prompts_and_attaches_only_the_selected_roles(array $names, array $selected, array $expectedIds): void
    {
        $choices = [];
        foreach ($names as $index => $name) {
            $role = new Role(['name' => $name, 'slug' => $name === 'Administrator' ? 'administrator' : 'role-'.$index]);
            $role->id = [17, 29, 41][$index];
            $role->save();
            $choices[] = $name.' ['.$role->id.']';
        }

        $this->createUser()
            ->expectsChoice('Please choose a role for the user', $selected, $choices, true)
            ->expectsOutput('User [Created User] created successfully.')
            ->assertExitCode(0);

        $user = $this->assertCreatedUser();
        $this->assertSame($expectedIds, $user->roles()->orderBy('id')->pluck('id')->all());
        $this->assertSame(count($expectedIds), $this->app['db']->table('admin_role_users')->count());
        $this->assertSame(in_array('Administrator [17]', $selected, true), $user->isAdministrator());
        $this->assertSame(count($names), Role::count());
    }

    public function test_creation_without_available_roles_does_not_prompt_for_an_empty_choice(): void
    {
        $this->createUser()
            ->expectsOutput('User [Created User] created successfully.')
            ->assertExitCode(0);

        $user = $this->assertCreatedUser();
        $this->assertSame([], $user->roles()->pluck('id')->all());
        $this->assertSame(0, $this->app['db']->table('admin_role_users')->count());
        $this->assertFalse($user->isAdministrator());
    }

    public static function terminalSelections(): iterable
    {
        yield 'numeric names selected by index' => [['0', '00', '0.0'], '1,2', [29, 41]];
        yield 'name does not shadow an index' => [['1', 'Editor', 'Auditor'], '1', [29]];
        yield 'complete displayed label' => [['0', '00', '0.0'], '00 [29]', [29]];
    }

    #[DataProvider('terminalSelections')]
    public function test_real_console_choice_normalization_keeps_role_identity(array $names, string $answer, array $expectedIds): void
    {
        foreach ($names as $index => $name) {
            $role = new Role(['name' => $name, 'slug' => $index === 0 ? 'administrator' : 'role-'.$index]);
            $role->id = [17, 29, 41][$index];
            $role->save();
        }

        $this->withoutMockingConsoleOutput();
        $tester = new CommandTester($this->app[Kernel::class]->all()['admin:create-user']);
        $tester->setInputs(['created-user', 'disposable-command-secret', 'Created User', $answer]);
        $this->assertSame(0, $tester->execute([], ['interactive' => true, 'decorated' => false]));
        $this->assertStringContainsString('Please choose a role for the user', $tester->getDisplay());
        $this->assertStringNotContainsString('disposable-command-secret', $tester->getDisplay());
        $user = $this->assertCreatedUser();
        $this->assertSame($expectedIds, $user->roles()->orderBy('id')->pluck('id')->all());
        $this->assertFalse($user->isAdministrator());
    }

    private function createUser()
    {
        return $this->artisan('admin:create-user')
            ->expectsQuestion('Please enter a username to login', 'created-user')
            ->expectsQuestion('Please enter a password to login', 'disposable-command-secret')
            ->expectsQuestion('Please enter a name to display', 'Created User');
    }

    private function assertCreatedUser(): Administrator
    {
        $this->assertSame(1, Administrator::count());
        $user = Administrator::firstOrFail();
        $this->assertSame('created-user', $user->username);
        $this->assertSame('Created User', $user->name);
        $this->assertNotSame('disposable-command-secret', $user->password);
        $this->assertTrue(Hash::check('disposable-command-secret', $user->password));

        return $user;
    }
}
