<?php

declare(strict_types=1);

namespace Database\Factories;

use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => null,
        ];
    }
}
