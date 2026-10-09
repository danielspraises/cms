<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalActionException;
use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Models\LoanGuarantor;
use App\Services\LoanApprovalService;
use Illuminate\Http\Request;

class LoanApplicationController extends Controller
{
    public function __construct(private LoanApprovalService $approval) {}

    public function show(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);
        $application->load(['member', 'product', 'guarantors.guarantor', 'actions.actor', 'currentStage']);

        // Tells the screen whether THIS user can act right now, using the same
        // checks act() enforces, so the UI never has to guess.
        $user = $request->user();
        $stage = $application->currentStage;
        $actionable = in_array($application->status, ['in_review', 'approved'], true);

        $application->setAttribute('can_act', $actionable
            && $stage !== null
            && $stage->canBeActedOnBy($user)
            && ($stage->action_type === 'disburse'
                || !in_array($user->id, $application->actorsWhoHaveActed(), true)));

        return $application;
    }

    /**
     * Lists every loan application for the society. myQueue() only shows
     * applications awaiting THIS user's action; this lets an admin browse
     * everything regardless of whose turn it is.
     */
    public function index(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $query = LoanApplication::where('society_id', $societyId)
            ->with(['member:id,full_name,member_uid', 'product:id,name', 'currentStage:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate(25);
    }

    /** Member applies for a loan. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'loan_product_id' => 'required|exists:loan_products,id',
            'requested_principal_minor' => 'required|integer|min:1',
            'requested_tenure_months' => 'required|integer|min:1',
            'purpose' => 'nullable|string',
            'guarantor_member_ids' => 'array',
            'guarantor_member_ids.*' => 'exists:users,id',
        ]);

        $application = $this->approval->submit(
            $request->user(),
            $validated['loan_product_id'],
            $validated['requested_principal_minor'],
            $validated['requested_tenure_months'],
            $validated['purpose'] ?? null,
        );

        foreach ($validated['guarantor_member_ids'] ?? [] as $guarantorId) {
            LoanGuarantor::create([
                'loan_application_id' => $application->id,
                'guarantor_id' => $guarantorId,
                'status' => 'pending',
            ]);
        }

        return response()->json($application->load('guarantors'), 201);
    }

    public function guarantorRespond(Request $request, LoanApplication $application)
    {
        $validated = $request->validate(['accept' => 'required|boolean']);

        try {
            $updated = $this->approval->guarantorRespond($application, $request->user(), $validated['accept']);
        } catch (ApprovalActionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $updated;
    }

    /** The core action endpoint: recommend / approve / reject / disburse, per current stage. */
    public function act(Request $request, LoanApplication $application)
    {
        abort_unless($request->user()->canActOnSociety($application->society_id), 404);

        $validated = $request->validate([
            'decision' => 'required|in:recommend,approve,reject,disburse',
            'notes' => 'nullable|string',
        ]);

        try {
            $updated = $this->approval->act($application, $request->user(), $validated['decision'], $validated['notes'] ?? null);
        } catch (ApprovalActionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $updated->load('actions.actor');
    }

    public function resubmit(Request $request, LoanApplication $application)
    {
        abort_unless($application->member_id === $request->user()->id, 403);

        $validated = $request->validate([
            'requested_principal_minor' => 'required|integer|min:1',
            'requested_tenure_months' => 'required|integer|min:1',
            'purpose' => 'nullable|string',
        ]);

        try {
            $updated = $this->approval->resubmit(
                $application,
                $validated['requested_principal_minor'],
                $validated['requested_tenure_months'],
                $validated['purpose'] ?? null,
            );
        } catch (ApprovalActionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $updated;
    }

    /** Applications currently awaiting THIS user's action, across whatever stage they're eligible for. */
    public function myQueue(Request $request)
    {
        $user = $request->user();

        return LoanApplication::where('society_id', $request->attributes->get('society_id'))
            ->where('status', 'in_review')
            ->with('currentStage')
            ->get()
            ->filter(fn (LoanApplication $app) => $app->currentStage?->canBeActedOnBy($user)
                && !in_array($user->id, $app->actorsWhoHaveActed(), true))
            ->values();
    }
}