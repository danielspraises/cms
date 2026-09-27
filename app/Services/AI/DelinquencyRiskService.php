<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Flags members likely to miss their NEXT scheduled deduction, based on
 * observable patterns in their own deduction_runs / deduction_rows history.
 * Rule-based for the same reason as LoanRiskScoringService — explainable,
 * no training data dependency, and it's the kind of signal admins can
 * sanity-check against what they already know about a member.
 */
class DelinquencyRiskService
{
    public function assess(User $member): array
    {
        $recentRuns = $member->deductionChannels()
            ->with(['runs' => fn ($q) => $q->orderByDesc('scheduled_for')->limit(6)])
            ->get()
            ->flatMap(fn ($channel) => $channel->runs);

        if ($recentRuns->isEmpty()) {
            return ['risk' => 'unknown', 'reasons' => ['No deduction history yet.']];
        }

        $failureRate = $recentRuns->where('status', 'failed')->count() / max(1, $recentRuns->count());
        $consecutiveFailures = $this->consecutiveFailuresFromMostRecent($recentRuns);

        $reasons = [];
        $risk = 'low';

        if ($consecutiveFailures >= 2) {
            $risk = 'high';
            $reasons[] = "{$consecutiveFailures} consecutive failed deduction attempts.";
        } elseif ($failureRate > 0.3) {
            $risk = 'medium';
            $reasons[] = 'Failure rate over the last 6 scheduled deductions exceeds 30%.';
        }

        $savings = $member->accounts()->where('account_type', 'savings')->first()?->balance_minor ?? 0;
        if ($savings <= 0 && $risk === 'low') {
            $risk = 'medium';
            $reasons[] = 'No savings buffer to draw on if a deduction fails.';
        }

        return ['risk' => $risk, 'reasons' => $reasons ?: ['No concerning patterns detected.']];
    }

    private function consecutiveFailuresFromMostRecent(Collection $runsDescending): int
    {
        $count = 0;
        foreach ($runsDescending as $run) {
            if ($run->status !== 'failed') break;
            $count++;
        }
        return $count;
    }
}
