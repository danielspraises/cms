<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Society-admin-facing: create staff accounts (auditors, loan officers,
 * bookkeepers, or custom roles) within their own society and assign
 * permissions via roles. This is what backs "the society admin should be
 * able to create users and tie roles and assign permission to each user."
 *
 * Deliberately does NOT let a society admin create another society_admin —
 * that stays a platform-admin-only action via PlatformSocietyController,
 * so a compromised society_admin account can't mint itself co-admins.
 */
class SocietyStaffController extends Controller
{
    public function index(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        return User::where('society_id', $societyId)
            ->where('role', 'staff')
            ->with('roles')
            ->get();
    }

    public function store(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $validated = $request->validate([
            'full_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        // Every assigned role must belong to this society (or be a system
        // template, but templates are cloned per-society at provisioning
        // time — see PlatformSocietyController::store — so in practice this
        // should always be society-scoped roles).
        $roles = Role::whereIn('id', $validated['role_ids'])
            ->where('society_id', $societyId)
            ->get();

        abort_if($roles->count() !== count($validated['role_ids']), 422, 'One or more roles do not belong to this society.');

        $staff = User::create([
            'society_id' => $societyId,
            'role' => 'staff',
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => 'active',
        ]);

        $staff->roles()->sync(
            $roles->mapWithKeys(fn ($role) => [$role->id => ['assigned_by' => $request->user()->id, 'assigned_at' => now()]])
        );

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'staff.create',
            entityType: 'user',
            entityId: $staff->id,
            after: ['full_name' => $staff->full_name, 'email' => $staff->email, 'roles' => $roles->pluck('slug')],
        );

        return response()->json($staff->load('roles')->makeHidden('password'), 201);
    }

    public function updateRoles(Request $request, User $staff)
    {
        $societyId = $request->attributes->get('society_id');
        abort_unless($staff->society_id === $societyId && $staff->role === 'staff', 404);

        $validated = $request->validate([
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $roles = Role::whereIn('id', $validated['role_ids'])->where('society_id', $societyId)->get();
        $before = $staff->roles->pluck('slug');

        $staff->roles()->sync(
            $roles->mapWithKeys(fn ($role) => [$role->id => ['assigned_by' => $request->user()->id, 'assigned_at' => now()]])
        );

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'staff.roles_updated',
            entityType: 'user',
            entityId: $staff->id,
            before: ['roles' => $before],
            after: ['roles' => $roles->pluck('slug')],
        );

        return $staff->load('roles');
    }
}
