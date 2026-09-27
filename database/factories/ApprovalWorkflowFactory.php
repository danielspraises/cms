<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalWorkflowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Standard Loan Approval',
            'is_default' => true,
        ];
    }
}
