<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalActionException;
use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Services\DisbursementService;
use Illuminate\Http\Request;

/**
 * The settlement sheet, payout record and cross-check. Who may do each part is
 * decided by the approval workflow (the step's role), not here.
 */
class DisbursementController extends Controller
{
    public function __construct(private DisbursementService $disbursements) {}

    public function show(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);
        abort_unless($request->user()->hasPermission('loans.view'), 403);

        return response()->json($this->disbursements->sheet($application));
    }

    public function prepare(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);

        $validated = $request->validate([
            'settlements' => 'present|array',
            'settlements.*.loan_id' => 'required|integer',
            'settlements.*.fund_type' => 'required|in:principal,interest,sales',
            'settlements.*.amount_minor' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        return $this->respond($application, fn () => $this->disbursements->prepare(
            $application, $request->user(), $validated['settlements'], $validated['notes'] ?? null
        ));
    }

    public function pay(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);

        $validated = $request->validate([
            'paid_on' => 'required|date|before_or_equal:today',
            'payment_method' => 'required|in:bank_transfer,cash,cheque,other',
            'payment_reference' => 'nullable|string|max:100|required_unless:payment_method,cash',
            'notes' => 'nullable|string',
        ]);

        return $this->respond($application, fn () => $this->disbursements->recordPayout(
            $application,
            $request->user(),
            [
                'paid_on' => $validated['paid_on'],
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
            ],
            $validated['notes'] ?? null
        ));
    }

    public function confirm(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);

        $validated = $request->validate(['notes' => 'nullable|string']);

        return $this->respond($application, fn () => $this->disbursements->confirmAndPost(
            $application, $request->user(), $validated['notes'] ?? null
        ));
    }

    private function respond(LoanApplication $application, callable $action)
    {
        try {
            $action();
        } catch (ApprovalActionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->disbursements->sheet($application->fresh()));
    }
}