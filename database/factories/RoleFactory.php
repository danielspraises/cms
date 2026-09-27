<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->jobTitle();

        return [
            'society_id' => null,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'is_system' => false,
        ];
    }
}
