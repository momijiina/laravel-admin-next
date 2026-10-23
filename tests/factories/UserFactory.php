<?php

namespace Tests\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => $this->faker->userName(),
            'email'    => $this->faker->email(),
            'mobile'   => $this->faker->phoneNumber(),
            // BrowserKit checks the image URL markup without downloading a remote image.
            'avatar'   => 'https://example.com/avatars/'.$this->faker->uuid().'.png',
            'password' => '$2y$10$U2WSLymU6eKJclK06glaF.Gj3Sw/ieDE3n7mJYjKEgDh4nzUiSESO', // bcrypt(123456)
        ];
    }
}
