<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a admin user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userModel = config('admin.database.users_model');
        $roleModel = config('admin.database.roles_model');

        $username = $this->ask('Please enter a username to login');

        $password = Hash::make($this->secret('Please enter a password to login'));

        $name = $this->ask('Please enter a name to display');

        $roles = $roleModel::all();

        /** @var array $selected */
        // Include the identity so numeric names cannot be mistaken for choice indexes.
        $selectedOption = $roles->map(function ($role) {
            return $role->name.' ['.$role->getKey().']';
        })->all();

        if (!empty($selectedOption)) {
            $selected = $this->choice('Please choose a role for the user', $selectedOption, null, null, true);

            $roles = $roles->filter(function ($role, $index) use ($selected, $selectedOption) {
                return in_array($selectedOption[$index], $selected, true);
            });
        }

        $user = new $userModel(compact('username', 'password', 'name'));

        $user->save();

        if (isset($roles)) {
            $user->roles()->attach($roles);
        }

        $this->info("User [$name] created successfully.");
    }
}
