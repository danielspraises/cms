<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SocietyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Cooperative',
            'code' => $this->faker->unique()->lexify('SOC-????'),
            'currency' => 'NGN',
        ];
    }
}
