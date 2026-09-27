<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Platform-admin (super admin) only. Backs the "Societies management" and
 * "Society admins management" screens.
 */
class PlatformSocietyController extends Controller
{
    public function __construct(private \App\Services\SocietyProvisioningService $provisioning) {}

    public function index()
    {
        return Society::withCount('members')->with(['subscription', 'brandTheme'])->paginate(25);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|alpha_dash|unique:societies,code',
            'currency' => 'nullable|string|size:3',
            'admin_full_name' => 'required|string',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
        ]);

        return DB::transaction(function () use ($validated) {
            $society = Society::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'currency' => $validated['currency'] ?? 'NGN',
            ]);

            // Provision the first society admin at creation time — matches
            // your requirement that only the super admin creates societies
            // and their admins, not a self-service signup flow.
            $admin = User::create([
                'society_id' => $society->id,
                'role' => 'society_admin',
                'full_name' => $validated['admin_full_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'status' => 'active',
            ]);

            // Clone system role templates AND seed the default 4-stage
            // approval workflow (blueprint §7.1) — one call, one place.
            $this->provisioning->provision($society);

            return response()->json([
                'society' => $society,
                'admin' => $admin->makeHidden('password'),
            ], 201);
        });
    }

    public function update(Request $request, Society $society)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'currency' => 'sometimes|string|size:3',
        ]);

        $society->update($validated);

        return $society;
    }

    /** List/add/remove admins for a specific society. */
    public function admins(Society $society)
    {
        return $society->admins()->get();
    }

    public function addAdmin(Request $request, Society $society)
    {
        $validated = $request->validate([
            'full_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $admin = User::create([
            'society_id' => $society->id,
            'role' => 'society_admin',
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => 'active',
        ]);

        return response()->json($admin->makeHidden('password'), 201);
    }

    public function removeAdmin(Society $society, User $user)
    {
        abort_unless($user->society_id === $society->id && $user->role === 'society_admin', 404);

        // Demote rather than delete — preserves history/audit trail. A society
        // must always retain at least one admin.
        if ($society->admins()->count() <= 1) {
            abort(422, 'Cannot remove the last remaining admin for a society.');
        }

        $user->update(['status' => 'suspended']);

        return response()->noContent();
    }
}
