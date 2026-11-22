<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlanningController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\VaultController;
use App\Http\Controllers\Api\WealthController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// ---------------- Health, auth and public token routes ----------------
Route::get('/health', [PublicController::class, 'health']);

Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/login', [AuthController::class, 'login']);
    Route::get('/auth/callback', [AuthController::class, 'callback']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/dev-login', [AuthController::class, 'devForm']);
    Route::post('/auth/dev-login', [AuthController::class, 'devLogin']);
    Route::get('/invite/{token}', [AuthController::class, 'invite'])->where('token', '[A-Za-z0-9]{48}');
});

Route::middleware('throttle:public')->group(function () {
    Route::get('/x/{token}', [PublicController::class, 'export'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('/x/{token}', [PublicController::class, 'exportVerify'])->where('token', '[A-Za-z0-9]{48}');
});
Route::get('/d/{token}', [PublicController::class, 'download'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:api');

// ---------------- OAuth (MCP delegation) and MCP ----------------
Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
Route::middleware('throttle:oauth')->group(function () {
    Route::post('/oauth/register', [OAuthController::class, 'register']);
    Route::post('/oauth/token', [OAuthController::class, 'token']);
    Route::post('/oauth/revoke', [OAuthController::class, 'revoke']);
});
Route::middleware('central')->group(function () {
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize']);
    Route::post('/oauth/authorize', [OAuthController::class, 'approve']);
    Route::get('/u/{token}', [IntakeController::class, 'show'])->where('token', '[A-Za-z0-9]{40}');
    Route::post('/u/{token}', [IntakeController::class, 'store'])->where('token', '[A-Za-z0-9]{40}');
});
Route::post('/mcp', [McpController::class, 'handle'])->middleware('throttle:mcp');
Route::get('/mcp', [McpController::class, 'stream']);

// ---------------- JSON API (session principals) ----------------
Route::prefix('api/v1')->middleware(['central', 'throttle:api', 'idempotent'])->group(function () use ($ulid) {
    Route::get('/me', [MeController::class, 'me']);
    Route::get('/meta', [MeController::class, 'meta']);
    Route::post('/workspaces', [MeController::class, 'createWorkspace']);
    Route::post('/stepup/enroll', [MeController::class, 'stepupEnroll']);
    Route::post('/stepup/verify', [MeController::class, 'stepupConfirm']);

    Route::prefix('workspaces/{workspace}')->where(['workspace' => $ulid])->middleware('workspace')->group(function () use ($ulid) {
        // Generic resources
        Route::get('/r/{type}', [ResourceController::class, 'index'])->where('type', '[a-z_]+');
        Route::post('/r/{type}', [ResourceController::class, 'store'])->where('type', '[a-z_]+');
        Route::get('/r/{type}/{id}', [ResourceController::class, 'show'])->where(['type' => '[a-z_]+', 'id' => $ulid]);
        Route::patch('/r/{type}/{id}', [ResourceController::class, 'update'])->where(['type' => '[a-z_]+', 'id' => $ulid]);
        Route::delete('/r/{type}/{id}', [ResourceController::class, 'destroy'])->where(['type' => '[a-z_]+', 'id' => $ulid]);

        // Access & governance of access
        Route::get('/access', [AccessController::class, 'overview']);
        Route::post('/access/invitations', [AccessController::class, 'invite']);
        Route::post('/access/members/{membership}/status', [AccessController::class, 'setStatus']);
        Route::post('/access/members/{membership}/role', [AccessController::class, 'setRole']);
        Route::post('/access/members/{membership}/person', [AccessController::class, 'linkPerson']);
        Route::post('/access/scopes', [AccessController::class, 'createScope']);
        Route::post('/access/scopes/{scope}/stewards', [AccessController::class, 'addSteward']);
        Route::post('/access/grants', [AccessController::class, 'grant']);
        Route::post('/access/grants/{grant}/approve', [AccessController::class, 'approveGrant']);
        Route::post('/access/grants/{grant}/revoke', [AccessController::class, 'revokeGrant']);
        Route::get('/access/audit', [AccessController::class, 'audit']);
        Route::get('/access/consents', [AccessController::class, 'consents']);
        Route::post('/access/consents', [AccessController::class, 'grantConsent']);
        Route::post('/access/consents/{consent}/revoke', [AccessController::class, 'revokeConsent']);
        Route::get('/access/sessions', [AccessController::class, 'sessions']);
        Route::post('/access/sessions/revoke-others', [AccessController::class, 'revokeOtherSessions']);
        Route::get('/access/connected-apps', [AccessController::class, 'oauthClients']);
        Route::post('/access/connected-apps/{client}/revoke', [AccessController::class, 'revokeOauthClient']);

        // Home, wealth, portfolio, equity, ledger
        Route::get('/home', [WealthController::class, 'home']);
        Route::get('/net-worth', [WealthController::class, 'netWorth']);
        Route::get('/portfolio', [WealthController::class, 'portfolio']);
        Route::get('/portfolio/performance/{account}', [WealthController::class, 'performance']);
        Route::get('/ownership/validation', [WealthController::class, 'ownershipValidation']);
        Route::get('/search', [WealthController::class, 'search']);
        Route::get('/links', [WealthController::class, 'links']);
        Route::post('/links', [WealthController::class, 'createLink']);
        Route::post('/equity/grants/{grant}/tranches', [WealthController::class, 'tranches']);
        Route::post('/equity/grants/{grant}/events', [WealthController::class, 'equityEvent']);
        Route::post('/equity/reconcile', [WealthController::class, 'equityReconcile']);
        Route::post('/equity/model', [WealthController::class, 'equityModel']);
        Route::post('/year-close', [WealthController::class, 'yearClose']);
        Route::post('/year-close/{snapshot}/close', [WealthController::class, 'closeYear']);
        Route::post('/year-close/{snapshot}/reopen', [WealthController::class, 'reopenYear']);
        Route::post('/ledger/periods/close', [WealthController::class, 'closePeriod']);
        Route::post('/ledger/periods/{close}/reopen', [WealthController::class, 'reopenPeriod']);

        // Real property records, secured loans, valuation review
        Route::post('/real-estate/lookup', [PropertyController::class, 'lookup']);
        Route::post('/properties/{property}/public-records', [PropertyController::class, 'applyPublicRecords']);
        Route::get('/collateral/{type}/{id}', [PropertyController::class, 'financing'])->where(['type' => 'property|asset', 'id' => $ulid]);
        Route::post('/properties/{property}/county-records', [PropertyController::class, 'countyRecords'])->where('property', $ulid);
        Route::get('/properties/{property}/comps', [PropertyController::class, 'comps'])->where('property', $ulid);
        Route::post('/estimates/{type}/{id}', [PropertyController::class, 'recordEstimate'])->where(['type' => 'property|asset', 'id' => $ulid]);
        Route::post('/valuations/{valuation}/decision', [PropertyController::class, 'decideValuation']);
        Route::post('/property-tax-installments/{installment}/pay', [PropertyController::class, 'payTaxInstallment'])->where('installment', $ulid);
        Route::post('/properties/{property}/tax-installments', [PropertyController::class, 'projectTaxes'])->where('property', $ulid);
        Route::get('/title/{type}/{id}', [PropertyController::class, 'title'])->where(['type' => 'property|asset|account|liability', 'id' => $ulid]);
        Route::put('/title/{type}/{id}', [PropertyController::class, 'setTitle'])->where(['type' => 'property|asset|account|liability', 'id' => $ulid]);

        // Vault, extraction, payroll, imports
        Route::post('/vault/documents', [VaultController::class, 'upload']);
        Route::get('/vault/documents/{document}/versions', [VaultController::class, 'versions']);
        Route::post('/vault/documents/{document}/download', [VaultController::class, 'download']);
        Route::post('/vault/documents/{document}/extract', [VaultController::class, 'extract']);
        Route::post('/vault/documents/{document}/release', [VaultController::class, 'releaseQuarantine']);
        Route::post('/vault/documents/{document}/legal-hold', [VaultController::class, 'legalHold']);
        Route::post('/vault/documents/{document}/purge', [VaultController::class, 'purge']);
        Route::post('/vault/extractions/{extraction}/approve-paystub', [VaultController::class, 'approvePaystub']);
        Route::post('/vault/extractions/{extraction}/reject', [VaultController::class, 'rejectExtraction']);
        Route::post('/imports', [VaultController::class, 'importUpload']);
        Route::get('/imports/{batch}', [VaultController::class, 'importShow']);
        Route::get('/imports/{batch}/rows', [VaultController::class, 'importRows']);
        Route::post('/imports/{batch}/rows/{row}', [VaultController::class, 'importResolve']);
        Route::post('/imports/{batch}/commit', [VaultController::class, 'importCommit']);
        Route::post('/imports/{batch}/reverse', [VaultController::class, 'importReverse']);
        Route::post('/import-profiles/{profile}/approve', [VaultController::class, 'approveProfile']);

        // MCP data-source integrations, provenance and source conflicts
        Route::get('/integrations', [IntegrationController::class, 'index']);
        Route::post('/integrations', [IntegrationController::class, 'store']);
        Route::get('/integrations/{connection}', [IntegrationController::class, 'show'])->where('connection', $ulid);
        Route::patch('/integrations/{connection}', [IntegrationController::class, 'update'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/key', [IntegrationController::class, 'key'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/oauth/start', [IntegrationController::class, 'oauthStart'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/oauth/complete', [IntegrationController::class, 'oauthComplete'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/test', [IntegrationController::class, 'test'])->where('connection', $ulid);
        Route::put('/integrations/{connection}/tools', [IntegrationController::class, 'tools'])->where('connection', $ulid);
        Route::put('/integrations/{connection}/account-map', [IntegrationController::class, 'accountMap'])->where('connection', $ulid);
        Route::get('/earnings/business-and-rentals', [IntegrationController::class, 'businessAndRentals']);
        Route::post('/integrations/{connection}/preview', [IntegrationController::class, 'preview'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/approve', [IntegrationController::class, 'approve'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/sync', [IntegrationController::class, 'sync'])->where('connection', $ulid);
        Route::post('/integrations/{connection}/disconnect', [IntegrationController::class, 'disconnect'])->where('connection', $ulid);
        Route::get('/accounts/{account}/sources', [IntegrationController::class, 'accountSources'])->where('account', $ulid);
        Route::post('/accounts/{account}/merge', [IntegrationController::class, 'merge'])->where('account', $ulid);
        Route::post('/accounts/{account}/authority', [IntegrationController::class, 'authority'])->where('account', $ulid);
        Route::post('/source-conflicts/{conflict}/resolve', [IntegrationController::class, 'resolveConflict'])->where('conflict', $ulid);

        // Tax
        Route::post('/tax/units/{unit}/runs', [TaxController::class, 'run']);
        Route::post('/tax/units/{unit}/strategies', [TaxController::class, 'strategies']);
        Route::get('/tax/units/{unit}/estimates', [TaxController::class, 'estimates']);
        Route::post('/tax/units/{unit}/estimates', [TaxController::class, 'estimates']);
        Route::get('/tax/runs/compare', [TaxController::class, 'compare']);
        Route::get('/tax/runs/{run}', [TaxController::class, 'show']);
        Route::get('/tax/runs/{run}/trace', [TaxController::class, 'trace']);
        Route::post('/tax/runs/{run}/review', [TaxController::class, 'review']);
        Route::post('/tax/runs/{run}/reconcile', [TaxController::class, 'reconcile']);
        Route::post('/tax/strategies/{strategy}/decision', [TaxController::class, 'decideStrategy']);
        Route::get('/tax/packs', [TaxController::class, 'packs']);
        Route::post('/tax/packs/{pack}/approve', [TaxController::class, 'approvePack']);

        // Planning & debt
        Route::post('/planning/scenarios/{scenario}/runs', [PlanningController::class, 'run']);
        Route::post('/planning/scenarios/{scenario}/branch', [PlanningController::class, 'branch']);
        Route::get('/planning/compare', [PlanningController::class, 'compare']);
        Route::get('/planning/runs/{run}', [PlanningController::class, 'show']);
        Route::get('/planning/spending-baseline', [PlanningController::class, 'spendingBaseline']);
        Route::post('/debts/compare', [PlanningController::class, 'debtCompare']);
        Route::post('/debts/{liability}/amortize', [PlanningController::class, 'amortize']);

        // Estate, insurance, governance
        Route::post('/estate/scenarios/{scenario}/runs', [RiskController::class, 'estateRun']);
        Route::get('/estate/runs/{run}', [RiskController::class, 'estateRunShow']);
        Route::get('/insurance/analysis', [RiskController::class, 'insuranceAnalysis']);
        Route::post('/insurance/loss-summary', [RiskController::class, 'lossSummary']);
        Route::get('/insurance/cases/{case}/compare', [RiskController::class, 'compareQuotes']);
        Route::get('/governance/prompts', [RiskController::class, 'prompts']);
        Route::get('/governance/break-glass', [RiskController::class, 'breakGlassList']);
        Route::post('/governance/binders/{binder}/break-glass', [RiskController::class, 'breakGlassRequest']);
        Route::post('/governance/break-glass/{request}/approve', [RiskController::class, 'breakGlassApprove']);
        Route::post('/governance/break-glass/{request}/deny', [RiskController::class, 'breakGlassDeny']);

        // Reports, exports, review, jobs, notifications, operations
        Route::post('/reports', [WorkflowController::class, 'createReport']);
        Route::post('/reports/{manifest}/download', [WorkflowController::class, 'reportDownload']);
        Route::post('/reports/{manifest}/exports', [WorkflowController::class, 'createExport']);
        Route::get('/exports', [WorkflowController::class, 'exports']);
        Route::post('/exports/{grant}/send', [WorkflowController::class, 'sendExport']);
        Route::post('/exports/{grant}/revoke', [WorkflowController::class, 'revokeExport']);
        Route::post('/review/{item}/resolve', [WorkflowController::class, 'resolveReview']);
        Route::get('/jobs', [WorkflowController::class, 'jobs']);
        Route::get('/jobs/{job}', [WorkflowController::class, 'job']);
        Route::post('/jobs/{job}/cancel', [WorkflowController::class, 'cancelJob']);
        Route::post('/jobs/{job}/retry', [WorkflowController::class, 'retryJob']);
        Route::get('/notifications', [WorkflowController::class, 'notifications']);
        Route::post('/notifications/{notification}', [WorkflowController::class, 'markNotification']);
        Route::get('/notification-preferences', [WorkflowController::class, 'preferences']);
        Route::put('/notification-preferences', [WorkflowController::class, 'preferences']);
        Route::post('/deliveries/{delivery}/resend', [WorkflowController::class, 'resendDelivery']);
        Route::get('/operations', [WorkflowController::class, 'operations']);
    });

    Route::any('/{any}', fn () => response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404))->where('any', '.*');
});

// ---------------- Authenticated browser shell (React) ----------------
Route::get('/{any?}', [PublicController::class, 'shell'])->where('any', '^(?!api/|mcp|oauth/|auth/|health|build/|\.well-known/).*$')->middleware('central');
