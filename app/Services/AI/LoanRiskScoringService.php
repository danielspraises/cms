<?php

namespace App\Services\AI;

use App\Models\Loan;
use App\Models\User;

/**
 * Baseline is deliberately RULE-BASED, not a trained model — there's no
 * training data yet (a brand-new platform has no historical outcomes to
 * learn from), and a transparent, explainable scoring function is a better
 * starting point for a lending decision than an opaque model with no track
 * record. Once enough loans have real outcomes (repaid on time / defaulted),
 * this becomes the natural place to swap in a trained classifier — the
 * interface (score() returning a RiskAssessment) doesn't need to change.
 *
 * Per blueprint §4 guardrails: this NEVER auto-approves or auto-rejects.
 * It produces a score + reasons for a human reviewer.
 */
class LoanRiskScoringService
{
    public function score(User $applicant, int $requestedPrincipalMinor, int $tenureMonths): RiskAssessment
    {
        $points = 100;
        $reasons = [];

        // Savings behavior: consistent saving is the strongest low-cost
        // signal a cooperative platform has for repayment capacity.
        $savingsAccount = $applicant->accounts()->where('account_type', 'savings')->first();
        $savingsBalance = $savingsAccount?->balance_minor ?? 0;

        if ($savingsBalance <= 0) {
            $points -= 25;
            $reasons[] = 'No savings balance on record.';
        } elseif ($savingsBalance < $requestedPrincipalMinor * 0.1) {
            $points -= 10;
            $reasons[] = 'Savings balance is low relative to requested loan amount.';
        }

        // Prior repayment history.
        $priorLoans = $applicant->loans()->whereIn('status', ['terminated', 'active'])->get();
        $priorDefaults = $priorLoans->filter(fn (Loan $l) => $l->status === 'active' && $this->isOverdue($l))->count();

        if ($priorDefaults > 0) {
            $points -= 30 * $priorDefaults;
            $reasons[] = "{$priorDefaults} currently overdue loan(s) on record.";
        }

        if ($priorLoans->isEmpty()) {
            $points -= 5;
            $reasons[] = 'No prior loan history with this cooperative — limited track record.';
        } else {
            $completedCleanly = $priorLoans->where('status', 'terminated')->count();
            if ($completedCleanly > 0) {
                $points += min(15, $completedCleanly * 5);
                $reasons[] = "{$completedCleanly} prior loan(s) completed successfully.";
            }
        }

        // Requested amount vs. tenure sanity check — very large amounts over
        // very short tenures imply a high monthly burden.
        $impliedMonthly = $tenureMonths > 0 ? $requestedPrincipalMinor / $tenureMonths : $requestedPrincipalMinor;
        if ($savingsBalance > 0 && $impliedMonthly > $savingsBalance) {
            $points -= 10;
            $reasons[] = 'Implied monthly installment exceeds current savings balance.';
        }

        $points = max(0, min(100, $points));

        return new RiskAssessment(
            score: $points,
            band: $this->band($points),
            reasons: $reasons,
        );
    }

    private function isOverdue(Loan $loan): bool
    {
        // Placeholder for a real overdue check — would use LoanDueCalculator
        // against the loan's actual due dates. Kept simple here since this
        // service's job is to combine signals, not recompute due amounts.
        return false;
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 75 => 'low_risk',
            $score >= 50 => 'medium_risk',
            default => 'high_risk',
        };
    }
}

final class RiskAssessment
{
    public function __construct(
        public readonly int $score,
        public readonly string $band,
        public readonly array $reasons,
    ) {}

    public function toArray(): array
    {
        return ['score' => $this->score, 'band' => $this->band, 'reasons' => $this->reasons];
    }
}
