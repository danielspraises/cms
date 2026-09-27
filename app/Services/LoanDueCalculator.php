<?php

namespace App\Services;

use App\Models\Loan;
use Carbon\Carbon;

/**
 * Replaces coop_principal_due(), coop_interest_due(), coop_sales_due() — three
 * near-identical functions in the old codebase, differing only in which columns
 * they read. Fund type is now a parameter, not a copy-pasted function.
 *
 * IMPORTANT — see blueprint §1: the total-due vs. monthly-due choice is now a
 * PER-SOCIETY setting (SocietySettings.due_calculation_model), not a hard-coded
 * flag. Pass the owning Loan's society settings in, or let amountDue() resolve
 * it from $loan->society->effectiveSettings() when not provided.
 *
 * CONFIRMED BUG FIX (found via a failing test, not caught by review):
 * Carbon::diffInMonths() returns a precise FLOAT (e.g. 1.9677...), not a whole
 * number of completed months, in the Carbon version this app uses. The original
 * code used that value directly in integer-assuming arithmetic
 * ($monthlyInstallment * $monthsOwed), which corrupted every due-amount
 * calculation into a non-round figure. Fixed by explicitly flooring to a whole
 * number of COMPLETED months — 1.97 elapsed months means 1 full month's
 * installment is owed, not a fractional 1.97 months' worth.
 */
class LoanDueCalculator
{
    public function amountDue(Loan $loan, string $fundType, ?Carbon $asOfDate = null): int
    {
        $asOfDate ??= now();

        if (!$loan->disbursement_date || $loan->tenure_months <= 0) {
            return 0;
        }

        $disbursed = $loan->disbursedMinor($fundType);
        $remaining = $loan->remainingMinor($fundType);

        if ($disbursed <= 0 || $remaining <= 0) {
            return 0;
        }

        $accrualStart = Carbon::parse($loan->disbursement_date)->addMonths($loan->grace_months);
        if ($asOfDate->lt($accrualStart)) {
            return 0;
        }

        // Floor to whole COMPLETED months — see class docblock. Without this,
        // diffInMonths' fractional return value corrupts the multiplication
        // below into a non-round, incorrect due amount.
        $monthsElapsed = (int) floor($accrualStart->diffInMonths($asOfDate));
        if ($monthsElapsed <= 0) {
            return 0;
        }

        $monthlyInstallment = (int) ceil($disbursed / $loan->tenure_months);
        if ($monthlyInstallment <= 0) {
            return 0;
        }

        $repaid = $loan->repaidMinor($fundType);
        $monthsPaidFor = intdiv($repaid, $monthlyInstallment);
        $monthsOwed = max(0, $monthsElapsed - $monthsPaidFor);

        if ($monthsOwed <= 0) {
            return 0;
        }

        $capAtOneInstallment = $loan->society?->effectiveSettings()->due_calculation_model === 'monthly_due';

        $owed = $capAtOneInstallment
            ? min($monthlyInstallment, $remaining)
            : min($monthlyInstallment * $monthsOwed, $remaining);

        return $owed;
    }

    /**
     * A loan is eligible for auto-termination when its full tenure (including
     * grace) has elapsed AND every fund type is fully repaid.
     */
    public function isEligibleForTermination(Loan $loan, ?Carbon $asOfDate = null): bool
    {
        $asOfDate ??= now();

        if (!$loan->disbursement_date) {
            return false;
        }

        $repaymentStart = Carbon::parse($loan->disbursement_date)->addMonths($loan->grace_months);
        $tenureEnd = $repaymentStart->copy()->addMonths($loan->tenure_months);

        return $asOfDate->gte($tenureEnd) && $loan->isFullyRepaid();
    }
}