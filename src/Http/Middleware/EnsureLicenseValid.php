<?php

namespace Westel\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Westel\License\Contracts\LicenseServiceInterface;
use Westel\License\Exceptions\LicenseException;

class EnsureLicenseValid
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
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Log access attempt if configured
            $this->logAccessAttempt($request);

            // Check if license is valid
            if (!$this->licenseService->isValid()) {
                return $this->handleInvalidLicense($request);
            }

            // License is valid, proceed with request
            return $next($request);
        } catch (LicenseException $e) {
            // Log the exception
            $this->logException($e, $request);

            // Handle exception based on configuration
            return $this->handleException($e, $request);
        } catch (\Exception $e) {
            // Log unexpected exceptions
            $this->logException($e, $request);

            // Handle gracefully
            if (config('license.middleware.throw_exception', false)) {
                throw $e;
            }

            return $this->handleInvalidLicense($request);
        }
    }

    /**
     * Handle invalid license.
     *
     * @param Request $request
     * @return Response
     */
    protected function handleInvalidLicense(Request $request): Response
    {
        // Check if we should throw exception
        if (config('license.middleware.throw_exception', false)) {
            abort(403, 'License is invalid or expired');
        }

        // Get redirect route from config
        $redirectRoute = config('license.middleware.redirect_route', 'license.invalid');

        // Check if redirect route exists
        if ($redirectRoute && \Route::has($redirectRoute)) {
            return redirect()->route($redirectRoute);
        }

        // Fallback to 403 response
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'License invalid',
                'message' => 'Your license is invalid or has expired. Please contact support.',
            ], 403);
        }

        return response()->view('errors.403', [
            'message' => 'Your license is invalid or has expired. Please contact support.',
        ], 403);
    }

    /**
     * Handle license exception.
     *
     * @param LicenseException $exception
     * @param Request $request
     * @return Response
     */
    protected function handleException(LicenseException $exception, Request $request): Response
    {
        // Check if we should throw exception
        if (config('license.middleware.throw_exception', false)) {
            throw $exception;
        }

        // Return appropriate response based on request type
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'License error',
                'message' => $exception->getMessage(),
            ], 403);
        }

        // Get redirect route from config
        $redirectRoute = config('license.middleware.redirect_route', 'license.invalid');

        if ($redirectRoute && \Route::has($redirectRoute)) {
            return redirect()->route($redirectRoute)
                ->with('error', $exception->getMessage());
        }

        // Fallback to 403 response
        return response()->view('errors.403', [
            'message' => $exception->getMessage(),
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

        Log::channel($channel)->$level('License validation attempted', [
            'middleware' => 'EnsureLicenseValid',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
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

        Log::channel($channel)->error('License validation failed', [
            'middleware' => 'EnsureLicenseValid',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);
    }
}
