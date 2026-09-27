<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two jobs:
 *  1. Basic gate — reject unauthenticated or inactive-status users, same as
 *     always.
 *  2. Resolve which society this request operates on, from the {society}
 *     route parameter when the route has one (Laravel resolves route model
 *     binding via SubstituteBindings, which runs before this middleware in
 *     the default 'api' middleware group, so $request->route('society') is
 *     already a Society instance here, not a raw ID).
 *
 * Blueprint §9.2: a platform_admin may pass ANY society via the route
 * parameter (the society-switcher model) — a society_admin/staff member may
 * only ever pass their own, checked against $user->society_id, anything
 * else is a 403. Routes without a {society} segment (member self-service
 * endpoints, resource-action routes that authorize against the resource's
 * own society_id instead — see LedgerController::amend,
 * LoanApplicationController::act, DeductionChannelController::activate for
 * that pattern) fall back to the user's own society_id as before.
 */
class EnsureSocietyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->status !== 'active') {
            abort(403, 'Access denied.');
        }

        // NOTE: $request->route('society') only returns a resolved Society
        // model automatically for controller methods that declare a typed
        // `Society $society` parameter — most controllers behind this
        // middleware deliberately don't (they read
        // $request->attributes->get('society_id') instead, so their
        // signatures didn't need to change for this restructure). For
        // everyone else, the route gives us the raw ID string, so we
        // resolve it explicitly here rather than assuming a bound model.
        $routeSocietyParam = $request->route('society');
        $routeSociety = $routeSocietyParam instanceof \App\Models\Society
            ? $routeSocietyParam
            : ($routeSocietyParam ? \App\Models\Society::find($routeSocietyParam) : null);

        if ($routeSocietyParam && !$routeSociety) {
            abort(404, 'Society not found.');
        }

        if ($user->role === 'platform_admin') {
            if ($routeSociety) {
                $request->attributes->set('society_id', $routeSociety->id);
            }
            // No route society and no own society_id (platform admins have
            // none) — that's fine, controllers for platform-only resources
            // (billing plans, society list itself) don't need one.
            return $next($request);
        }

        if (!$user->society_id) {
            abort(403, 'No society associated with this account.');
        }

        if ($routeSociety && $routeSociety->id !== $user->society_id) {
            abort(403, 'You do not have access to this society.');
        }

        $request->attributes->set('society_id', $user->society_id);

        return $next($request);
    }
}
