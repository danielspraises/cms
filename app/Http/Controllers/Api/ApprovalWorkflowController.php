<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use Illuminate\Http\Request;

/**
 * Society-admin-facing workflow/stage builder — this is what makes §7 "fully
 * customizable" rather than fixed. A society can edit the seeded default
 * chain, or build entirely new (typically shorter) workflows for specific
 * loan types.
 */
class ApprovalWorkflowController extends Controller
{
    public function index(Request $request)
    {
        return ApprovalWorkflow::where('society_id', $request->attributes->get('society_id'))
            ->with('stages.assignmentRole')
            ->get();
    }

    public function store(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $validated = $request->validate([
            'name' => 'required|string',
            'stages' => 'required|array|min:1',
            'stages.*.name' => 'required|string',
            'stages.*.action_type' => 'required|in:recommend,approve,disburse',
            'stages.*.assignment_type' => 'required|in:role,permission',
            'stages.*.assignment_role_id' => 'required_if:stages.*.assignment_type,role|nullable|exists:roles,id',
            'stages.*.assignment_permission_key' => 'required_if:stages.*.assignment_type,permission|nullable|exists:permissions,key',
            'stages.*.notes_required' => 'sometimes|boolean',
        ]);

        // A chain must end in 'approve' or 'disburse' — a trailing
        // 'recommend' stage with nothing after it stalls every application
        // that reaches it (LoanApprovalService::act() fails loudly on this,
        // but better to reject it here before any application ever hits it).
        $lastStage = end($validated['stages']);
        if ($lastStage['action_type'] === 'recommend') {
            return response()->json(['message' => 'Workflow cannot end on a recommend stage.'], 422);
        }

        $workflow = ApprovalWorkflow::create(['society_id' => $societyId, 'name' => $validated['name']]);

        foreach ($validated['stages'] as $index => $stageData) {
            $workflow->stages()->create($stageData + ['sequence' => $index + 1]);
        }

        return response()->json($workflow->load('stages'), 201);
    }
}
