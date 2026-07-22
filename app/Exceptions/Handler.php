<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // Report unhandled exceptions to Sentry, tagged with the current user
        // so errors can be traced back to who hit them. No-op until a DSN is
        // configured (SENTRY_LARAVEL_DSN), so dev/test stay silent.
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                Integration::configureScope(function (Scope $scope): void {
                    if ($user = auth()->user()) {
                        $scope->setUser([
                            'id' => $user->getAuthIdentifier(),
                            'email' => $user->email ?? null,
                            'username' => $user->name ?? null,
                        ]);
                    }
                });

                Integration::captureUnhandledException($e);
            }
        });

        // Render every API exception as a consistent JSON envelope.
        $this->renderable(function (Throwable $e, Request $request) {
            if ($this->isApiRequest($request)) {
                return $this->renderApiException($e);
            }

            return null;
        });
    }

    /**
     * Whether the request targets the JSON API.
     *
     * Scoped to the /api/* prefix only: matching on expectsJson() as well would
     * hijack Livewire/Filament error responses, which also negotiate JSON.
     */
    protected function isApiRequest(Request $request): bool
    {
        return $request->is('api/*');
    }

    /**
     * Build a uniform JSON error response for the given exception.
     */
    protected function renderApiException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($e instanceof AuthorizationException) {
            return response()->json([
                'message' => $e->getMessage() ?: 'This action is unauthorized.',
            ], 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return response()->json([
                'message' => $e->getMessage() ?: $this->statusMessage($status),
            ], $status);
        }

        // Unexpected server error: hide details unless debugging.
        return response()->json(array_merge(
            ['message' => 'Server error.'],
            config('app.debug') ? [
                'exception' => get_class($e),
                'detail' => $e->getMessage(),
            ] : []
        ), 500);
    }

    /**
     * Default human message for a bare HTTP status code.
     */
    protected function statusMessage(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }
}
