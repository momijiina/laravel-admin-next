<?php

namespace Tests\Seeds;

use Illuminate\Database\Seeder;
use Tests\Factories\ProfileFactory;
use Tests\Factories\TagFactory;
use Tests\Factories\UserFactory;

class UserTableSeeder extends Seeder
{
    public function run()
    {
        UserFactory::new()->count(50)
            ->create()
            ->each(function ($u) {
                $u->profile()->save(ProfileFactory::new()->make());
                $u->tags()->saveMany(TagFactory::new()->count(5)->make());
                $u->data = ['json' => ['field' => random_int(0, 50)]];
            });
    }
}
