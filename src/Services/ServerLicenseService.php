<?php

namespace Westel\License\Services;

use Westel\License\Contracts\LicenseServiceInterface;
use Westel\License\Models\License;
use Westel\License\Models\LicenseActivation;
use Westel\License\Models\LicenseValidation;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;

class ServerLicenseService implements LicenseServiceInterface
{
    protected array $config;
    protected ?License $license = null;
    protected ?string $licenseKey = null;
    protected ?string $hardwareFingerprint = null;
    protected ?string $productId = null;
    protected ?array $lastValidationResult = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'jwt_secret' => config('license.server.jwt_secret', config('app.key')),
            'jwt_algorithm' => config('license.server.jwt_algorithm', 'HS256'),
            'grace_period_days' => config('license.server.grace_period_days', 7),
            'offline_validation_days' => config('license.server.offline_validation_days', 30),
            'default_activation_limit' => config('license.server.default_activation_limit', 5),
            'log_validations' => config('license.logging.log_validations', true),
        ], $config);
    }

    /**
     * Set license parameters for validation
     */
    public function setLicenseParams(string $licenseKey, string $hardwareFingerprint, string $productId): self
    {
        $this->licenseKey = $licenseKey;
        $this->hardwareFingerprint = $hardwareFingerprint;
        $this->productId = $productId;
        $this->license = null; // Reset cached license

        return $this;
    }

    /**
     * Validate license and return validation result
     */
    public function validateLicense(
        string $licenseKey,
        string $hardwareFingerprint,
        string $productId,
        ?array $systemInfo = null,
        ?Request $request = null
    ): array {
        $this->setLicenseParams($licenseKey, $hardwareFingerprint, $productId);

        // Find license
        $license = License::with(['product'])
            ->where('license_key', $licenseKey)
            ->where('product_id', $productId)
            ->first();

        if (!$license) {
            $this->logValidation(null, $hardwareFingerprint, 'online', 'invalid', $request);
            return $this->lastValidationResult = [
                'valid' => false,
                'error' => 'License not found',
                'error_code' => 'LICENSE_NOT_FOUND'
            ];
        }

        $this->license = $license;

        // Check license status
        if (!$license->isActive()) {
            $this->logValidation($license->id, $hardwareFingerprint, 'online', 'inactive', $request);
            return $this->lastValidationResult = [
                'valid' => false,
                'error' => 'License is not active',
                'error_code' => 'LICENSE_INACTIVE',
                'status' => $license->status
            ];
        }

        // Check expiration
        if ($license->isExpired()) {
            // Check if in grace period
            if ($license->isInGracePeriod()) {
                $this->logValidation($license->id, $hardwareFingerprint, 'online', 'success', $request, [
                    'grace_period' => true,
                    'days_expired' => abs($license->getDaysUntilExpiry())
                ]);

                return $this->lastValidationResult = [
                    'valid' => true,
                    'status' => 'grace_period',
                    'message' => 'License expired but in grace period',
                    'grace_period_days' => $license->product->grace_period_days ?? $this->config['grace_period_days'],
                    'days_expired' => abs($license->getDaysUntilExpiry()),
                    'license' => $this->formatLicenseResponse($license),
                    'offline_token' => $this->generateOfflineToken($license, $hardwareFingerprint),
                    'next_validation' => now()->addHours(24)->toISOString()
                ];
            }

            $this->logValidation($license->id, $hardwareFingerprint, 'online', 'expired', $request);
            return $this->lastValidationResult = [
                'valid' => false,
                'error' => 'License has expired',
                'error_code' => 'LICENSE_EXPIRED',
                'expires_at' => $license->expires_at->toISOString()
            ];
        }

        // Check if device is already activated
        $existingActivation = LicenseActivation::where('license_id', $license->id)
            ->where('hardware_fingerprint', $hardwareFingerprint)
            ->first();

        if ($existingActivation) {
            // Update existing activation
            $this->updateActivation($license, $hardwareFingerprint, $systemInfo);
        } else {
            // Check if we can create a new activation
            if (!$license->canActivate($hardwareFingerprint)) {
                $this->logValidation($license->id, $hardwareFingerprint, 'online', 'limit_exceeded', $request);
                return $this->lastValidationResult = [
                    'valid' => false,
                    'error' => 'Activation limit exceeded',
                    'error_code' => 'ACTIVATION_LIMIT_EXCEEDED',
                    'activation_limit' => $license->activation_limit,
                    'active_count' => $license->activeActivations()->count()
                ];
            }

            // Create new activation
            $this->createActivation($license, $hardwareFingerprint, $systemInfo);
        }

        $this->logValidation($license->id, $hardwareFingerprint, 'online', 'success', $request);

        // Update last validated timestamp
        $license->update(['last_validated_at' => now()]);

        return $this->lastValidationResult = [
            'valid' => true,
            'status' => 'active',
            'license' => $this->formatLicenseResponse($license),
            'offline_token' => $this->generateOfflineToken($license, $hardwareFingerprint),
            'next_validation' => now()->addHours(24)->toISOString()
        ];
    }

    /**
     * Validate the license (implements interface method)
     */
    public function validate(): array
    {
        if (!$this->licenseKey || !$this->hardwareFingerprint || !$this->productId) {
            return [
                'valid' => false,
                'error' => 'License parameters not set. Call setLicenseParams() first.',
                'error_code' => 'PARAMS_NOT_SET'
            ];
        }

        return $this->validateLicense(
            $this->licenseKey,
            $this->hardwareFingerprint,
            $this->productId
        );
    }

    /**
     * Check if license is valid
     */
    public function isValid(): bool
    {
        if ($this->lastValidationResult === null) {
            $this->validate();
        }

        return $this->lastValidationResult['valid'] ?? false;
    }

    /**
     * Activate license on new hardware
     */
    public function activateLicense(
        string $licenseKey,
        string $hardwareFingerprint,
        string $productId,
        ?array $systemInfo = null
    ): array {
        $license = License::with(['product'])
            ->where('license_key', $licenseKey)
            ->where('product_id', $productId)
            ->first();

        if (!$license) {
            return [
                'success' => false,
                'error' => 'License not found',
                'error_code' => 'LICENSE_NOT_FOUND'
            ];
        }

        if (!$license->isActive()) {
            return [
                'success' => false,
                'error' => 'License is not active',
                'error_code' => 'LICENSE_INACTIVE'
            ];
        }

        // Check if device is already activated
        $existingActivation = LicenseActivation::where('license_id', $license->id)
            ->where('hardware_fingerprint', $hardwareFingerprint)
            ->first();

        if ($existingActivation) {
            return [
                'success' => true,
                'activation_id' => $existingActivation->id,
                'message' => 'License is already activated on this device'
            ];
        }

        if (!$license->canActivate($hardwareFingerprint)) {
            return [
                'success' => false,
                'error' => 'Cannot activate license on this device',
                'error_code' => 'ACTIVATION_FAILED',
                'activation_limit' => $license->activation_limit,
                'active_count' => $license->activeActivations()->count()
            ];
        }

        $activation = $this->createActivation($license, $hardwareFingerprint, $systemInfo);

        return [
            'success' => true,
            'activation_id' => $activation->id,
            'message' => 'License successfully activated on this device',
            'offline_token' => $this->generateOfflineToken($license, $hardwareFingerprint)
        ];
    }

    /**
     * Deactivate license on specific hardware
     */
    public function deactivateLicense(string $licenseKey, string $hardwareFingerprint): array
    {
        $license = License::where('license_key', $licenseKey)->first();

        if (!$license) {
            return [
                'success' => false,
                'error' => 'License not found',
                'error_code' => 'LICENSE_NOT_FOUND'
            ];
        }

        $activation = LicenseActivation::where('license_id', $license->id)
            ->where('hardware_fingerprint', $hardwareFingerprint)
            ->where('is_active', true)
            ->first();

        if (!$activation) {
            return [
                'success' => false,
                'error' => 'No active activation found for this device',
                'error_code' => 'ACTIVATION_NOT_FOUND'
            ];
        }

        $activation->deactivate();

        // Update activation count
        $license->update(['activation_count' => $license->activeActivations()->count()]);

        return [
            'success' => true,
            'message' => 'License successfully deactivated from this device'
        ];
    }

    /**
     * Process heartbeat validation
     */
    public function heartbeat(string $licenseKey, string $hardwareFingerprint, ?Request $request = null): array
    {
        if ($this->config['log_validations']) {
            Log::info("Heartbeat validation for license: {$licenseKey}, hardware: {$hardwareFingerprint}");
        }

        $license = License::with(['product'])
            ->where('license_key', $licenseKey)
            ->first();

        if (!$license) {
            return [
                'valid' => false,
                'error' => 'License not found',
                'error_code' => 'LICENSE_NOT_FOUND'
            ];
        }

        $activation = LicenseActivation::where('license_id', $license->id)
            ->where('hardware_fingerprint', $hardwareFingerprint)
            ->where('is_active', true)
            ->first();

        if (!$activation) {
            return [
                'valid' => false,
                'error' => 'Device not activated',
                'error_code' => 'DEVICE_NOT_ACTIVATED'
            ];
        }

        $activation->updateHeartbeat();
        $this->logValidation($license->id, $hardwareFingerprint, 'heartbeat', 'success', $request);

        return [
            'valid' => true,
            'status' => $license->status,
            'expires_at' => $license->expires_at?->toISOString(),
            'features' => $license->product->getFeaturesList(),
            'structured_features' => $license->product->getStructuredFeatures(),
            'license' => $this->formatLicenseResponse($license)
        ];
    }

    /**
     * Get license information
     */
    public function getLicenseInfo(?string $licenseKey = null): array
    {
        $key = $licenseKey ?? $this->licenseKey;

        if (!$key) {
            return [
                'found' => false,
                'error' => 'License key not provided'
            ];
        }

        $license = License::with(['product', 'activations'])
            ->where('license_key', $key)
            ->first();

        if (!$license) {
            return [
                'found' => false,
                'error' => 'License not found'
            ];
        }

        $this->license = $license;

        return [
            'found' => true,
            'license' => $this->formatLicenseResponse($license),
            'activations' => $license->activations->map(function ($activation) {
                return [
                    'id' => $activation->id,
                    'hardware_fingerprint' => substr($activation->hardware_fingerprint, 0, 8) . '...',
                    'activated_at' => $activation->activated_at->toISOString(),
                    'last_checked_at' => $activation->last_checked_at?->toISOString(),
                    'is_active' => $activation->is_active,
                ];
            })
        ];
    }

    /**
     * Check if license has a specific feature
     */
    public function hasFeature(string $featureKey): bool
    {
        if (!$this->license) {
            if ($this->licenseKey) {
                $this->license = License::with(['product'])
                    ->where('license_key', $this->licenseKey)
                    ->first();
            }

            if (!$this->license) {
                return false;
            }
        }

        return $this->license->hasFeature($featureKey);
    }

    /**
     * Check if feature can be used (with limit validation)
     */
    public function canUseFeature(string $featureKey, ?int $currentUsage = null): bool
    {
        if (!$this->license) {
            return false;
        }

        $validation = $this->validateFeatureUsage($this->license, $featureKey, $currentUsage);
        return $validation['allowed'] ?? false;
    }

    /**
     * Get all available features
     */
    public function getFeatures(): array
    {
        if (!$this->license) {
            if ($this->licenseKey) {
                $this->license = License::with(['product'])
                    ->where('license_key', $this->licenseKey)
                    ->first();
            }

            if (!$this->license) {
                return [];
            }
        }

        return $this->getLicenseFeatures($this->license);
    }

    /**
     * Get feature configuration
     */
    public function getFeatureConfig(string $featureKey): ?array
    {
        if (!$this->license) {
            return null;
        }

        return $this->getFeatureConfiguration($this->license, $featureKey);
    }

    /**
     * Get license status
     */
    public function getStatus(): string
    {
        if (!$this->license) {
            if ($this->licenseKey) {
                $this->license = License::where('license_key', $this->licenseKey)->first();
            }

            if (!$this->license) {
                return 'unknown';
            }
        }

        if ($this->license->isExpired()) {
            return $this->license->isInGracePeriod() ? 'grace_period' : 'expired';
        }

        return $this->license->status;
    }

    /**
     * Refresh license data from server (in server mode, just reload from DB)
     */
    public function refresh(): array
    {
        if (!$this->licenseKey) {
            return [
                'success' => false,
                'error' => 'License key not set'
            ];
        }

        $this->license = License::with(['product'])
            ->where('license_key', $this->licenseKey)
            ->first();

        if (!$this->license) {
            return [
                'success' => false,
                'error' => 'License not found'
            ];
        }

        return [
            'success' => true,
            'license' => $this->formatLicenseResponse($this->license)
        ];
    }

    /**
     * Get days until license expiry
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->license) {
            return null;
        }

        return $this->license->getDaysUntilExpiry();
    }

    /**
     * Generate JWT token for offline validation
     */
    protected function generateOfflineToken(License $license, string $hardwareFingerprint): string
    {
        $offlineDays = $license->product->offline_validation_days ?? $this->config['offline_validation_days'];
        $expiry = $license->expires_at
            ? min($license->expires_at->timestamp, now()->addDays($offlineDays)->timestamp)
            : now()->addDays($offlineDays)->timestamp;

        $payload = [
            'iss' => config('app.url'),
            'aud' => 'offline-validation',
            'iat' => now()->timestamp,
            'exp' => $expiry,
            'license_id' => $license->id,
            'license_key' => $license->license_key,
            'product_id' => $license->product_id,
            'features' => $license->product->getFeaturesList(),
            'structured_features' => $license->product->getStructuredFeatures(),
            'hardware_fingerprint' => $hardwareFingerprint,
            'grace_period_days' => $license->product->grace_period_days ?? $this->config['grace_period_days'],
            'status' => $license->status
        ];

        return JWT::encode($payload, $this->config['jwt_secret'], $this->config['jwt_algorithm']);
    }

    /**
     * Verify offline JWT token
     */
    public function verifyOfflineToken(string $token, string $hardwareFingerprint): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config['jwt_secret'], $this->config['jwt_algorithm']));

            // Verify hardware fingerprint matches
            if ($decoded->hardware_fingerprint !== $hardwareFingerprint) {
                return [
                    'valid' => false,
                    'error' => 'Hardware fingerprint mismatch',
                    'error_code' => 'HARDWARE_MISMATCH'
                ];
            }

            return [
                'valid' => true,
                'status' => $decoded->status ?? 'active',
                'license_key' => $decoded->license_key,
                'product_id' => $decoded->product_id,
                'features' => $decoded->features,
                'structured_features' => $decoded->structured_features ?? [],
                'expires_at' => date('c', $decoded->exp),
                'validation_type' => 'offline'
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'Invalid or expired token: ' . $e->getMessage(),
                'error_code' => 'TOKEN_INVALID'
            ];
        }
    }

    /**
     * Create new activation record
     */
    protected function createActivation(License $license, string $hardwareFingerprint, ?array $systemInfo = null): LicenseActivation
    {
        $activation = LicenseActivation::create([
            'license_id' => $license->id,
            'hardware_fingerprint' => $hardwareFingerprint,
            'ip_address' => request()?->ip(),
            'system_info' => $systemInfo,
            'activated_at' => now(),
            'last_checked_at' => now(),
            'is_active' => true,
        ]);

        // Update license hardware fingerprint if not set
        if (!$license->hardware_fingerprint) {
            $license->update([
                'hardware_fingerprint' => $hardwareFingerprint,
                'activated_at' => now(),
            ]);
        }

        // Update activation count to reflect actual active activations
        $license->update(['activation_count' => $license->activeActivations()->count()]);

        return $activation;
    }

    /**
     * Update existing activation
     */
    protected function updateActivation(License $license, string $hardwareFingerprint, ?array $systemInfo = null): void
    {
        LicenseActivation::where('license_id', $license->id)
            ->where('hardware_fingerprint', $hardwareFingerprint)
            ->update([
                'last_checked_at' => now(),
                'system_info' => $systemInfo,
                'is_active' => true,
            ]);
    }

    /**
     * Log validation attempt
     */
    protected function logValidation(
        ?string $licenseId,
        string $hardwareFingerprint,
        string $type,
        string $result,
        ?Request $request = null,
        ?array $context = null
    ): void {
        if (!$this->config['log_validations']) {
            return;
        }

        LicenseValidation::logValidation(
            $licenseId,
            $hardwareFingerprint,
            $type,
            $result,
            $request?->ip(),
            $request?->userAgent(),
            $context
        );
    }

    /**
     * Format license response for API
     */
    protected function formatLicenseResponse(License $license): array
    {
        $features = $license->getAvailableFeatures();

        return [
            'id' => $license->id,
            'license_key' => $license->license_key,
            'status' => $license->status,
            'activated_at' => $license->activated_at?->toISOString(),
            'expires_at' => $license->expires_at?->toISOString(),
            'activation_limit' => $license->activation_limit,
            'activation_count' => $license->activation_count,
            'days_until_expiry' => $license->getDaysUntilExpiry(),
            'is_expired' => $license->isExpired(),
            'is_in_grace_period' => $license->isExpired() && $license->isInGracePeriod(),
            'product' => [
                'id' => $license->product->id,
                'name' => $license->product->name,
                'version' => $license->product->version,
                'features' => $features,
                'structured_features' => $license->product->getStructuredFeatures(),
            ],
        ];
    }

    /**
     * Get feature configuration for a license
     */
    protected function getFeatureConfiguration(License $license, string $featureKey): ?array
    {
        // Get from new feature system first
        $assignment = $license->product->featureAssignments()
            ->whereHas('featureDefinition', function ($query) use ($featureKey) {
                $query->where('key', $featureKey);
            })
            ->where('is_enabled', true)
            ->with('featureDefinition')
            ->first();

        if ($assignment) {
            return [
                'enabled' => true,
                'type' => $assignment->featureDefinition->type,
                'configuration' => $assignment->configuration ?? [],
                'limit' => $assignment->getConfiguredValue('limit'),
                'quota' => $assignment->getConfiguredValue('quota'),
                'period' => $assignment->getConfiguredValue('period', 'month'),
            ];
        }

        // Fallback to legacy feature system
        if ($this->hasFeature($featureKey)) {
            return [
                'enabled' => true,
                'type' => 'feature',
                'configuration' => [],
                'limit' => null,
                'quota' => null,
                'period' => null,
            ];
        }

        return null;
    }

    /**
     * Validate if a license can use a specific feature with limits
     */
    protected function validateFeatureUsage(License $license, string $featureKey, ?int $currentUsage = null): array
    {
        $featureConfig = $this->getFeatureConfiguration($license, $featureKey);

        if (!$featureConfig) {
            return [
                'allowed' => false,
                'error' => "Feature '{$featureKey}' not available in current license",
                'error_code' => 'FEATURE_NOT_AVAILABLE'
            ];
        }

        if (!$featureConfig['enabled']) {
            return [
                'allowed' => false,
                'error' => "Feature '{$featureKey}' is disabled",
                'error_code' => 'FEATURE_DISABLED'
            ];
        }

        // Check limits for limit-type features
        if ($featureConfig['type'] === 'limit' && $featureConfig['limit'] !== null && $featureConfig['limit'] !== -1) {
            if ($currentUsage !== null && $currentUsage >= $featureConfig['limit']) {
                return [
                    'allowed' => false,
                    'error' => "Feature limit exceeded. Maximum: {$featureConfig['limit']}, Current: {$currentUsage}",
                    'error_code' => 'FEATURE_LIMIT_EXCEEDED',
                    'limit' => $featureConfig['limit'],
                    'current_usage' => $currentUsage
                ];
            }
        }

        return [
            'allowed' => true,
            'feature_config' => $featureConfig,
            'remaining' => $featureConfig['limit'] !== null && $featureConfig['limit'] !== -1 && $currentUsage !== null
                ? max(0, $featureConfig['limit'] - $currentUsage)
                : null
        ];
    }

    /**
     * Get all available features for a license with their configurations
     */
    protected function getLicenseFeatures(License $license): array
    {
        $features = [];

        // Get structured features from new system
        $structuredFeatures = $license->product->getStructuredFeatures();
        foreach ($structuredFeatures as $category => $categoryFeatures) {
            foreach ($categoryFeatures as $feature) {
                $features[$feature['key']] = [
                    'name' => $feature['name'],
                    'category' => $feature['category'],
                    'type' => $feature['type'],
                    'enabled' => true,
                    'configuration' => $feature['configuration'],
                    'display_value' => $feature['display_value'],
                ];
            }
        }

        // Add legacy features
        $legacyFeatures = $license->product->features ?? [];
        foreach ($legacyFeatures as $featureKey) {
            if (!isset($features[$featureKey])) {
                $features[$featureKey] = [
                    'name' => ucwords(str_replace('_', ' ', $featureKey)),
                    'category' => 'other',
                    'type' => 'feature',
                    'enabled' => true,
                    'configuration' => [],
                    'display_value' => 'Enabled',
                ];
            }
        }

        return $features;
    }

    /**
     * Get the current license instance
     */
    public function getLicense(): ?License
    {
        return $this->license;
    }

    /**
     * Set the license instance directly
     */
    public function setLicense(License $license): self
    {
        $this->license = $license;
        $this->licenseKey = $license->license_key;
        $this->productId = $license->product_id;

        return $this;
    }
}
