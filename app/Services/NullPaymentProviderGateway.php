<?php

namespace App\Services;

use App\Contracts\PaymentProviderGateway;
use App\Exceptions\PaymentChargeException;

/**
 * TEMPORARY placeholder — binds the PaymentProviderGateway interface so the
 * app can boot (Laravel resolves console command dependencies eagerly, and
 * RunDueDeductions needs a concrete implementation to exist). This is NOT a
 * real gateway: it throws if actually invoked, on purpose, rather than
 * silently pretending to charge a member. Replace this binding in
 * AppServiceProvider with a real Paystack implementation
 * (charge_authorization API call) before RunDueDeductions ever runs for
 * real — until then, this exists purely so `php artisan migrate`,
 * `php artisan test`, etc. don't crash on an unrelated missing binding.
 */
class NullPaymentProviderGateway implements PaymentProviderGateway
{
    public function chargeAuthorization(string $mandateRef, int $amountMinor, string $currency): string
    {
        throw new PaymentChargeException(
            'No real payment gateway is configured yet. NullPaymentProviderGateway is a placeholder — ' .
            'see app/Services/NullPaymentProviderGateway.php for what needs replacing.'
        );
    }
}