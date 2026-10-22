<?php

namespace App\Services;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write path into the ledger. Every write happens inside a DB
 * transaction, updates the account's cached balance with the line insert, and
 * records the running balance on each line.
 *
 * A line may carry a loan_id. For loan accounts, that keeps the loan's own
 * disbursed and repaid figures in step in the same transaction, which is what
 * lets a payment or a settlement credit land on exactly the right loan.
 */
class LedgerService
{
    /**
     * @param array<int, array{user_id:int, account_type:string, direction:string, amount_minor:int, loan_id?:int|null}> $lines
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
                    continue;
                }

                $account = Account::query()
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $line['user_id'], 'account_type' => $line['account_type']]
                    );

                $delta = $line['direction'] === 'credit' ? $line['amount_minor'] : -$line['amount_minor'];
                $account->balance_minor += $delta;
                $account->save();

                LedgerLine::create([
                    'entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'loan_id' => $line['loan_id'] ?? null,
                    'direction' => $line['direction'],
                    'amount_minor' => $line['amount_minor'],
                    'running_balance_minor' => $account->balance_minor,
                ]);

                if (!empty($line['loan_id'])) {
                    $this->applyToLoan($line);
                }
            }

            return $entry->load('lines');
        });
    }

    /** Keeps the named loan's own disbursed/repaid figures in step with the ledger line. */
    private function applyToLoan(array $line): void
    {
        $column = match ([$line['account_type'], $line['direction']]) {
            ['loan_principal', 'debit'] => 'principal_disbursed_minor',
            ['loan_principal', 'credit'] => 'principal_repaid_minor',
            ['loan_interest', 'debit'] => 'interest_disbursed_minor',
            ['loan_interest', 'credit'] => 'interest_repaid_minor',
            ['sales_loan', 'debit'] => 'sales_disbursed_minor',
            ['sales_loan', 'credit'] => 'sales_repaid_minor',
            default => null,
        };

        if ($column !== null) {
            DB::table('loans')->where('id', $line['loan_id'])->increment($column, $line['amount_minor']);
        }
    }

    /**
     * Reverses an entry with new offsetting lines rather than deleting history.
     * A reversed loan line is booked as the opposite direction, so the loan's
     * remaining balance comes out right even though its repaid figure rises.
     */
    public function reverseEntry(LedgerEntry $original, ?int $actorId = null, ?string $reason = null): LedgerEntry
    {
        $lines = $original->lines->map(fn (LedgerLine $line) => [
            'user_id' => $line->account->user_id,
            'account_type' => $line->account->account_type,
            'direction' => $line->direction === 'credit' ? 'debit' : 'credit',
            'amount_minor' => $line->amount_minor,
            'loan_id' => $line->loan_id,
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