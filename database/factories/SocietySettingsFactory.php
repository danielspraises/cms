<?php

namespace Database\Factories;

use App\Models\SocietySettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocietySettingsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deduction_priority_order' => SocietySettings::DEFAULT_PRIORITY_ORDER,
            'due_calculation_model' => 'total_due',
            'features' => [
                'sales_loans' => true, 'share_deductions' => true, 'bank_auto_debit' => false,
                'csv_deductions' => true, 'loan_guarantors_required' => true, 'member_self_service_payment' => true,
            ],
            'granted_features' => [],
            'currency' => 'NGN',
        ];
    }
}
