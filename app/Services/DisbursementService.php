<?php

namespace App\Services;

use App\Exceptions\ApprovalActionException;
use App\Models\ApprovalStage;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The three steps after approval. The workflow engine owns who may act and in
 * what order; this service owns the sheet, the payout record and the posting.
 *
 *   prepare         (society admin)  settlement sheet: interest, earlier loans cleared, net payout
 *   recordPayout    (treasurer)      the payout: date, method, reference
 *   confirmAndPost  (society admin)  re-checks, then writes the ledger entries
 *
 * Only confirmAndPost writes to the ledger.
 */
class DisbursementService
{
    private const FUND_ACCOUNT = [
        'principal' => 'loan_principal',
        'interest' => 'loan_interest',
        'sales' => 'sales_loan',
    ];

    public function __construct(
        private LoanApprovalService $approval,
        private LedgerService $ledger,
        private InterestCalculator $interest,
    ) {}

    /** Everything the settlement / payout / cross-check screens need. */
    public function sheet(LoanApplication $application): array
    {
        $application->loadMissing(['product', 'currentStage']);

        return [
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'loan_id' => $application->loan_id,
            ],
            'stage' => $application->currentStage ? [
                'id' => $application->currentStage->id,
                'name' => $application->currentStage->name,
                'action_type' => $application->currentStage->action_type,
            ] : null,
            'terms' => $this->terms($application),
            'outstanding_loans' => $this->outstandingLoans($application),
            'disbursement' => LoanDisbursement::with('settlements.loan')
                ->where('loan_application_id', $application->id)->first(),
        ];
    }

    /** The approved amount and the interest the loan type works out for it. */
    public function terms(LoanApplication $application): array
    {
        $product = $application->product;
        $principal = $application->requested_principal_minor;
        $tenure = $application->requested_tenure_months;

        return [
            'principal_minor' => $principal,
            'tenure_months' => $tenure,
            'interest_rate_bp' => $this->interest->rateBpFor($product, $tenure),
            'interest_minor' => $this->interest->interestFor($product, $principal, $tenure),
        ];
    }

    /** The member's running loans that still have something owing, for the settlement choices. */
    public function outstandingLoans(LoanApplication $application): array
    {
        $loans = Loan::where('society_id', $application->society_id)
            ->where('member_id', $application->member_id)
            ->where('status', 'active')
            ->orderBy('disbursement_date')->orderBy('id')
            ->get();

        $names = LoanProduct::whereIn('id', $loans->pluck('loan_product_id')->filter())->pluck('name', 'id');

        return $loans->map(fn (Loan $loan) => [
            'loan_id' => $loan->id,
            'name' => $names->get($loan->loan_product_id, 'Earlier loan'),
            'disbursed_on' => $loan->disbursement_date?->toDateString(),
            'tenure_months' => $loan->tenure_months,
            'balances' => [
                'principal' => $loan->remainingMinor('principal'),
                'interest' => $loan->remainingMinor('interest'),
                'sales' => $loan->remainingMinor('sales'),
            ],
        ])->filter(fn (array $loan) => array_sum($loan['balances']) > 0)->values()->all();
    }

    /**
     * Step 4, Settlement. $settlements is a list of
     * ['loan_id' => int, 'fund_type' => 'principal'|'interest'|'sales', 'amount_minor' => int].
     * An empty list is fine: nothing is cleared and the whole amount is paid out.
     *
     * @throws ApprovalActionException
     */
    public function prepare(LoanApplication $application, User $actor, array $settlements, ?string $notes = null): LoanDisbursement
    {
        return DB::transaction(function () use ($application, $actor, $settlements, $notes) {
            $application = LoanApplication::query()->lockForUpdate()->findOrFail($application->id);
            $stage = $this->assertAtStep($application, 'prepare', 'settlement');
            $this->assertCanAct($stage, $actor);

            $terms = $this->terms($application);
            $rows = $this->validatedSettlements($application, $settlements);

            $settlementTotal = array_sum(array_column($rows, 'amount_minor'));
            $net = $terms['principal_minor'] - $settlementTotal;

            if ($net < 0) {
                throw new ApprovalActionException('The loans being cleared add up to more than the new loan, so there would be nothing to pay out.');
            }

            $disbursement = LoanDisbursement::updateOrCreate(
                ['loan_application_id' => $application->id],
                [
                    'society_id' => $application->society_id,
                    'member_id' => $application->member_id,
                    'principal_minor' => $terms['principal_minor'],
                    'tenure_months' => $terms['tenure_months'],
                    'interest_rate_bp' => $terms['interest_rate_bp'],
                    'interest_minor' => $terms['interest_minor'],
                    'settlement_total_minor' => $settlementTotal,
                    'net_payout_minor' => $net,
                    'status' => 'prepared',
                    'prepared_by' => $actor->id,
                    'prepared_at' => now(),
                    'paid_by' => null,
                    'paid_at' => null,
                    'paid_on' => null,
                    'payment_method' => null,
                    'payment_reference' => null,
                ]
            );

            $disbursement->settlements()->delete();
            foreach ($rows as $row) {
                $disbursement->settlements()->create($row);
            }

            $this->approval->act($application, $actor, 'prepare', $notes);

            return $disbursement->fresh('settlements');
        });
    }

    /**
     * Step 5, Disbursement: the treasurer records that the net amount was paid.
     * Also used again after a return from the cross-check, to correct the details.
     *
     * @param  array{paid_on:string, payment_method:string, payment_reference?:string|null}  $details
     * @throws ApprovalActionException
     */
    public function recordPayout(LoanApplication $application, User $actor, array $details, ?string $notes = null): LoanDisbursement
    {
        return DB::transaction(function () use ($application, $actor, $details, $notes) {
            $application = LoanApplication::query()->lockForUpdate()->findOrFail($application->id);
            $stage = $this->assertAtStep($application, 'disburse', 'disbursement');
            $this->assertCanAct($stage, $actor);

            $disbursement = LoanDisbursement::where('loan_application_id', $application->id)->lockForUpdate()->first();
            if (!$disbursement || !in_array($disbursement->status, ['prepared', 'paid_out'], true)) {
                throw new ApprovalActionException('There is no prepared settlement sheet to pay out against.');
            }

            $disbursement->update([
                'status' => 'paid_out',
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'paid_on' => $details['paid_on'],
                'payment_method' => $details['payment_method'],
                'payment_reference' => $details['payment_reference'] ?? null,
            ]);

            $this->approval->act($application, $actor, 'disburse', $notes);

            return $disbursement->fresh('settlements');
        });
    }

    /**
     * Step 6, Cross-check and posting: the only step that writes to the ledger.
     * Creates the new loan at its FULL amount with interest on that full amount,
     * credits each earlier loan being cleared, and closes the ones now fully paid.
     *
     * @throws ApprovalActionException
     */
    public function confirmAndPost(LoanApplication $application, User $actor, ?string $notes = null): LoanApplication
    {
        return DB::transaction(function () use ($application, $actor, $notes) {
            $application = LoanApplication::query()->lockForUpdate()->findOrFail($application->id);
            $stage = $this->assertAtStep($application, 'confirm', 'cross-check');
            $this->assertCanAct($stage, $actor);

            $disbursement = LoanDisbursement::with('settlements')
                ->where('loan_application_id', $application->id)->lockForUpdate()->first();
            if (!$disbursement || $disbursement->status !== 'paid_out') {
                throw new ApprovalActionException('The payout has not been recorded yet.');
            }

            // The earlier loans must still owe what the sheet says. If a repayment
            // landed in between, the sheet is stale and has to be redone.
            $earlierLoans = [];
            foreach ($disbursement->settlements as $settlement) {
                $earlier = Loan::query()->lockForUpdate()->find($settlement->loan_id);

                if (!$earlier || $earlier->status !== 'active'
                    || $earlier->remainingMinor($settlement->fund_type) < $settlement->amount_minor) {
                    throw new ApprovalActionException(
                        "The balance on loan #{$settlement->loan_id} has changed since the settlement sheet was prepared. Send this back so the sheet can be updated."
                    );
                }

                $earlierLoans[$earlier->id] = $earlier;
            }

            $product = $application->product;

            $loan = Loan::create([
                'society_id' => $application->society_id,
                'member_id' => $application->member_id,
                'loan_product_id' => $application->loan_product_id,
                'tenure_months' => $disbursement->tenure_months,
                'grace_months' => 0,
                'disbursement_date' => $disbursement->paid_on,
                'status' => 'active',
            ]);

            $lines = [[
                'user_id' => $application->member_id,
                'account_type' => 'loan_principal',
                'direction' => 'debit',
                'amount_minor' => $disbursement->principal_minor,
                'loan_id' => $loan->id,
            ]];

            if ($disbursement->interest_minor > 0) {
                $lines[] = [
                    'user_id' => $application->member_id,
                    'account_type' => 'loan_interest',
                    'direction' => 'debit',
                    'amount_minor' => $disbursement->interest_minor,
                    'loan_id' => $loan->id,
                ];
            }

            foreach ($disbursement->settlements as $settlement) {
                $lines[] = [
                    'user_id' => $application->member_id,
                    'account_type' => self::FUND_ACCOUNT[$settlement->fund_type],
                    'direction' => 'credit',
                    'amount_minor' => $settlement->amount_minor,
                    'loan_id' => $settlement->loan_id,
                ];
            }

            $cleared = $disbursement->settlements->pluck('loan_id')->unique()
                ->map(fn ($id) => "#{$id}")->implode(', ');

            $entry = $this->ledger->postEntry(
                societyId: $application->society_id,
                entryDate: $disbursement->paid_on,
                description: "Loan disbursement: {$product->name}" . ($cleared !== '' ? " (clears earlier loan {$cleared})" : ''),
                source: 'system',
                lines: $lines,
                createdBy: $actor->id,
                loanId: $loan->id,
            );

            foreach ($earlierLoans as $earlier) {
                $earlier->refresh();
                if ($earlier->isFullyRepaid()) {
                    $earlier->forceFill(['status' => 'terminated', 'settled_by_loan_id' => $loan->id])->save();
                }
            }

            $disbursement->update([
                'status' => 'posted',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'loan_id' => $loan->id,
                'ledger_entry_id' => $entry->id,
            ]);

            $application->update(['loan_id' => $loan->id]);

            return $this->approval->act($application, $actor, 'confirm', $notes);
        });
    }

    private function assertAtStep(LoanApplication $application, string $actionType, string $label): ApprovalStage
    {
        $stage = $application->currentStage;

        if ($application->status !== 'approved' || !$stage || $stage->action_type !== $actionType) {
            throw new ApprovalActionException("This application is not at the {$label} step.");
        }

        return $stage;
    }

    private function assertCanAct(ApprovalStage $stage, User $actor): void
    {
        if (!$stage->canBeActedOnBy($actor)) {
            throw new ApprovalActionException('You do not hold the role/permission required for this stage.');
        }
    }

    /**
     * Each line must be one of this member's running loans and no more than that
     * loan still owes on that fund.
     *
     * @return array<int, array{loan_id:int, fund_type:string, amount_minor:int, balance_at_preparation_minor:int}>
     */
    private function validatedSettlements(LoanApplication $application, array $settlements): array
    {
        $rows = [];
        $seen = [];

        foreach ($settlements as $item) {
            $loanId = (int) ($item['loan_id'] ?? 0);
            $fund = (string) ($item['fund_type'] ?? '');
            $amount = (int) ($item['amount_minor'] ?? 0);

            if (!isset(self::FUND_ACCOUNT[$fund])) {
                throw new ApprovalActionException("Unknown fund type '{$fund}'.");
            }
            if ($amount <= 0) {
                throw new ApprovalActionException('Each amount cleared must be more than zero.');
            }

            $key = $loanId . ':' . $fund;
            if (isset($seen[$key])) {
                throw new ApprovalActionException('The same item was listed twice.');
            }
            $seen[$key] = true;

            $loan = Loan::query()->lockForUpdate()
                ->where('id', $loanId)
                ->where('society_id', $application->society_id)
                ->where('member_id', $application->member_id)
                ->where('status', 'active')
                ->first();

            if (!$loan) {
                throw new ApprovalActionException("Loan #{$loanId} is not one of this member's running loans.");
            }

            $remaining = $loan->remainingMinor($fund);
            if ($amount > $remaining) {
                throw new ApprovalActionException(
                    "Loan #{$loan->id} only has " . number_format($remaining / 100, 2) . " outstanding on {$fund}, so "
                    . number_format($amount / 100, 2) . ' cannot be cleared.'
                );
            }

            $rows[] = [
                'loan_id' => $loan->id,
                'fund_type' => $fund,
                'amount_minor' => $amount,
                'balance_at_preparation_minor' => $remaining,
            ];
        }

        return $rows;
    }
}