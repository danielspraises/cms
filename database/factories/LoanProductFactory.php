<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class LoanProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Standard Loan',
            'type' => 'cash',
            'interest_method' => 'flat',
            'requires_guarantors' => true,
            'guarantors_required' => 2,
            'rejection_mode' => 'hard_reject',
        ];
    }
}
