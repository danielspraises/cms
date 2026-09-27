<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'description' => $this->faker->sentence(),
            'group' => 'test',
        ];
    }
}
