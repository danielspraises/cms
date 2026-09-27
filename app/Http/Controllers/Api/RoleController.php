<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Society-admin-facing role management (blueprint §2). A society admin picks
 * FROM the fixed platform-wide permission catalog — they cannot invent new
 * permission keys, which is what keeps CheckPermission middleware meaningful.
 */
class RoleController extends Controller
{
    /** GET /permissions — full catalog grouped for a checkbox-group UI. */
    public function catalog()
    {
        return Permission::all()->groupBy('group');
    }

    public function index(Request $request)
    {
        return \App\Models\Role::where('society_id', $request->attributes->get('society_id'))
            ->with('permissions')
            ->withCount('users')
            ->get();
    }

    public function store(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'permission_ids' => 'required|array|min:1',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $slug = Str::slug($validated['name']);
        if (Role::where('society_id', $societyId)->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'A role with this name already exists.'], 422);
        }

        // Escalation guardrail (blueprint §2): granting staff.manage through a
        // custom role requires the requester to explicitly acknowledge it —
        // enforced here as a required companion flag, not just a UI nicety,
        // since the API could otherwise be called directly.
        $grantsStaffManage = Permission::whereIn('id', $validated['permission_ids'])
            ->where('key', 'staff.manage')->exists();

        if ($grantsStaffManage && !$request->boolean('acknowledge_staff_manage_grant')) {
            return response()->json([
                'message' => 'Granting staff.manage lets holders of this role create other users. ' .
                    'Resubmit with acknowledge_staff_manage_grant=true to confirm.',
            ], 422);
        }

        $role = Role::create([
            'society_id' => $societyId,
            'name' => $validated['name'],
            'slug' => $slug,
            'is_system' => false,
        ]);
        $role->permissions()->sync($validated['permission_ids']);

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'role.create',
            entityType: 'role',
            entityId: $role->id,
            after: ['name' => $role->name, 'permissions' => $role->permissions()->pluck('key')],
        );

        return response()->json($role->load('permissions'), 201);
    }

    public function update(Request $request, Role $role)
    {
        $societyId = $request->attributes->get('society_id');
        abort_unless($role->society_id === $societyId, 404);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $before = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('key')];

        if (isset($validated['permission_ids'])) {
            $grantsStaffManage = Permission::whereIn('id', $validated['permission_ids'])
                ->where('key', 'staff.manage')->exists();
            $currentlyHasIt = $role->permissions()->where('key', 'staff.manage')->exists();

            if ($grantsStaffManage && !$currentlyHasIt && !$request->boolean('acknowledge_staff_manage_grant')) {
                return response()->json([
                    'message' => 'Granting staff.manage lets holders of this role create other users. ' .
                        'Resubmit with acknowledge_staff_manage_grant=true to confirm.',
                ], 422);
            }

            $role->permissions()->sync($validated['permission_ids']);
        }

        if (isset($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'role.update',
            entityType: 'role',
            entityId: $role->id,
            before: $before,
            after: ['name' => $role->name, 'permissions' => $role->permissions()->pluck('key')],
        );

        return $role->load('permissions');
    }

    public function destroy(Request $request, Role $role)
    {
        $societyId = $request->attributes->get('society_id');
        abort_unless($role->society_id === $societyId, 404);
        abort_if($role->is_system, 422, 'System role templates cannot be deleted, only edited.');
        abort_if($role->users()->exists(), 422, 'Cannot delete a role that is still assigned to staff — reassign them first.');

        $role->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'role.delete',
            entityType: 'role',
            entityId: $role->id,
            before: ['name' => $role->name],
        );

        return response()->noContent();
    }
}
