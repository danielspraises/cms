<?php

namespace App\Services\AI;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Flags manual ledger edits worth a second admin's attention. Rule-based
 * thresholds rather than a trained anomaly model, for the same explainability
 * reason as the other Tier 1 services — an admin reviewing a flag needs to
 * see WHY it was flagged, not just a black-box score.
 */
class LedgerAnomalyDetectionService
{
    private const LARGE_EDIT_THRESHOLD_MINOR = 500_000_00; // 500,000.00 in kobo — tune per society

    public function flagsForRecentActivity(int $societyId, int $lookbackDays = 7): array
    {
        $flags = [];

        // 1. Large manual entries.
        $largeEntries = LedgerEntry::where('society_id', $societyId)
            ->where('source', 'manual')
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->whereHas('lines', fn ($q) => $q->where('amount_minor', '>', self::LARGE_EDIT_THRESHOLD_MINOR))
            ->get();

        foreach ($largeEntries as $entry) {
            $flags[] = [
                'type' => 'large_manual_entry',
                'entry_id' => $entry->id,
                'description' => $entry->description,
                'created_by' => $entry->created_by,
                'reason' => 'Manual entry exceeds the large-amount review threshold.',
            ];
        }

        // 2. Repeated force-overrides by the same actor (batch reconciliation
        // bypass, per the original WordPress code's "force_override" pattern).
        $repeatedOverrides = AuditLog::where('action', 'ledger_entry.amend')
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->select('actor_id', DB::raw('count(*) as edit_count'))
            ->groupBy('actor_id')
            ->havingRaw('count(*) > 5')
            ->get();

        foreach ($repeatedOverrides as $row) {
            $flags[] = [
                'type' => 'frequent_ledger_amendments',
                'actor_id' => $row->actor_id,
                'edit_count' => $row->edit_count,
                'reason' => "Actor made {$row->edit_count} ledger amendments in {$lookbackDays} days — worth a review.",
            ];
        }

        // 3. Edits made outside typical business hours (a soft signal, not
        // proof of anything — flagged for context, not accusation).
        $afterHoursEdits = LedgerEntry::where('society_id', $societyId)
            ->where('source', 'manual')
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->get()
            ->filter(fn (LedgerEntry $e) => $e->created_at->hour < 6 || $e->created_at->hour > 21);

        foreach ($afterHoursEdits as $entry) {
            $flags[] = [
                'type' => 'after_hours_edit',
                'entry_id' => $entry->id,
                'created_at' => $entry->created_at,
                'reason' => 'Manual entry created outside typical business hours.',
            ];
        }

        return $flags;
    }
}
