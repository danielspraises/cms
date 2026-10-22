<?php

use App\Http\Controllers\Api\ApprovalWorkflowController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BrandThemeController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DeductionChannelController;
use App\Http\Controllers\Api\FeatureGrantController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\LoanApplicationController;
use App\Http\Controllers\Api\LoanTypeController;
use App\Http\Controllers\Api\MemberAccountController;
use App\Http\Controllers\Api\PlatformSocietyController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SocietySettingsController;
use App\Http\Controllers\Api\SocietyStaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PLATFORM-ONLY — no society context, or platform-wide resources
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/auth/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->get('/auth/me', [\App\Http\Controllers\Api\AuthController::class, 'me']);




Route::middleware(['auth:sanctum', 'can:actAsPlatformAdmin'])->prefix('platform')->group(function () {
    Route::get('/societies', [PlatformSocietyController::class, 'index']);
    Route::post('/societies', [PlatformSocietyController::class, 'store']);
    Route::patch('/societies/{society}', [PlatformSocietyController::class, 'update']);

    Route::get('/societies/{society}/admins', [PlatformSocietyController::class, 'admins']);
    Route::post('/societies/{society}/admins', [PlatformSocietyController::class, 'addAdmin']);
    Route::delete('/societies/{society}/admins/{user}', [PlatformSocietyController::class, 'removeAdmin']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/plans', [BillingController::class, 'storePlan']);
    Route::post('/societies/{society}/subscription', [BillingController::class, 'assignSubscription']);
    Route::get('/societies/{society}/invoices', [BillingController::class, 'invoices']);
    Route::post('/societies/{society}/invoices/generate', [BillingController::class, 'generateInvoiceNow']);

    Route::get('/branding', [BrandThemeController::class, 'platformTheme']);
    Route::patch('/branding', [BrandThemeController::class, 'updatePlatformTheme']);

    // Feature entitlement ceiling (§9.3) — platform-only by definition.
    Route::get('/societies/{society}/feature-grants', [FeatureGrantController::class, 'show']);
    Route::patch('/societies/{society}/feature-grants', [FeatureGrantController::class, 'update']);

    // Cross-society analytics (§9.4) — controller to be built next pass.
    // Route::get('/analytics/overview', [PlatformAnalyticsController::class, 'overview']);
    // Route::get('/analytics/growth', [PlatformAnalyticsController::class, 'growth']);
});

/*
|--------------------------------------------------------------------------
| SOCIETY MODE — one consistent path shape for every admin/staff resource.
| A society_admin/staff may only ever pass THEIR OWN society here (enforced
| in EnsureSocietyScope); a platform_admin may pass ANY society (the
| society-switcher model, §9.1/§9.2) — same controllers, same screens.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'ensure.society.scope'])->prefix('societies/{society}')->group(function () {

    Route::middleware('permission:members.view')->group(function () {
        Route::get('/members', [\App\Http\Controllers\Api\MemberController::class, 'index']);
    });


    Route::middleware('permission:ledger.view')->group(function () {
        Route::get('/members/{uid}/ledger', [LedgerController::class, 'forMember']);
    });


    // Billing — society admin/staff can VIEW their own subscription and
    // invoices; only platform admin can CHANGE them (see /platform/... routes).
    Route::middleware('permission:billing.view')->group(function () {
        Route::get('/billing/subscription', [BillingController::class, 'subscriptionShow']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    });

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('/reports/audit-log', [ReportController::class, 'auditLog']);
    });

    Route::middleware('permission:comments.create')->group(function () {
        Route::get('/comments', [CommentController::class, 'index']);
        Route::post('/comments', [CommentController::class, 'store']);
    });

    Route::middleware('permission:staff.manage')->group(function () {
        Route::get('/staff', [SocietyStaffController::class, 'index']);
        Route::post('/staff', [SocietyStaffController::class, 'store']);
        Route::patch('/staff/{staff}/roles', [SocietyStaffController::class, 'updateRoles']);

        Route::get('/permissions', [RoleController::class, 'catalog']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

        Route::get('/settings', [SocietySettingsController::class, 'show']);
        Route::patch('/settings', [SocietySettingsController::class, 'update']);

        Route::get('/loan-types', [LoanTypeController::class, 'index']);
        Route::post('/loan-types', [LoanTypeController::class, 'store']);
        Route::patch('/loan-types/{loanType}', [LoanTypeController::class, 'update']);

        Route::get('/approval-workflows', [ApprovalWorkflowController::class, 'index']);
        Route::post('/approval-workflows', [ApprovalWorkflowController::class, 'store']);
    });

    Route::middleware('permission:branding.manage')->group(function () {
        Route::get('/branding', [BrandThemeController::class, 'show']);
        Route::patch('/branding', [BrandThemeController::class, 'update']);
    });

    Route::middleware('permission:deductions.manage')->group(function () {
        Route::get('/members/{member}/deduction-channels', [DeductionChannelController::class, 'index']);
        Route::post('/members/{member}/deduction-channels', [DeductionChannelController::class, 'store']);
    });

    Route::middleware('permission:loans.view')->group(function () {
        Route::get('/loan-applications', [LoanApplicationController::class, 'index']);
        Route::get('/loan-applications/my-queue', [LoanApplicationController::class, 'myQueue']);
    });

    Route::middleware('permission:members.manage')->group(function () {
        Route::get('/members/{member}/closure-summary', [MemberAccountController::class, 'closureSummary']);
        Route::post('/members/{member}/close', [MemberAccountController::class, 'close']);
    });
});

/*
|--------------------------------------------------------------------------
| RESOURCE-ACTION ROUTES — no {society} in the path; authorization checks
| against the resource's OWN society_id via User::canActOnSociety() inside
| the controller instead (see LedgerController::amend,
| LoanApplicationController::act, DeductionChannelController::activate for
| the pattern). Kept out of the society-mode prefix above because these
| operate on a specific existing resource, not "list/create within society X".
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    Route::patch('/ledger/entries/{entry}', [LedgerController::class, 'amend']);

    Route::post('/deduction-channels/{channel}/activate', [DeductionChannelController::class, 'activate']);
    Route::post('/deduction-channels/{channel}/deactivate', [DeductionChannelController::class, 'deactivate']);

    Route::get('/loan-applications/{application}', [LoanApplicationController::class, 'show']);
    Route::post('/loan-applications/{application}/act', [LoanApplicationController::class, 'act']);

    // Pure member self-service — no society-mode switching applies, a member
    // only ever acts as themselves.
    Route::post('/loan-applications', [LoanApplicationController::class, 'store']);
    Route::post('/loan-applications/{application}/guarantor-response', [LoanApplicationController::class, 'guarantorRespond']);
    Route::post('/loan-applications/{application}/resubmit', [LoanApplicationController::class, 'resubmit']);
});

// Paystack webhook — signature-verified, NOT behind auth:sanctum.
// Route::post('/payments/webhook/paystack', [PaymentWebhookController::class, 'handle']);
