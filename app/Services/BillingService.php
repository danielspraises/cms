<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SocietySubscription;
use Illuminate\Support\Str;

/**
 * Handles invoice generation for society subscriptions. Cadence comes from
 * the assigned BillingPlan (monthly/quarterly/biannual/annual) but the super
 * admin can override the cycle length and amount per-society via
 * cycle_months_override / amount_minor_override on SocietySubscription —
 * this is the "or here anomaly... half a year or depending on what the
 * super admin said" case: a one-off arrangement doesn't need a whole new
 * BillingPlan row.
 *
 * Intended to run from a scheduled command (`php artisan billing:generate-due-invoices`,
 * to be added) checking `next_billing_date <= today` across all active
 * subscriptions, rather than a single big loop over everything every day.
 */
class BillingService
{
    public function generateInvoice(SocietySubscription $subscription): Invoice
    {
        $periodStart = $subscription->current_period_end
            ? $subscription->current_period_end->addDay()
            : now()->startOfDay();

        $periodEnd = $periodStart->copy()->addMonths($subscription->effectiveCycleMonths())->subDay();

        $invoice = Invoice::create([
            'society_id' => $subscription->society_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => $this->nextInvoiceNumber(),
            'amount_minor' => $subscription->effectiveAmountMinor(),
            'currency' => $subscription->plan?->currency ?? 'NGN',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $periodStart->copy()->addDays(7), // 7-day grace to pay from period start
            'status' => 'pending',
        ]);

        $subscription->update([
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'next_billing_date' => $periodEnd->copy()->addDay(),
        ]);

        return $invoice;
    }

    public function markPaid(Invoice $invoice, string $providerRef): Invoice
    {
        $invoice->update([
            'status' => 'paid',
            'provider_ref' => $providerRef,
            'paid_at' => now(),
        ]);

        // A society whose subscription was 'past_due' becomes 'active' again
        // on successful payment.
        $invoice->subscription->update(['status' => 'active']);

        return $invoice;
    }

    /**
     * Sweeps for invoices past due_date still 'pending' and marks them
     * 'overdue', flipping the subscription to 'past_due'. Intended to run
     * daily alongside generateInvoice's scheduled command.
     */
    public function markOverdueInvoices(): int
    {
        $overdue = Invoice::where('status', 'pending')
            ->where('due_date', '<', now())
            ->get();

        foreach ($overdue as $invoice) {
            $invoice->update(['status' => 'overdue']);
            $invoice->subscription->update(['status' => 'past_due']);
        }

        return $overdue->count();
    }

    private function nextInvoiceNumber(): string
    {
        return 'INV-' . now()->format('Ym') . '-' . Str::upper(Str::random(6));
    }
}
