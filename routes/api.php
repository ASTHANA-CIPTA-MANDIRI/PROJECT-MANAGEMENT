<?php

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SearchController;
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
        // The caller's own API tokens. Issuing one needs a first-party session
        // (see ApiTokenController), so a token cannot renew itself past the
        // expiry window; listing and revoking work with either.
        Route::get('tokens', [ApiTokenController::class, 'index']);
        Route::post('tokens', [ApiTokenController::class, 'store']);
        Route::delete('tokens/{token}', [ApiTokenController::class, 'destroy']);

        // Full-text search across projects, tickets and comments
        Route::get('search', SearchController::class);

        // Projects. PUT replaces a resource, PATCH updates only the fields it
        // carries; both land on the same controller action.
        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::match(['put', 'patch'], 'projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);

        // Tickets nested under a project; a single ticket is addressed on its
        // own, since it cannot move to another project anyway.
        Route::get('projects/{project}/tickets', [TicketController::class, 'index']);
        Route::post('projects/{project}/tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update']);
        Route::delete('tickets/{ticket}', [TicketController::class, 'destroy']);

        // Comments nested under a ticket, likewise addressed on their own once
        // they exist.
        Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
        Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);
        Route::match(['put', 'patch'], 'comments/{comment}', [TicketCommentController::class, 'update']);
        Route::delete('comments/{comment}', [TicketCommentController::class, 'destroy']);

        // Sprints
        Route::get('sprints', [SprintController::class, 'index']);
        Route::post('sprints', [SprintController::class, 'store']);
        Route::get('sprints/{sprint}', [SprintController::class, 'show']);
        Route::match(['put', 'patch'], 'sprints/{sprint}', [SprintController::class, 'update']);
        Route::delete('sprints/{sprint}', [SprintController::class, 'destroy']);
    });
});
