<?php

namespace Westel\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Westel\License\Contracts\LicenseServiceInterface;

class CheckFeatureAccess
{
    /**
     * The license service instance.
     *
     * @var LicenseServiceInterface
     */
    protected LicenseServiceInterface $licenseService;

    /**
     * Create a new middleware instance.
     *
     * @param LicenseServiceInterface $licenseService
     */
    public function __construct(LicenseServiceInterface $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $featureKey
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $featureKey = null): Response
    {
        // Validate feature key parameter
        if (empty($featureKey)) {
            // Log missing feature key
            $this->logError('Feature key not provided', $request);

            return $this->handleUnauthorized($request, 'Feature key not specified');
        }

        try {
            // Log access attempt if configured
            $this->logAccessAttempt($request, $featureKey);

            // Check if license has access to the feature
            if (!$this->licenseService->hasFeature($featureKey)) {
                return $this->handleUnauthorized($request, $featureKey);
            }

            // Check if feature can be used (with limit validation)
            if (!$this->licenseService->canUseFeature($featureKey)) {
                return $this->handleFeatureLimitExceeded($request, $featureKey);
            }

            // Feature access granted, proceed with request
            return $next($request);
        } catch (\Exception $e) {
            // Log the exception
            $this->logException($e, $request, $featureKey);

            // Handle exception gracefully
            if (config('license.middleware.throw_exception', false)) {
                throw $e;
            }

            return $this->handleUnauthorized($request, $featureKey);
        }
    }

    /**
     * Handle unauthorized feature access.
     *
     * @param Request $request
     * @param string $featureKey
     * @return Response
     */
    protected function handleUnauthorized(Request $request, string $featureKey): Response
    {
        // Log the unauthorized access
        $this->logUnauthorizedAccess($request, $featureKey);

        // Check if we should throw exception
        if (config('license.middleware.throw_exception', false)) {
            abort(403, "Access denied. Feature '{$featureKey}' is not available in your license.");
        }

        // Get redirect route from config
        $redirectRoute = config('license.middleware.redirect_route', 'license.invalid');

        // Return appropriate response based on request type
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Feature not available',
                'message' => "Access denied. The feature '{$featureKey}' is not available in your current license.",
                'feature' => $featureKey,
            ], 403);
        }

        // Check if redirect route exists
        if ($redirectRoute && \Route::has($redirectRoute)) {
            return redirect()->route($redirectRoute)
                ->with('error', "Access denied. The feature '{$featureKey}' is not available in your current license.");
        }

        // Fallback to 403 response
        return response()->view('errors.403', [
            'message' => "Access denied. The feature '{$featureKey}' is not available in your current license.",
        ], 403);
    }

    /**
     * Handle feature limit exceeded.
     *
     * @param Request $request
     * @param string $featureKey
     * @return Response
     */
    protected function handleFeatureLimitExceeded(Request $request, string $featureKey): Response
    {
        // Log the limit exceeded event
        $this->logFeatureLimitExceeded($request, $featureKey);

        // Check if we should throw exception
        if (config('license.middleware.throw_exception', false)) {
            abort(403, "Feature limit exceeded for '{$featureKey}'.");
        }

        // Get redirect route from config
        $redirectRoute = config('license.middleware.redirect_route', 'license.invalid');

        // Return appropriate response based on request type
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Feature limit exceeded',
                'message' => "You have exceeded the usage limit for the feature '{$featureKey}'.",
                'feature' => $featureKey,
            ], 403);
        }

        // Check if redirect route exists
        if ($redirectRoute && \Route::has($redirectRoute)) {
            return redirect()->route($redirectRoute)
                ->with('error', "You have exceeded the usage limit for the feature '{$featureKey}'.");
        }

        // Fallback to 403 response
        return response()->view('errors.403', [
            'message' => "You have exceeded the usage limit for the feature '{$featureKey}'.",
        ], 403);
    }

    /**
     * Log access attempt.
     *
     * @param Request $request
     * @param string $featureKey
     * @return void
     */
    protected function logAccessAttempt(Request $request, string $featureKey): void
    {
        // Check if feature check logging is enabled
        if (!config('license.logging.log_feature_checks', false)) {
            return;
        }

        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');
        $level = config('license.logging.level', 'info');

        Log::channel($channel)->$level('Feature access check', [
            'middleware' => 'CheckFeatureAccess',
            'feature' => $featureKey,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Log unauthorized access attempt.
     *
     * @param Request $request
     * @param string $featureKey
     * @return void
     */
    protected function logUnauthorizedAccess(Request $request, string $featureKey): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->warning('Unauthorized feature access attempt', [
            'middleware' => 'CheckFeatureAccess',
            'feature' => $featureKey,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Log feature limit exceeded.
     *
     * @param Request $request
     * @param string $featureKey
     * @return void
     */
    protected function logFeatureLimitExceeded(Request $request, string $featureKey): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->warning('Feature limit exceeded', [
            'middleware' => 'CheckFeatureAccess',
            'feature' => $featureKey,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Log exception.
     *
     * @param \Exception $exception
     * @param Request $request
     * @param string $featureKey
     * @return void
     */
    protected function logException(\Exception $exception, Request $request, string $featureKey): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->error('Feature access check failed', [
            'middleware' => 'CheckFeatureAccess',
            'feature' => $featureKey,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);
    }

    /**
     * Log error.
     *
     * @param string $message
     * @param Request $request
     * @return void
     */
    protected function logError(string $message, Request $request): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->error($message, [
            'middleware' => 'CheckFeatureAccess',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
    }
}
