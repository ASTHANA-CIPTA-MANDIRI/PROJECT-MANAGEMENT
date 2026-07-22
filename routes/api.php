<?php

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SprintController;
use App\Http\Controllers\Api\V1\TicketCommentController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All endpoints are authenticated with Sanctum tokens and versioned under
| /api/v1. See public/docs/openapi.yaml for the full specification.
|
*/

// Internal-services endpoints: restricted to whitelisted IPs (see
// config/internal.php). Not token-authenticated; meant for health checks and
// monitoring from trusted hosts only.
Route::middleware('internal')->prefix('internal')->group(function () {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ]));
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    Route::prefix('v1')->group(function () {
        // Projects
        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);

        // Tickets nested under a project
        Route::get('projects/{project}/tickets', [TicketController::class, 'index']);
        Route::post('projects/{project}/tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);

        // Comments nested under a ticket
        Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
        Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

        // Sprints
        Route::get('sprints', [SprintController::class, 'index']);
        Route::post('sprints', [SprintController::class, 'store']);
        Route::get('sprints/{sprint}', [SprintController::class, 'show']);
    });
});
