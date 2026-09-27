<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeductionChannel;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admin-facing activation/deactivation of a member's deduction channel —
 * blueprint §3: both CSV-eligibility and bank/card auto-debit require this
 * explicit step. Nothing here ever charges anything; that's DailyDeductionRunner.
 */
class DeductionChannelController extends Controller
{
    public function index(Request $request, User $member)
    {
        return $member->deductionChannels()->get();
    }

    public function store(Request $request, User $member)
    {
        $validated = $request->validate([
            'channel_type' => 'required|in:csv_upload,bank_debit,card_debit',
            'frequency' => 'required|in:monthly,weekly,custom',
            'day_of_period' => 'required_unless:frequency,custom|nullable|integer|min:1|max:31',
            'fixed_amount_minor' => 'nullable|integer|min:1',
            'provider' => 'required_if:channel_type,bank_debit,card_debit|nullable|string',
            'provider_mandate_ref' => 'required_if:channel_type,bank_debit,card_debit|nullable|string',
        ]);

        // A bank/card channel must have proof of member authorization
        // (a real mandate/authorization reference) before an admin can even
        // create it — the admin activates the schedule, but the *member*
        // must have already authorized the provider-side charge.
        $channel = DeductionChannel::updateOrCreate(
            ['member_id' => $member->id, 'channel_type' => $validated['channel_type']],
            $validated + [
                'society_id' => $request->attributes->get('society_id'),
                'provider_authorized_at' => in_array($validated['channel_type'], ['bank_debit', 'card_debit'])
                    ? now() : null,
                'is_active' => false, // still requires a separate explicit activation step
            ]
        );

        return response()->json($channel, 201);
    }

    public function activate(Request $request, DeductionChannel $channel)
    {
        abort_unless($request->user()->canActOnSociety($channel->society_id), 404);

        if (in_array($channel->channel_type, ['bank_debit', 'card_debit']) && !$channel->provider_mandate_ref) {
            return response()->json(['message' => 'Cannot activate without a valid provider mandate reference.'], 422);
        }

        $channel->update([
            'is_active' => true,
            'activated_by' => $request->user()->id,
            'activated_at' => now(),
            'deactivated_by' => null,
            'deactivated_at' => null,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'deduction_channel.activate',
            entityType: 'deduction_channel',
            entityId: $channel->id,
        );

        return $channel;
    }

    public function deactivate(Request $request, DeductionChannel $channel)
    {
        abort_unless($request->user()->canActOnSociety($channel->society_id), 404);

        $channel->update([
            'is_active' => false,
            'deactivated_by' => $request->user()->id,
            'deactivated_at' => now(),
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'deduction_channel.deactivate',
            entityType: 'deduction_channel',
            entityId: $channel->id,
        );

        return $channel;
    }
}
