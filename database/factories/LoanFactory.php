<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class LoanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'principal_disbursed_minor' => 0,
            'principal_repaid_minor' => 0,
            'interest_disbursed_minor' => 0,
            'interest_repaid_minor' => 0,
            'sales_disbursed_minor' => 0,
            'sales_repaid_minor' => 0,
            'tenure_months' => 12,
            'grace_months' => 0,
            'disbursement_date' => now(),
            'guarantors_required' => 0,
            'status' => 'active',
        ];
    }
}
