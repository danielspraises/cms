<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanProduct;
use Illuminate\Http\Request;

/**
 * Society-admin-facing loan type management (blueprint §7.5). "Loan type"
 * in the product/UI language maps to the LoanProduct model in code.
 */
class LoanTypeController extends Controller
{
    public function index(Request $request)
    {
        return LoanProduct::where('society_id', $request->attributes->get('society_id'))
            ->with('workflow.stages')
            ->get();
    }

    public function store(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:cash,sales',
            'interest_method' => 'required|in:flat,reducing_balance',
            'requires_guarantors' => 'required|boolean',
            'guarantors_required' => 'required_if:requires_guarantors,true|nullable|integer|min:1',
            'rejection_mode' => 'required|in:hard_reject,return_for_changes',
            'approval_workflow_id' => 'nullable|exists:approval_workflows,id',
        ]);

        // Default to the society's standard chain if none specified —
        // admin can build and assign a custom (e.g. shorter) workflow later.
        if (empty($validated['approval_workflow_id'])) {
            $default = \App\Models\ApprovalWorkflow::where('society_id', $societyId)
                ->where('is_default', true)->first();
            $validated['approval_workflow_id'] = $default?->id;
        }

        return LoanProduct::create($validated + ['society_id' => $societyId]);
    }

    public function update(Request $request, LoanProduct $loanType)
    {
        abort_unless($loanType->society_id === $request->attributes->get('society_id'), 404);

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'requires_guarantors' => 'sometimes|boolean',
            'guarantors_required' => 'sometimes|integer|min:1',
            'rejection_mode' => 'sometimes|in:hard_reject,return_for_changes',
            'approval_workflow_id' => 'sometimes|exists:approval_workflows,id',
        ]);

        $loanType->update($validated);

        return $loanType;
    }
}
