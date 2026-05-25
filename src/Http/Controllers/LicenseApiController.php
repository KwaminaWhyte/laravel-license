<?php

namespace Westel\License\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Westel\License\Models\License;
use Westel\License\Models\LicenseActivation;
use Westel\License\Models\LicenseValidation;
use Westel\License\Models\Product;
use Westel\License\Services\ServerLicenseService;
use Westel\License\Services\HardwareFingerprintService;

class LicenseApiController extends Controller
{
    protected ServerLicenseService $licenseService;
    protected HardwareFingerprintService $fingerprintService;

    public function __construct(
        ServerLicenseService $licenseService,
        HardwareFingerprintService $fingerprintService
    ) {
        $this->licenseService = $licenseService;
        $this->fingerprintService = $fingerprintService;

        // Apply rate limiting if enabled
        if (config('license.server.rate_limiting.enabled')) {
            $this->middleware('throttle:' .
                config('license.server.rate_limiting.max_attempts', 60) . ',' .
                config('license.server.rate_limiting.decay_minutes', 1)
            );
        }
    }

    /**
     * Validate license and generate offline token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string|min:20|max:50',
                'hardware_fingerprint' => 'required|string',
                'system_info' => 'array|nullable',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $this->logRequest('validate', $request, $validated);

            $result = $this->licenseService->validateLicense(
                $validated['license_key'],
                $validated['hardware_fingerprint'],
                $validated['system_info'] ?? null,
                $request
            );

            $status = $result['valid'] ? 200 : 400;
            return response()->json($result, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'validate');
        }
    }

    /**
     * Activate license on new hardware
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function activate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string',
                'hardware_fingerprint' => 'required|string',
                'system_info' => 'array|nullable',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $this->logRequest('activate', $request, $validated);

            $result = $this->licenseService->activateLicense(
                $validated['license_key'],
                $validated['hardware_fingerprint'],
                $validated['system_info'] ?? null
            );

            $status = $result['success'] ? 200 : 400;
            return response()->json($result, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'activate');
        }
    }

    /**
     * Deactivate license from specific hardware
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deactivate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string',
                'hardware_fingerprint' => 'required|string',
                'admin_user_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $this->logRequest('deactivate', $request, $validated);

            $result = $this->licenseService->deactivateLicense(
                $validated['license_key'],
                $validated['hardware_fingerprint']
            );

            if (!$result['success']) {
                $status = isset($result['error_code']) && $result['error_code'] === 'LICENSE_NOT_FOUND' ? 404 : 400;
                return response()->json($result, $status);
            }

            // Log the deactivation
            $license = License::where('license_key', $validated['license_key'])->first();
            if ($license) {
                LicenseValidation::logValidation(
                    $license->id,
                    $validated['hardware_fingerprint'],
                    'deactivation',
                    'success',
                    $request->ip(),
                    $request->userAgent(),
                    ['admin_user_id' => $validated['admin_user_id'] ?? null]
                );
            }

            return response()->json(array_merge($result, [
                'deactivated_at' => now()->toISOString(),
            ]), 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'deactivate');
        }
    }

    /**
     * Heartbeat validation for active licenses
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function heartbeat(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string',
                'hardware_fingerprint' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $this->logRequest('heartbeat', $request, $validated);

            $result = $this->licenseService->heartbeat(
                $validated['license_key'],
                $validated['hardware_fingerprint'],
                $request
            );

            $status = $result['valid'] ? 200 : 400;
            return response()->json($result, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'heartbeat');
        }
    }

    /**
     * Get license status (query params or headers)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $licenseKey = $request->query('license_key') ?? $request->header('X-License-Key');
            $hardwareFingerprint = $request->query('hardware_fingerprint') ?? $request->header('X-Hardware-Fingerprint');

            if (!$licenseKey || !$hardwareFingerprint) {
                return response()->json([
                    'valid' => false,
                    'error' => 'Missing license_key or hardware_fingerprint',
                    'error_code' => 'MISSING_PARAMETERS',
                ], 400);
            }

            $this->logRequest('status', $request, [
                'license_key' => $licenseKey,
                'hardware_fingerprint' => $hardwareFingerprint,
            ]);

            // Use heartbeat for status check
            $result = $this->licenseService->heartbeat(
                $licenseKey,
                $hardwareFingerprint,
                $request
            );

            $status = $result['valid'] ? 200 : 400;
            return response()->json($result, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'status');
        }
    }

    /**
     * Get license information by license key
     *
     * @param string $licenseKey
     * @return JsonResponse
     */
    public function info(string $licenseKey): JsonResponse
    {
        try {
            $this->logRequest('info', request(), ['license_key' => $licenseKey]);

            $result = $this->licenseService->getLicenseInfo($licenseKey);
            $status = $result['found'] ? 200 : 404;

            return response()->json($result, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'info');
        }
    }

    /**
     * Get available features for a license
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function features(Request $request): JsonResponse
    {
        try {
            $licenseKey = $request->query('license_key') ?? $request->header('X-License-Key');

            if (!$licenseKey) {
                return response()->json([
                    'error' => 'Missing license_key parameter',
                    'error_code' => 'MISSING_LICENSE_KEY',
                ], 400);
            }

            $this->logRequest('features', $request, ['license_key' => $licenseKey]);

            $license = License::with(['product'])->where('license_key', $licenseKey)->first();

            if (!$license) {
                return response()->json([
                    'error' => 'License not found',
                    'error_code' => 'LICENSE_NOT_FOUND',
                ], 404);
            }

            $this->licenseService->setLicense($license);
            $features = $this->licenseService->getFeatures();

            return response()->json([
                'license_key' => $licenseKey,
                'tier' => $license->product?->slug ?: \Illuminate\Support\Str::slug($license->product?->name ?? 'unknown'),
                'tier_label' => $license->product?->name ?? 'Unknown',
                'features' => $features,
                'features_count' => count($features),
                'license_status' => $license->status,
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'features');
        }
    }

    /**
     * Validate specific feature access
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateFeature(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string',
                'feature_key' => 'required|string',
                'current_usage' => 'integer|nullable',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $this->logRequest('validate-feature', $request, $validated);

            $license = License::with(['product'])->where('license_key', $validated['license_key'])->first();

            if (!$license) {
                return response()->json([
                    'allowed' => false,
                    'error' => 'License not found',
                    'error_code' => 'LICENSE_NOT_FOUND',
                ], 404);
            }

            if (!$license->isActive()) {
                return response()->json([
                    'allowed' => false,
                    'error' => 'License is not active',
                    'error_code' => 'LICENSE_INACTIVE',
                ], 403);
            }

            $this->licenseService->setLicense($license);
            $result = $this->licenseService->canUseFeature(
                $validated['feature_key'],
                $validated['current_usage'] ?? null
            );

            // Get detailed validation result
            $featureConfig = $this->licenseService->getFeatureConfig($validated['feature_key']);

            $response = [
                'allowed' => $result,
                'feature_key' => $validated['feature_key'],
            ];

            if ($featureConfig) {
                $response['feature_config'] = $featureConfig;
            } else {
                $response['error'] = 'Feature not available';
                $response['error_code'] = 'FEATURE_NOT_AVAILABLE';
            }

            $status = $result ? 200 : 403;
            return response()->json($response, $status);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'validate-feature');
        }
    }

    /**
     * Get available products/tiers
     *
     * @return JsonResponse
     */
    public function tiers(): JsonResponse
    {
        try {
            $this->logRequest('tiers', request(), []);

            $products = Product::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(function ($product) {
                    $tier = $product->slug ?: \Illuminate\Support\Str::slug($product->name);

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'version' => $product->version,
                        'description' => $product->description,
                        'tier' => $tier,
                        'tier_label' => $product->name,
                        'features' => $product->getFeaturesList(),
                        'structured_features' => $product->getStructuredFeatures(),
                        'features_count' => $product->features_count,
                        'default_activation_limit' => $product->default_activation_limit,
                        'grace_period_days' => $product->grace_period_days,
                        'offline_validation_days' => $product->offline_validation_days,
                    ];
                });

            return response()->json([
                'tiers' => $products,
                'total' => $products->count(),
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'tiers');
        }
    }

    /**
     * Get analytics for a license
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $licenseKey = $request->query('license_key');
            $hardwareFingerprint = $request->query('hardware_fingerprint');

            if (!$licenseKey) {
                return response()->json([
                    'error' => 'Missing license_key parameter',
                    'error_code' => 'MISSING_LICENSE_KEY',
                ], 400);
            }

            $this->logRequest('analytics', $request, [
                'license_key' => $licenseKey,
                'hardware_fingerprint' => $hardwareFingerprint,
            ]);

            $license = License::where('license_key', $licenseKey)->first();

            if (!$license) {
                return response()->json([
                    'error' => 'License not found',
                    'error_code' => 'LICENSE_NOT_FOUND',
                ], 404);
            }

            $dailySince = now()->subDay();
            $monthlySince = now()->subDays(30);

            $dailyRequests = LicenseValidation::where('license_id', $license->id)
                ->where('validated_at', '>=', $dailySince)
                ->count();

            $monthlyRequests = LicenseValidation::where('license_id', $license->id)
                ->where('validated_at', '>=', $monthlySince)
                ->count();

            $usersCountQuery = LicenseActivation::where('license_id', $license->id)
                ->where('is_active', true);

            if ($hardwareFingerprint) {
                $usersCountQuery->where('hardware_fingerprint', $hardwareFingerprint);
            }

            $usersCount = $usersCountQuery->distinct('hardware_fingerprint')
                ->count('hardware_fingerprint');

            return response()->json([
                'daily_requests' => $dailyRequests,
                'monthly_requests' => $monthlyRequests,
                'total_users' => $usersCount,
                'license_key' => $licenseKey,
                'hardware_fingerprint' => $hardwareFingerprint,
                'period' => [
                    'daily_since' => $dailySince->toISOString(),
                    'monthly_since' => $monthlySince->toISOString(),
                ],
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'analytics');
        }
    }

    /**
     * Get validation statistics for a license
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validationStats(Request $request): JsonResponse
    {
        try {
            $licenseKey = $request->query('license_key');

            if (!$licenseKey) {
                return response()->json([
                    'error' => 'Missing license_key parameter',
                    'error_code' => 'MISSING_LICENSE_KEY',
                ], 400);
            }

            $this->logRequest('validation-stats', $request, ['license_key' => $licenseKey]);

            $license = License::with(['product'])->where('license_key', $licenseKey)->first();

            if (!$license) {
                return response()->json([
                    'error' => 'License not found',
                    'error_code' => 'LICENSE_NOT_FOUND',
                ], 404);
            }

            // Server connection status
            $serverOnline = true; // Always true since we're responding

            // Last validation stats
            $lastValidation = LicenseValidation::where('license_id', $license->id)
                ->latest('validated_at')
                ->first();

            // Active devices count
            $activeDevices = LicenseActivation::where('license_id', $license->id)
                ->where('is_active', true)
                ->distinct('hardware_fingerprint')
                ->count('hardware_fingerprint');

            // Validation counts for last 30 days
            $monthlySince = now()->subDays(30);
            $totalValidations = LicenseValidation::where('license_id', $license->id)
                ->where('validated_at', '>=', $monthlySince)
                ->count();

            $successfulValidations = LicenseValidation::where('license_id', $license->id)
                ->where('validated_at', '>=', $monthlySince)
                ->where('result', 'success')
                ->count();

            // Grace period and offline settings
            $gracePeriodDays = $license->product->grace_period_days ??
                config('license.server.grace_period_days', 7);
            $maxOfflineDays = $license->product->offline_validation_days ??
                config('license.server.offline_validation_days', 30);

            return response()->json([
                'server_online' => $serverOnline,
                'last_server_check' => now()->toISOString(),
                'validation_cache_enabled' => true,
                'offline_grace_period_days' => $gracePeriodDays,
                'max_offline_days' => $maxOfflineDays,
                'hardware_binding_enabled' => true,
                'hardware_tolerance' => config('license.fingerprint.tolerance', 10),
                'active_devices' => $activeDevices,
                'total_validations_30d' => $totalValidations,
                'successful_validations_30d' => $successfulValidations,
                'failed_validations_30d' => max(0, $totalValidations - $successfulValidations),
                'success_rate_30d' => $totalValidations > 0
                    ? round(($successfulValidations / $totalValidations) * 100, 1)
                    : 0,
                'last_validation_at' => $lastValidation
                    ? $lastValidation->validated_at->toISOString()
                    : null,
                'license_status' => $license->status,
                'license_expires_at' => $license->expires_at
                    ? $license->expires_at->toISOString()
                    : null,
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'validation-stats');
        }
    }

    /**
     * Get hardware information (fingerprint generation utility)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function hardwareInfo(Request $request): JsonResponse
    {
        try {
            $this->logRequest('hardware-info', $request, []);

            $fingerprint = $this->fingerprintService->generate();
            $components = $this->fingerprintService->getComponents();

            return response()->json([
                'hardware_fingerprint' => $fingerprint,
                'components' => $components,
                'generated_at' => now()->toISOString(),
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse($e, 'hardware-info');
        }
    }

    /**
     * Log API request if logging is enabled
     *
     * @param string $endpoint
     * @param Request $request
     * @param array $data
     * @return void
     */
    protected function logRequest(string $endpoint, Request $request, array $data): void
    {
        if (!config('license.logging.log_validations', true)) {
            return;
        }

        $logData = [
            'endpoint' => $endpoint,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'license_key' => $data['license_key'] ?? null,
            'hardware_fingerprint' => isset($data['hardware_fingerprint'])
                ? substr($data['hardware_fingerprint'], 0, 8) . '...'
                : null,
        ];

        Log::channel(config('license.logging.channel', 'stack'))
            ->info("License API Request: {$endpoint}", $logData);
    }

    /**
     * Format validation error response
     *
     * @param array $errors
     * @return JsonResponse
     */
    protected function validationErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'valid' => false,
            'success' => false,
            'error' => 'Validation failed',
            'error_code' => 'VALIDATION_ERROR',
            'details' => $errors,
        ], 422);
    }

    /**
     * Format exception response
     *
     * @param \Exception $exception
     * @param string $context
     * @return JsonResponse
     */
    protected function exceptionResponse(\Exception $exception, string $context): JsonResponse
    {
        $isDebug = config('app.debug', false);

        Log::channel(config('license.logging.channel', 'stack'))
            ->error("License API Exception [{$context}]: {$exception->getMessage()}", [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $isDebug ? $exception->getTraceAsString() : null,
            ]);

        $response = [
            'valid' => false,
            'success' => false,
            'error' => $isDebug ? $exception->getMessage() : 'An error occurred while processing your request',
            'error_code' => 'INTERNAL_ERROR',
        ];

        if ($isDebug) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return response()->json($response, 500);
    }
}
