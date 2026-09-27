<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;

/**
 * Replaces the priority-allocation logic that was duplicated (and drifting
 * slightly out of sync) between coop_handle_deduction_upload's inline loop
 * and coop_member_payment's self-service breakdown. One implementation now,
 * used by both the CSV bulk-deduction path, the bank/card auto-debit path,
 * and the member-initiated payment path.
 *
 * Priority order is READ FROM SocietySettings.deduction_priority_order,
 * not hard-coded — this is what makes it customizable per society (blueprint
 * §1). The default order if a society hasn't customized it is:
 *   sales -> loan_interest -> loan_principal -> shares -> savings
 */
class DeductionAllocationService
{
    public function __construct(private LoanDueCalculator $dueCalculator) {}

    public function allocate(User $member, int $availableMinor, Carbon $date): AllocationResult
    {
        $result = new AllocationResult();
        $settings = $member->society->effectiveSettings();
        $priorityOrder = $settings->deduction_priority_order;

        // Loans past due are only ever pulled from status = 'active' — once
        // a loan is fully repaid and the termination job flips its status,
        // it silently stops appearing here. See LoanDueCalculator::amountDue()
        // for the second, independent guard (balance-based, not status-based)
        // that also returns 0 in the window before that job runs.
        $activeLoans = $member->loans()->where('status', 'active')->get();

        foreach ($priorityOrder as $fundKey) {
            if ($availableMinor <= 0) {
                break;
            }

            if (in_array($fundKey, ['sales', 'loan_interest', 'loan_principal'], true)) {
                $fundType = match ($fundKey) {
                    'sales' => 'sales', 'loan_interest' => 'interest', 'loan_principal' => 'principal',
                };

                foreach ($activeLoans as $loan) {
                    if ($availableMinor <= 0) {
                        break;
                    }

                    $due = $this->dueCalculator->amountDue($loan, $fundType, $date);
                    $pay = min($due, $availableMinor);

                    if ($pay > 0) {
                        $result->addLine($loan, $fundType, $pay);
                        $availableMinor -= $pay;
                    }
                }
            } elseif ($fundKey === 'shares' && $settings->isFeatureEnabled('share_deductions')) {
                $standing = $member->standingShareDeduction ?? null;
                if ($standing && $standing->is_active) {
                    $pay = min($standing->amount_minor, $availableMinor);
                    if ($pay > 0) {
                        $result->addLine(null, 'shares', $pay);
                        $availableMinor -= $pay;
                    }
                }
            } elseif ($fundKey === 'savings') {
                if ($availableMinor > 0) {
                    $result->addLine(null, 'savings', $availableMinor);
                    $availableMinor = 0;
                }
            }
        }

        // If the society's configured order somehow leaves a remainder
        // unallocated (e.g. 'savings' was accidentally omitted from a custom
        // order), it still lands in savings rather than vanishing — money
        // is never silently unaccounted for.
        if ($availableMinor > 0) {
            $result->addLine(null, 'savings', $availableMinor);
        }

        return $result;
    }
}

/**
 * Small value object instead of the loosely-typed nested array the old code
 * built up.
 */
class AllocationResult
{
    /** @var array<int, array{loan: ?Loan, fund_type: string, amount_minor: int}> */
    private array $lines = [];

    public function addLine(?Loan $loan, string $fundType, int $amountMinor): void
    {
        $this->lines[] = ['loan' => $loan, 'fund_type' => $fundType, 'amount_minor' => $amountMinor];
    }

    /** @return array<int, array{loan: ?Loan, fund_type: string, amount_minor: int}> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalMinor(): int
    {
        return array_sum(array_column($this->lines, 'amount_minor'));
    }

    public function isEmpty(): bool
    {
        return empty($this->lines);
    }
}
