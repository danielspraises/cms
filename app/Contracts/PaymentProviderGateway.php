<?php

namespace App\Contracts;

/**
 * Thin abstraction over Paystack (or any future provider) so
 * DailyDeductionRunner doesn't call the Paystack SDK directly — makes it
 * possible to unit-test the runner with a fake gateway, and to add a second
 * provider later without touching the runner.
 */
interface PaymentProviderGateway
{
    /**
     * Charge a previously-authorized card/mandate. Returns a provider
     * reference on success; throws PaymentChargeException on failure.
     */
    public function chargeAuthorization(string $mandateRef, int $amountMinor, string $currency): string;
}
