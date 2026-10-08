<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RideAssignmentController;
use App\Http\Controllers\Api\V1\RideBoardController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\RideSeriesController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Support\Facades\Route;

/**
 * Tenant-scoped API. ResolveTenant binds the agency from X-Tenant (or the
 * subdomain); platform admins send X-Tenant: "" and operate on all tenants.
 */
Route::prefix('v1')->middleware(['tenant'])->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Rides, dispatch board, assignment, lifecycle
        Route::get('rides', [RideController::class, 'index']);
        Route::post('rides', [RideController::class, 'store']);
        Route::get('rides/board', [RideBoardController::class, 'index']);
        Route::get('rides/{ride}', [RideController::class, 'show']);
        Route::patch('rides/{ride}', [RideController::class, 'update']);
        Route::post('rides/{ride}/transition', [RideController::class, 'transition']);
        Route::post('rides/{ride}/assign', [RideAssignmentController::class, 'store']);
        Route::delete('rides/{ride}/assign', [RideAssignmentController::class, 'destroy']);
        Route::post('rides/{ride}/self-assign', [DriverController::class, 'selfAssign']);

        // Drivers
        Route::get('drivers', [DriverController::class, 'index']);
        Route::get('drivers/{driverProfile}', [DriverController::class, 'show']);
        Route::post('drivers', [DriverController::class, 'store']);
        Route::patch('drivers/{driverProfile}', [DriverController::class, 'update']);
        Route::delete('drivers/{driverProfile}', [DriverController::class, 'destroy']);
        Route::get('drivers/me/rides', [DriverController::class, 'myRides']);
        Route::put('drivers/availability', [DriverController::class, 'updateAvailability']);
        Route::put('drivers/me/home', [DriverController::class, 'updateHome']);
        Route::post('drivers/review/{driverProfile}', [DriverController::class, 'review']);

        // Tenant
        Route::get('tenant', [TenantController::class, 'show']);
        Route::patch('tenant/settings', [TenantController::class, 'updateSettings']);

        // Agencies list for driver registration
        Route::get('agencies', function () {
            $tenants = \App\Models\Tenant::with('settings')->orderBy('name')->get();
            return response()->json($tenants);
        });

        // Recurring ride series
        Route::get('ride-series', [RideSeriesController::class, 'index']);
        Route::get('ride-series/{seriesKey}', [RideSeriesController::class, 'show']);
        Route::patch('ride-series/{seriesKey}', [RideSeriesController::class, 'update']);
        Route::post('ride-series/{seriesKey}/cancel', [RideSeriesController::class, 'cancel']);
        Route::post('ride-series/{seriesKey}/shift', [RideSeriesController::class, 'shift']);

        // Reporting
        Route::get('reports/metrics', [ReportController::class, 'metrics']);
        Route::post('reports/exports', [ReportController::class, 'export']);
        Route::get('reports/exports/{reportExport}', [ReportController::class, 'show']);

        // Fleet users (invites, active toggles)
        Route::get('users', [UserController::class, 'index']);
        Route::post('users/invite', [UserController::class, 'invite']);
        Route::patch('users/{user}/active', [UserController::class, 'toggleActive']);

        // Messaging
        Route::get('messages', [MessageController::class, 'index']);
        Route::post('messages', [MessageController::class, 'store']);
        Route::post('messages/{message}/read', [MessageController::class, 'markRead']);
    });
});

// Platform administration — no tenant resolution, platform admins only.
Route::prefix('v1')->middleware(['auth:sanctum', RequirePlatformAdmin::class])->group(function () {
    Route::get('platform/tenants', [PlatformTenantController::class, 'index']);
    Route::post('platform/tenants', [PlatformTenantController::class, 'store']);
    Route::get('platform/tenants/{tenant}', [PlatformTenantController::class, 'show']);
    Route::patch('platform/tenants/{tenant}', [PlatformTenantController::class, 'update']);
    Route::patch('platform/tenants/{tenant}/active', [PlatformTenantController::class, 'toggleActive']);

    Route::get('platform/users', function () {
        return response()->json([
            'data' => \App\Models\User::where('is_platform_admin', true)->get(['id', 'name', 'email', 'is_active']),
        ]);
    });

    Route::post('platform/users', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $tenant = \App\Models\Tenant::first();
        $role = \App\Models\Role::firstOrCreate(
            ['tenant_id' => $tenant?->id, 'name' => 'platform-admin'],
            ['description' => 'Platform Administrator']
        );

        $user = \App\Models\User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_platform_admin' => true,
            'is_active' => true,
            'role_id' => $role->id,
        ]);

        return response()->json(['data' => $user], 201);
    });

    Route::patch('platform/users/{user}/active', function (\Illuminate\Http\Request $request, \App\Models\User $user) {
        // Never let a platform admin disable the account they're logged in as.
        if ($user->is_active && $user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return response()->json(['data' => $user->fresh()]);
    });

    // Cross-agency aggregates for the platform dashboard. No tenant is bound
    // here, so the BelongsToTenant scope is skipped and counts span all tenants.
    Route::get('platform/metrics', function () {
        return response()->json([
            'total_rides' => \App\Models\Ride::count(),
        ]);
    });
});
