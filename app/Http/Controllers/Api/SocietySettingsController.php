<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Society;
use App\Models\SocietySettings;
use Illuminate\Http\Request;

/**
 * Backs the "Society Settings" admin screen (blueprint §1). Society admin
 * edits their own; platform admin can edit any society's via the same
 * controller reached through /platform/societies/{id}/settings.
 */
class SocietySettingsController extends Controller
{
    private const VALID_PRIORITY_KEYS = ['sales', 'loan_interest', 'loan_principal', 'shares', 'savings'];

    public function show(Society $society)
    {
        return $society->settings ?? SocietySettings::create([
            'society_id' => $society->id,
            'deduction_priority_order' => SocietySettings::DEFAULT_PRIORITY_ORDER,
        ]);
    }

    public function update(Request $request, Society $society)
    {
        $validated = $request->validate([
            'deduction_priority_order' => 'sometimes|array',
            'deduction_priority_order.*' => 'in:' . implode(',', self::VALID_PRIORITY_KEYS),
            'due_calculation_model' => 'sometimes|in:total_due,monthly_due',
            'default_grace_months' => 'sometimes|integer|min:0',
            'default_interest_method' => 'sometimes|string',
            'late_fee_enabled' => 'sometimes|boolean',
            'late_fee_type' => 'sometimes|nullable|in:flat,percent_of_due',
            'late_fee_amount_minor' => 'sometimes|integer|min:0',
            'late_fee_percent_bp' => 'sometimes|integer|min:0|max:10000',
            'features' => 'sometimes|array',
            'terminology' => 'sometimes|array',
            'currency' => 'sometimes|string|size:3',
        ]);

        // Every configured priority key must be one of the known fund types,
        // AND every fund type should be represented — otherwise money could
        // silently have nowhere configured to go (mitigated in the allocator
        // itself too, but validate at the source of truth as well).
        if (isset($validated['deduction_priority_order'])) {
            $missing = array_diff(self::VALID_PRIORITY_KEYS, $validated['deduction_priority_order']);
            if (!empty($missing)) {
                return response()->json([
                    'message' => 'Priority order must include every fund type.',
                    'missing' => array_values($missing),
                ], 422);
            }
        }

        $settings = SocietySettings::firstOrNew(['society_id' => $society->id]);

        // Entitlement ceiling (blueprint §9.3): a society admin cannot
        // enable a gated feature the platform admin hasn't granted this
        // society. A platform admin acting via society mode IS allowed to
        // flip these directly, since they're the one who could also grant
        // it — this check only constrains society_admin/staff.
        if (isset($validated['features']) && $request->user()->role !== 'platform_admin') {
            foreach ($validated['features'] as $key => $value) {
                if ($value === true && !$settings->isFeatureGranted($key)) {
                    return response()->json([
                        'message' => "The '{$key}' feature has not been granted to this society by the platform admin.",
                    ], 403);
                }
            }
        }
        $before = $settings->exists ? $settings->toArray() : null;

        $settings->fill($validated);
        $settings->society_id = $society->id;
        $settings->updated_by = $request->user()->id;
        $settings->save();

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'society_settings.update',
            entityType: 'society_settings',
            entityId: $settings->id,
            before: $before,
            after: $settings->toArray(),
        );

        return $settings;
    }
}
