<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\Society;
use App\Models\SocietySubscription;
use App\Services\BillingService;
use Illuminate\Http\Request;

/**
 * Platform-admin only. Backs the "Billing" screen: manage plans, assign a
 * plan (or a fully custom cycle/amount) to a society, view/generate invoices.
 */
class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function plans()
    {
        return BillingPlan::where('is_active', true)->get();
    }

    public function storePlan(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'cadence' => 'required|in:monthly,quarterly,biannual,annual,custom',
            'custom_cycle_months' => 'required_if:cadence,custom|nullable|integer|min:1',
            'amount_minor' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
        ]);

        return BillingPlan::create($validated + ['currency' => $validated['currency'] ?? 'NGN']);
    }

    /**
     * Assign or update a society's subscription. Accepts either a
     * billing_plan_id (standard case) or explicit overrides (the "or here
     * anomaly... half a year or depending on what the super admin said"
     * one-off case) — both can be set together, override wins.
     */
    public function assignSubscription(Request $request, Society $society)
    {
        $validated = $request->validate([
            'billing_plan_id' => 'nullable|exists:billing_plans,id',
            'cycle_months_override' => 'nullable|integer|min:1',
            'amount_minor_override' => 'nullable|integer|min:0',
            'status' => 'nullable|in:trial,active,past_due,cancelled',
        ]);

        $subscription = SocietySubscription::updateOrCreate(
            ['society_id' => $society->id],
            $validated + ['status' => $validated['status'] ?? 'active'],
        );

        return $subscription->load('plan');
    }

    public function invoices(Society $society)
    {
        return $society->subscription?->invoices()->orderByDesc('created_at')->paginate(25)
            ?? response()->json(['data' => []]);
    }

        /**
     * Society-scoped, read-only — this is what a society_admin/staff
     * actually calls to see their OWN subscription, unlike the
     * platform-only assignSubscription() which changes it. Reached via
     * /societies/{society}/billing/subscription, not /platform/....
     */
    public function subscriptionShow(Society $society)
    {
        $subscription = $society->subscription()->with('plan')->first();

        if (!$subscription) {
            return response()->json(['message' => 'No subscription configured for this society yet.'], 404);
        }

        return $subscription;
    }

    public function generateInvoiceNow(Society $society)
    {
        $subscription = $society->subscription;
        abort_unless($subscription, 404, 'Society has no subscription configured.');

        return $this->billing->generateInvoice($subscription);
    }
}
