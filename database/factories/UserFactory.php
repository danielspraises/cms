<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'society_id' => null,
            'role' => 'member',
            'member_uid' => $this->faker->unique()->numerify('MU-######'),
            'full_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
        ];
    }
}
