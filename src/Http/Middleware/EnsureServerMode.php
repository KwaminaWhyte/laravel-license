<?php

namespace Westel\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureServerMode
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Log access attempt if configured
            $this->logAccessAttempt($request);

            // Check if package is running in server mode
            if (!$this->isServerMode()) {
                return $this->handleUnauthorized($request);
            }

            // Server mode confirmed, proceed with request
            return $next($request);
        } catch (\Exception $e) {
            // Log the exception
            $this->logException($e, $request);

            // Handle exception gracefully
            if (config('license.middleware.throw_exception', false)) {
                throw $e;
            }

            return $this->handleUnauthorized($request);
        }
    }

    /**
     * Check if package is running in server mode.
     *
     * @return bool
     */
    protected function isServerMode(): bool
    {
        $mode = config('license.mode', 'client');

        return strtolower($mode) === 'server';
    }

    /**
     * Handle unauthorized access (not in server mode).
     *
     * @param Request $request
     * @return Response
     */
    protected function handleUnauthorized(Request $request): Response
    {
        // Log the unauthorized access
        $this->logUnauthorizedAccess($request);

        // Check if we should throw exception
        if (config('license.middleware.throw_exception', false)) {
            abort(403, 'This resource is only available in server mode.');
        }

        // Return appropriate response based on request type
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Server mode required',
                'message' => 'This resource is only available when the package is running in server mode.',
            ], 403);
        }

        // Get redirect route from config
        $redirectRoute = config('license.middleware.redirect_route', 'license.invalid');

        // Check if redirect route exists
        if ($redirectRoute && \Route::has($redirectRoute)) {
            return redirect()->route($redirectRoute)
                ->with('error', 'This resource is only available in server mode.');
        }

        // Fallback to 403 response
        return response()->view('errors.403', [
            'message' => 'This resource is only available when the package is running in server mode.',
        ], 403);
    }

    /**
     * Log access attempt.
     *
     * @param Request $request
     * @return void
     */
    protected function logAccessAttempt(Request $request): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');
        $level = config('license.logging.level', 'info');

        Log::channel($channel)->$level('Server mode check', [
            'middleware' => 'EnsureServerMode',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'current_mode' => config('license.mode', 'client'),
        ]);
    }

    /**
     * Log unauthorized access attempt.
     *
     * @param Request $request
     * @return void
     */
    protected function logUnauthorizedAccess(Request $request): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->warning('Unauthorized server mode access attempt', [
            'middleware' => 'EnsureServerMode',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'current_mode' => config('license.mode', 'client'),
            'message' => 'Access denied. Package is not running in server mode.',
        ]);
    }

    /**
     * Log exception.
     *
     * @param \Exception $exception
     * @param Request $request
     * @return void
     */
    protected function logException(\Exception $exception, Request $request): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->error('Server mode check failed', [
            'middleware' => 'EnsureServerMode',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
            'current_mode' => config('license.mode', 'client'),
        ]);
    }
}
