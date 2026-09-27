<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Society;
use App\Models\SocietySettings;
use Illuminate\Http\Request;

/**
 * Platform-admin-only — sets the entitlement ceiling from blueprint §9.3.
 * Deliberately separate from SocietySettingsController: settings are what a
 * society admin edits about their own operation; grants are what the
 * platform owner decides that society is even allowed to touch.
 */
class FeatureGrantController extends Controller
{
    public function show(Society $society)
    {
        $settings = $society->settings ?? SocietySettings::create(['society_id' => $society->id]);

        return [
            'gated_features' => SocietySettings::GATED_FEATURE_KEYS,
            'granted' => $settings->granted_features ?? [],
        ];
    }

    public function update(Request $request, Society $society)
    {
        $validated = $request->validate([
            'granted_features' => 'required|array',
            'granted_features.*' => 'in:' . implode(',', SocietySettings::GATED_FEATURE_KEYS),
        ]);

        $settings = SocietySettings::firstOrCreate(['society_id' => $society->id]);
        $before = $settings->granted_features;

        $settings->update([
            'granted_features' => $validated['granted_features'],
            'features_updated_by' => $request->user()->id,
            'features_updated_at' => now(),
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'feature_grants.update',
            entityType: 'society_settings',
            entityId: $settings->id,
            before: ['granted_features' => $before],
            after: ['granted_features' => $validated['granted_features']],
        );

        return $settings;
    }
}
