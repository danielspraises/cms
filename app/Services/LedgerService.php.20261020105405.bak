<?php

namespace App\Services;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write path into the ledger. Every place in the old codebase that did
 * $wpdb->insert($table, [...]) directly — loan disbursement, savings credit/debit,
 * deduction distribution, manual entries, payment allocation — now goes through here
 * instead of duplicating the "update balance, insert row" pattern a dozen times.
 *
 * Guarantees:
 *  - every write happens inside a DB transaction
 *  - the account's cached balance is updated atomically with the line insert
 *  - every line records the running balance at insert time (fast history reads,
 *    no need to re-sum on every ledger view like the old shortcodes did)
 */
class LedgerService
{
    /**
     * @param array<int, array{user_id:int, account_type:string, direction:string, amount_minor:int}> $lines
     */
    public function postEntry(
        int $societyId,
        \DateTimeInterface $entryDate,
        string $description,
        string $source,
        array $lines,
        ?int $createdBy = null,
        ?string $batchId = null,
        ?int $loanId = null,
    ): LedgerEntry {
        if (empty($lines)) {
            throw new \InvalidArgumentException('Cannot post a ledger entry with no lines.');
        }

        return DB::transaction(function () use (
            $societyId, $entryDate, $description, $source, $lines, $createdBy, $batchId, $loanId
        ) {
            $entry = LedgerEntry::create([
                'society_id' => $societyId,
                'batch_id' => $batchId,
                'loan_id' => $loanId,
                'entry_date' => $entryDate,
                'description' => $description,
                'source' => $source,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                if ($line['amount_minor'] <= 0) {
                    continue; // mirrors old "only insert if amount > 0" guards
                }

                $account = Account::query()
                    ->lockForUpdate() // prevent lost updates under concurrent writes
                    ->firstOrCreate(
                        ['user_id' => $line['user_id'], 'account_type' => $line['account_type']]
                    );

                $delta = $line['direction'] === 'credit' ? $line['amount_minor'] : -$line['amount_minor'];
                $account->balance_minor += $delta;
                $account->save();

                LedgerLine::create([
                    'entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount_minor' => $line['amount_minor'],
                    'running_balance_minor' => $account->balance_minor,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Reverses an entry with new offsetting lines rather than deleting history —
     * replaces the old "undo batch" pattern which did a hard DELETE FROM ledger.
     * Deleting history destroys the audit trail; reversing preserves it.
     */
    public function reverseEntry(LedgerEntry $original, ?int $actorId = null, ?string $reason = null): LedgerEntry
    {
        $lines = $original->lines->map(fn (LedgerLine $line) => [
            'user_id' => $line->account->user_id,
            'account_type' => $line->account->account_type,
            'direction' => $line->direction === 'credit' ? 'debit' : 'credit',
            'amount_minor' => $line->amount_minor,
        ])->all();

        $reversal = $this->postEntry(
            societyId: $original->society_id,
            entryDate: now(),
            description: "Reversal of #{$original->id}: {$original->description}" . ($reason ? " ({$reason})" : ''),
            source: 'system',
            lines: $lines,
            createdBy: $actorId,
            batchId: $original->batch_id,
            loanId: $original->loan_id,
        );

        $reversal->update(['reversed_of_id' => $original->id]);

        return $reversal;
    }

    public function newBatchId(string $prefix = 'BATCH'): string
    {
        return $prefix . '_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(6));
    }
}
