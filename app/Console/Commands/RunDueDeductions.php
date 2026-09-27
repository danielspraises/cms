<?php

namespace App\Console\Commands;

use App\Contracts\PaymentProviderGateway;
use App\Exceptions\PaymentChargeException;
use App\Models\DeductionChannel;
use App\Models\DeductionRun;
use App\Services\DeductionAllocationService;
use App\Services\LedgerService;
use Illuminate\Console\Command;

/**
 * php artisan deductions:run-due
 *
 * Scheduled to run daily (register in routes/console.php or the Kernel's
 * schedule() method: $schedule->command('deductions:run-due')->dailyAt('01:00')).
 *
 * This is the single entry point for bank/card auto-debit — CSV uploads
 * remain a separate, admin-triggered flow (DeductionBatch review + confirm),
 * but both converge on the same DeductionAllocationService + LedgerService
 * once money is actually in hand, per blueprint §3.
 */
class RunDueDeductions extends Command
{
    protected $signature = 'deductions:run-due';
    protected $description = 'Charge all active bank/card deduction channels due today and post the results to the ledger.';

    public function __construct(
        private PaymentProviderGateway $gateway,
        private DeductionAllocationService $allocator,
        private LedgerService $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = now()->startOfDay();

        $dueChannels = DeductionChannel::where('is_active', true)
            ->whereIn('channel_type', ['bank_debit', 'card_debit'])
            ->get()
            ->filter(fn (DeductionChannel $channel) => $channel->isDueOn($today));

        $this->info("Found {$dueChannels->count()} channel(s) due today.");

        foreach ($dueChannels as $channel) {
            $this->processChannel($channel, $today);
        }

        return self::SUCCESS;
    }

    private function processChannel(DeductionChannel $channel, \Illuminate\Support\Carbon $today): void
    {
        // Idempotency: unique(channel_id, scheduled_for) means a re-run of
        // this command on the same day (e.g. after a crash/restart) will not
        // double-charge — firstOrCreate finds the existing attempt instead.
        $run = DeductionRun::firstOrCreate(
            ['channel_id' => $channel->id, 'scheduled_for' => $today],
            ['amount_minor' => $channel->fixed_amount_minor ?? 0, 'status' => 'pending']
        );

        if (in_array($run->status, ['success'], true)) {
            return; // already handled, nothing to do
        }

        if (!$channel->fixed_amount_minor || $channel->fixed_amount_minor <= 0) {
            $run->update(['status' => 'failed', 'failure_reason' => 'No fixed amount configured on channel.']);
            $this->error("Channel {$channel->id}: no fixed amount configured, skipped.");
            return;
        }

        try {
            $providerRef = $this->gateway->chargeAuthorization(
                $channel->provider_mandate_ref,
                $channel->fixed_amount_minor,
                $channel->society->currency ?? 'NGN',
            );
        } catch (PaymentChargeException $e) {
            $run->update([
                'status' => 'failed',
                'attempt_count' => $run->attempt_count + 1,
                'failure_reason' => $e->getMessage(),
            ]);
            $this->error("Channel {$channel->id}: charge failed — {$e->getMessage()}");
            // Notification to admin + member goes here once the notification
            // system (blueprint §5) is built — not silently dropped.
            return;
        }

        // Money is now confirmed in hand — allocate and post exactly like a
        // CSV row or a self-service payment would.
        $allocation = $this->allocator->allocate($channel->member, $channel->fixed_amount_minor, $today);

        $lines = collect($allocation->lines())->map(fn ($line) => [
            'user_id' => $channel->member_id,
            'account_type' => match ($line['fund_type']) {
                'principal' => 'loan_principal', 'interest' => 'loan_interest',
                'sales' => 'sales_loan', 'shares' => 'shares', 'savings' => 'savings',
            },
            'direction' => 'credit',
            'amount_minor' => $line['amount_minor'],
        ])->all();

        $entry = $this->ledger->postEntry(
            societyId: $channel->society_id,
            entryDate: $today,
            description: 'Auto deduction (' . ucfirst($channel->channel_type) . ')',
            source: 'auto_deduction',
            lines: $lines,
            batchId: $this->ledger->newBatchId('AUTO'),
        );

        $run->update([
            'status' => 'success',
            'provider_ref' => $providerRef,
            'ledger_entry_id' => $entry->id,
            'attempt_count' => $run->attempt_count + 1,
        ]);

        $this->info("Channel {$channel->id}: charged and posted, entry #{$entry->id}.");
    }
}
