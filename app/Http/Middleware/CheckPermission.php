<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes: ->middleware('permission:ledger.edit')
 *
 * This is what an auditor role failing to have 'ledger.edit' actually
 * enforces at the HTTP layer — the read-only-auditor requirement isn't just
 * a UI convention, it's blocked server-side before the controller runs.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permissionKey)) {
            abort(403, "Missing required permission: {$permissionKey}");
        }

        return $next($request);
    }
}
