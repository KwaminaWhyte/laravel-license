<?php

namespace Westel\License\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Westel\License\Contracts\LicenseServiceInterface;
use Westel\License\Events\LicenseActivated;
use Westel\License\Events\LicenseDeactivated;
use Westel\License\Events\LicenseExpired;
use Westel\License\Events\LicenseInvalid;
use Westel\License\Events\LicenseValidated;
use Westel\License\Exceptions\LicenseException;
use Westel\License\Exceptions\LicenseExpiredException;
use Westel\License\Exceptions\LicenseInvalidException;
use Westel\License\Exceptions\LicenseServerException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Carbon\Carbon;

class ClientLicenseService implements LicenseServiceInterface
{
    /**
     * Configuration
     *
     * @var array
     */
    protected array $config;

    /**
     * Hardware fingerprint service
     *
     * @var HardwareFingerprintService
     */
    protected HardwareFingerprintService $fingerprintService;

    /**
     * HTTP client
     *
     * @var Client
     */
    protected Client $httpClient;

    /**
     * Cache store
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Cached license data
     *
     * @var array|null
     */
    protected ?array $licenseData = null;

    /**
     * Last heartbeat timestamp
     *
     * @var int|null
     */
    protected ?int $lastHeartbeat = null;

    /**
     * Create a new ClientLicenseService instance
     *
     * @param array $config
     * @param HardwareFingerprintService $fingerprintService
     */
    public function __construct(array $config, HardwareFingerprintService $fingerprintService)
    {
        $this->config = $config;
        $this->fingerprintService = $fingerprintService;

        // Initialize HTTP client with retry middleware
        $this->initializeHttpClient();

        // Initialize cache
        $this->cache = Cache::store($config['cache_store'] ?? 'file');

        // Load cached license data
        $this->loadCachedData();
    }

    /**
     * Initialize HTTP client with Guzzle
     *
     * @return void
     */
    protected function initializeHttpClient(): void
    {
        $handlerStack = \GuzzleHttp\HandlerStack::create();

        // Add retry middleware if enabled
        if ($this->config['retry']['enabled'] ?? true) {
            $handlerStack->push($this->createRetryMiddleware());
        }

        $this->httpClient = new Client([
            'base_uri' => rtrim($this->config['server_url'], '/') . '/',
            'timeout' => $this->config['http_timeout'] ?? 10,
            'handler' => $handlerStack,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Laravel-License-Client/1.0',
            ],
        ]);
    }

    /**
     * Create retry middleware for Guzzle
     *
     * @return callable
     */
    protected function createRetryMiddleware(): callable
    {
        return \GuzzleHttp\Middleware::retry(
            function ($retries, $request, $response, $exception) {
                $maxRetries = $this->config['retry']['times'] ?? 3;

                // Don't retry if we've exceeded max retries
                if ($retries >= $maxRetries) {
                    return false;
                }

                // Retry on connection errors
                if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                    return true;
                }

                // Retry on 5xx server errors
                if ($response && $response->getStatusCode() >= 500) {
                    return true;
                }

                return false;
            },
            function ($retries) {
                $sleep = $this->config['retry']['sleep'] ?? 1000;
                return $retries * $sleep;
            }
        );
    }

    /**
     * Load cached license data
     *
     * @return void
     */
    protected function loadCachedData(): void
    {
        $this->licenseData = $this->cache->get($this->getCacheKey('license_data'));
        $this->lastHeartbeat = $this->cache->get($this->getCacheKey('last_heartbeat'));
    }

    /**
     * Get cache key
     *
     * @param string $suffix
     * @return string
     */
    protected function getCacheKey(string $suffix): string
    {
        $licenseKey = $this->config['license_key'] ?? 'default';
        return "license_{$licenseKey}_{$suffix}";
    }

    /**
     * Validate the license
     *
     * @return array
     * @throws LicenseException
     */
    public function validate(): array
    {
        try {
            // Try online validation first
            $result = $this->validateOnline();

            // Cache the result
            $this->cacheLicenseData($result);

            // Fire event
            $this->fireEvent('license_validated', new LicenseValidated($result, 'online'));

            return $result;
        } catch (LicenseServerException $e) {
            // Server is down, try offline validation
            if ($this->config['offline_mode'] ?? true) {
                return $this->validateOffline();
            }

            throw $e;
        } catch (LicenseException $e) {
            // License is invalid, check if we can use cached data
            if ($this->canUseCachedData()) {
                $this->log('warning', 'License validation failed, using cached data', [
                    'error' => $e->getMessage()
                ]);

                return $this->licenseData;
            }

            throw $e;
        }
    }

    /**
     * Validate license online
     *
     * @return array
     * @throws LicenseException
     */
    protected function validateOnline(): array
    {
        try {
            $payload = [
                'license_key' => $this->config['license_key'],
                'hardware_fingerprint' => $this->getHardwareFingerprint(),
                'system_info' => $this->getSystemInfo(),
            ];

            $response = $this->httpClient->post('api/license/validate', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['valid']) || !$data['valid']) {
                $this->fireEvent('license_invalid', new LicenseInvalid(
                    $data['message'] ?? 'License is invalid',
                    $data
                ));

                throw new LicenseInvalidException($data['message'] ?? 'License is invalid');
            }

            // Check if expired
            if ($data['is_expired'] ?? false) {
                $inGracePeriod = $data['in_grace_period'] ?? false;

                $this->fireEvent('license_expired', new LicenseExpired($data, $inGracePeriod));

                if (!$inGracePeriod) {
                    throw new LicenseExpiredException('License has expired');
                }

                // Warn about grace period
                if ($this->config['grace_period_warning'] ?? true) {
                    $this->log('warning', 'License is in grace period', [
                        'days_remaining' => $data['grace_period_days_remaining'] ?? 0
                    ]);
                }
            }

            $this->log('info', 'License validated successfully online');

            return $data;
        } catch (RequestException $e) {
            // Network or server error
            $this->log('error', 'License server request failed', [
                'error' => $e->getMessage()
            ]);

            throw new LicenseServerException('Failed to connect to license server: ' . $e->getMessage());
        } catch (GuzzleException $e) {
            $this->log('error', 'License validation HTTP error', [
                'error' => $e->getMessage()
            ]);

            throw new LicenseServerException('License validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate license offline using JWT token
     *
     * @return array
     * @throws LicenseException
     */
    protected function validateOffline(): array
    {
        $offlineToken = $this->cache->get($this->getCacheKey('offline_token'));

        if (!$offlineToken) {
            // No offline token available, try to get cached data
            if ($this->canUseCachedData()) {
                $this->log('warning', 'No offline token, using cached data');
                $this->fireEvent('license_validated', new LicenseValidated($this->licenseData, 'cache'));
                return $this->licenseData;
            }

            throw new LicenseServerException('No offline token available and server is unreachable');
        }

        try {
            // Decode JWT token
            $decoded = JWT::decode($offlineToken, new Key($this->getJwtSecret(), $this->getJwtAlgorithm()));
            $data = (array) $decoded;

            // Validate expiry
            if (isset($data['exp']) && $data['exp'] < time()) {
                $this->fireEvent('license_expired', new LicenseExpired($data, false));
                throw new LicenseExpiredException('Offline license token has expired');
            }

            // Validate hardware fingerprint
            if (isset($data['hardware_fingerprint'])) {
                $currentFingerprint = $this->getHardwareFingerprint();
                if ($data['hardware_fingerprint'] !== $currentFingerprint) {
                    $this->log('warning', 'Hardware fingerprint mismatch in offline mode', [
                        'expected' => $data['hardware_fingerprint'],
                        'actual' => $currentFingerprint
                    ]);

                    // Allow some tolerance based on config
                    if (!$this->fingerprintService->validate($data['hardware_fingerprint'], $currentFingerprint)) {
                        throw new LicenseInvalidException('Hardware fingerprint mismatch');
                    }
                }
            }

            $this->log('info', 'License validated successfully offline');
            $this->fireEvent('license_validated', new LicenseValidated($data, 'offline'));

            return $data;
        } catch (\Exception $e) {
            $this->log('error', 'Offline license validation failed', [
                'error' => $e->getMessage()
            ]);

            if ($e instanceof LicenseException) {
                throw $e;
            }

            throw new LicenseInvalidException('Offline token validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if license is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        try {
            $result = $this->validate();
            return $result['valid'] ?? false;
        } catch (LicenseException $e) {
            $this->handleValidationFailure($e);
            return false;
        }
    }

    /**
     * Check if license has a specific feature
     *
     * @param string $featureKey
     * @return bool
     */
    public function hasFeature(string $featureKey): bool
    {
        try {
            $features = $this->getFeatures();
            return isset($features[$featureKey]);
        } catch (\Exception $e) {
            $this->log('error', 'Feature check failed', [
                'feature' => $featureKey,
                'error' => $e->getMessage()
            ]);

            return config('license.features.default_access', false);
        }
    }

    /**
     * Check if feature can be used (with limit validation)
     *
     * @param string $featureKey
     * @param int|null $currentUsage
     * @return bool
     */
    public function canUseFeature(string $featureKey, ?int $currentUsage = null): bool
    {
        if (!$this->hasFeature($featureKey)) {
            return false;
        }

        $feature = $this->getFeatureConfig($featureKey);

        if (!$feature) {
            return false;
        }

        // Check if feature is enabled
        if (isset($feature['enabled']) && !$feature['enabled']) {
            return false;
        }

        // Check usage limits
        if (isset($feature['limit']) && $currentUsage !== null) {
            return $currentUsage < $feature['limit'];
        }

        return true;
    }

    /**
     * Get all available features
     *
     * @return array
     */
    public function getFeatures(): array
    {
        // Try to get from cache first
        $cacheKey = $this->getCacheKey('features');
        $cached = $this->cache->get($cacheKey);

        if ($cached && (config('license.features.cache_checks', true))) {
            return $cached;
        }

        try {
            // Fetch from server
            $response = $this->httpClient->get('api/license/features', [
                'query' => [
                    'license_key' => $this->config['license_key'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $features = $data['features'] ?? [];

            // Cache the features
            $ttl = $this->config['cache_ttl'] ?? 86400;
            if ($ttl > 0) {
                $this->cache->put($cacheKey, $features, $ttl);
            }

            return $features;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch features from server', [
                'error' => $e->getMessage()
            ]);

            // Return cached features if available
            if ($cached) {
                return $cached;
            }

            // Return features from license data
            if ($this->licenseData && isset($this->licenseData['features'])) {
                return $this->licenseData['features'];
            }

            return [];
        }
    }

    /**
     * Get feature configuration
     *
     * @param string $featureKey
     * @return array|null
     */
    public function getFeatureConfig(string $featureKey): ?array
    {
        $features = $this->getFeatures();
        return $features[$featureKey] ?? null;
    }

    /**
     * Get license information
     *
     * @return array
     */
    public function getLicenseInfo(): array
    {
        try {
            $result = $this->validate();
            return [
                'license_key' => $this->config['license_key'],
                'status' => $result['status'] ?? 'unknown',
                'is_valid' => $result['valid'] ?? false,
                'is_expired' => $result['is_expired'] ?? false,
                'in_grace_period' => $result['in_grace_period'] ?? false,
                'expires_at' => $result['expires_at'] ?? null,
                'tier' => $result['tier'] ?? null,
                'tier_label' => $result['tier_label'] ?? null,
                'plan' => $result['plan'] ?? null,
                'product' => $result['product'] ?? null,
                'customer' => $result['customer'] ?? null,
                'features' => $result['features'] ?? [],
                'structured_features' => $result['structured_features'] ?? [],
                'license' => $result['license'] ?? null,
            ];
        } catch (LicenseException $e) {
            // Return cached info if available
            if ($this->licenseData) {
                return array_merge([
                    'license_key' => $this->config['license_key'],
                    'status' => 'cached',
                ], $this->licenseData);
            }

            return [
                'license_key' => $this->config['license_key'],
                'status' => 'invalid',
                'is_valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get license status
     *
     * @return string
     */
    public function getStatus(): string
    {
        try {
            $result = $this->validate();
            return $result['status'] ?? 'unknown';
        } catch (LicenseExpiredException $e) {
            return 'expired';
        } catch (LicenseInvalidException $e) {
            return 'invalid';
        } catch (LicenseServerException $e) {
            return 'server_error';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    /**
     * Refresh license data from server
     *
     * @return array
     * @throws LicenseException
     */
    public function refresh(): array
    {
        // Clear cache
        $this->clearCache();

        // Validate to get fresh data
        return $this->validate();
    }

    /**
     * Get days until license expiry
     *
     * @return int|null
     */
    public function getDaysUntilExpiry(): ?int
    {
        try {
            $info = $this->getLicenseInfo();

            if (!isset($info['expires_at'])) {
                return null;
            }

            $expiresAt = Carbon::parse($info['expires_at']);
            $now = Carbon::now();

            if ($expiresAt->isPast()) {
                return 0;
            }

            return $now->diffInDays($expiresAt);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Activate license
     *
     * @return array
     * @throws LicenseException
     */
    public function activate(): array
    {
        try {
            $payload = [
                'license_key' => $this->config['license_key'],
                'hardware_fingerprint' => $this->getHardwareFingerprint(),
                'system_info' => $this->getSystemInfo(),
            ];

            $response = $this->httpClient->post('api/license/activate', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['success']) || !$data['success']) {
                throw new LicenseInvalidException($data['message'] ?? 'Activation failed');
            }

            // Store offline token if provided
            if (isset($data['offline_token'])) {
                $this->cache->put(
                    $this->getCacheKey('offline_token'),
                    $data['offline_token'],
                    0 // Store indefinitely
                );
            }

            // Cache license data
            if (isset($data['license'])) {
                $this->cacheLicenseData($data['license']);
            }

            $this->fireEvent('license_activated', new LicenseActivated(
                $this->config['license_key'],
                $data
            ));

            $this->log('info', 'License activated successfully');

            return $data;
        } catch (GuzzleException $e) {
            $this->log('error', 'License activation failed', [
                'error' => $e->getMessage()
            ]);

            throw new LicenseServerException('Activation failed: ' . $e->getMessage());
        }
    }

    /**
     * Deactivate license
     *
     * @param string|null $reason
     * @return array
     * @throws LicenseException
     */
    public function deactivate(?string $reason = null): array
    {
        try {
            $response = $this->httpClient->post('api/license/deactivate', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'hardware_fingerprint' => $this->getHardwareFingerprint(),
                    'reason' => $reason,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Clear cache
            $this->clearCache();

            $this->fireEvent('license_deactivated', new LicenseDeactivated(
                $this->config['license_key'],
                $reason
            ));

            $this->log('info', 'License deactivated successfully');

            return $data;
        } catch (GuzzleException $e) {
            $this->log('error', 'License deactivation failed', [
                'error' => $e->getMessage()
            ]);

            throw new LicenseServerException('Deactivation failed: ' . $e->getMessage());
        }
    }

    /**
     * Send heartbeat ping to server
     *
     * @return bool
     */
    public function heartbeat(): bool
    {
        // Check if heartbeat is due
        if (!$this->isHeartbeatDue()) {
            return true;
        }

        try {
            $response = $this->httpClient->post('api/license/heartbeat', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'hardware_fingerprint' => $this->getHardwareFingerprint(),
                    'system_info' => $this->getSystemInfo(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Update last heartbeat timestamp
            $this->lastHeartbeat = time();
            $this->cache->put($this->getCacheKey('last_heartbeat'), $this->lastHeartbeat, 0);

            // Update offline token if provided
            if (isset($data['offline_token'])) {
                $this->cache->put(
                    $this->getCacheKey('offline_token'),
                    $data['offline_token'],
                    0
                );
            }

            $this->log('info', 'Heartbeat sent successfully');

            return true;
        } catch (\Exception $e) {
            $this->log('warning', 'Heartbeat failed', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Check if heartbeat is due
     *
     * @return bool
     */
    protected function isHeartbeatDue(): bool
    {
        if (!$this->lastHeartbeat) {
            return true;
        }

        $interval = $this->config['heartbeat_interval'] ?? 86400;
        return (time() - $this->lastHeartbeat) >= $interval;
    }

    /**
     * Get hardware fingerprint
     *
     * @return string
     */
    protected function getHardwareFingerprint(): string
    {
        // Use custom fingerprint if provided
        if (!empty($this->config['hardware_fingerprint'])) {
            return $this->config['hardware_fingerprint'];
        }

        // Auto-generate if enabled
        if ($this->config['auto_fingerprint'] ?? true) {
            return $this->fingerprintService->generate();
        }

        return 'none';
    }

    /**
     * Get system information
     *
     * @return array
     */
    protected function getSystemInfo(): array
    {
        $systemInfoConfig = $this->config['system_info'] ?? [];

        if (!($systemInfoConfig['enabled'] ?? true)) {
            return [];
        }

        $info = [];

        if ($systemInfoConfig['include_php_version'] ?? true) {
            $info['php_version'] = PHP_VERSION;
        }

        if ($systemInfoConfig['include_laravel_version'] ?? true) {
            $info['laravel_version'] = app()->version();
        }

        if ($systemInfoConfig['include_server_software'] ?? true) {
            $info['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
        }

        return $info;
    }

    /**
     * Cache license data
     *
     * @param array $data
     * @return void
     */
    protected function cacheLicenseData(array $data): void
    {
        $this->licenseData = $data;

        $ttl = $this->config['cache_ttl'] ?? 86400;

        if ($ttl > 0) {
            $this->cache->put($this->getCacheKey('license_data'), $data, $ttl);
        } elseif ($ttl === 0) {
            // Don't cache
            return;
        } else {
            // Cache indefinitely
            $this->cache->put($this->getCacheKey('license_data'), $data, 0);
        }

        // Store offline token if provided
        if (isset($data['offline_token'])) {
            $this->cache->put(
                $this->getCacheKey('offline_token'),
                $data['offline_token'],
                0 // Store indefinitely
            );
        }
    }

    /**
     * Check if cached data can be used
     *
     * @return bool
     */
    protected function canUseCachedData(): bool
    {
        if (!$this->licenseData) {
            return false;
        }

        // Check if cached data is still valid
        if (isset($this->licenseData['expires_at'])) {
            $expiresAt = Carbon::parse($this->licenseData['expires_at']);

            // Allow grace period
            $gracePeriodDays = config('license.server.grace_period_days', 7);
            $expiryWithGrace = $expiresAt->addDays($gracePeriodDays);

            if (Carbon::now()->greaterThan($expiryWithGrace)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear cache
     *
     * @return void
     */
    protected function clearCache(): void
    {
        $this->cache->forget($this->getCacheKey('license_data'));
        $this->cache->forget($this->getCacheKey('offline_token'));
        $this->cache->forget($this->getCacheKey('features'));
        $this->cache->forget($this->getCacheKey('last_heartbeat'));

        $this->licenseData = null;
        $this->lastHeartbeat = null;
    }

    /**
     * Get JWT secret
     *
     * @return string
     */
    protected function getJwtSecret(): string
    {
        return config('license.server.jwt_secret', config('app.key'));
    }

    /**
     * Get JWT algorithm
     *
     * @return string
     */
    protected function getJwtAlgorithm(): string
    {
        return config('license.server.jwt_algorithm', 'HS256');
    }

    /**
     * Fire event
     *
     * @param string $eventName
     * @param mixed $event
     * @return void
     */
    protected function fireEvent(string $eventName, $event): void
    {
        if (!config('license.events.enabled', true)) {
            return;
        }

        if (config("license.events.fire.{$eventName}", true)) {
            Event::dispatch($event);
        }
    }

    /**
     * Log message
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!config('license.logging.enabled', true)) {
            return;
        }

        $channel = config('license.logging.channel', 'stack');

        Log::channel($channel)->$level($message, $context);
    }

    /**
     * Handle validation failure
     *
     * @param LicenseException $exception
     * @return void
     * @throws LicenseException
     */
    protected function handleValidationFailure(LicenseException $exception): void
    {
        $onFailure = $this->config['on_failure'] ?? 'throw';

        switch ($onFailure) {
            case 'throw':
                throw $exception;

            case 'log':
                $this->log('error', 'License validation failed', [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ]);
                break;

            case 'silent':
                // Do nothing
                break;
        }
    }

    /**
     * Get usage statistics from server
     *
     * @param int $days Number of days to retrieve stats for (default: 30)
     * @return array
     * @throws LicenseException
     */
    public function getUsageStats(int $days = 30): array
    {
        try {
            $response = $this->httpClient->post('api/license/usage-stats', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'days' => $days,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $this->log('info', 'Usage statistics retrieved successfully', [
                'days' => $days,
            ]);

            return $data;
        } catch (GuzzleException $e) {
            $this->log('error', 'Failed to retrieve usage statistics', [
                'error' => $e->getMessage()
            ]);

            // Return empty stats on failure
            return [
                'total_events' => 0,
                'api_requests_today' => 0,
                'popular_features' => [],
                'daily_usage' => [],
            ];
        }
    }

    /**
     * Get validation statistics from server
     *
     * @param int $days Number of days to retrieve stats for (default: 30)
     * @return array
     * @throws LicenseException
     */
    public function getValidationStats(int $days = 30): array
    {
        try {
            $response = $this->httpClient->post('api/license/validation-stats-detailed', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'days' => $days,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $this->log('info', 'Validation statistics retrieved successfully', [
                'days' => $days,
            ]);

            return $data;
        } catch (GuzzleException $e) {
            $this->log('error', 'Failed to retrieve validation statistics', [
                'error' => $e->getMessage()
            ]);

            // Return empty stats on failure
            return [
                'total_validations_30d' => 0,
                'successful_validations_30d' => 0,
                'failed_validations_30d' => 0,
                'success_rate_30d' => 0,
                'last_validation_at' => null,
                'active_devices' => 0,
            ];
        }
    }

    /**
     * Log a validation event to the server
     *
     * @param string $validationType Type of validation (online, offline, jwt, cache)
     * @param string $result Result of validation (success, failed)
     * @param string|null $clientIdentifier Optional client identifier
     * @param array|null $context Optional additional context
     * @return bool
     */
    public function logValidation(
        string $validationType,
        string $result,
        ?string $clientIdentifier = null,
        ?array $context = null
    ): bool {
        try {
            $this->httpClient->post('api/license/log-validation', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'hardware_fingerprint' => $this->getHardwareFingerprint(),
                    'validation_type' => $validationType,
                    'result' => $result,
                    'client_identifier' => $clientIdentifier,
                    'context' => $context,
                ],
            ]);

            return true;
        } catch (\Exception $e) {
            $this->log('warning', 'Failed to log validation to server', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Log a usage event to the server
     *
     * @param string $eventType Type of event (feature_access, api_request, page_view)
     * @param string|null $featureKey Feature key being accessed
     * @param string|null $action Action being performed (create, read, update, delete)
     * @param string|null $clientIdentifier Optional client identifier
     * @param string|null $userId Optional user ID
     * @param array|null $metadata Optional additional metadata
     * @return bool
     */
    public function logUsage(
        string $eventType,
        ?string $featureKey = null,
        ?string $action = null,
        ?string $clientIdentifier = null,
        ?string $userId = null,
        ?array $metadata = null
    ): bool {
        try {
            $this->httpClient->post('api/license/log-usage', [
                'json' => [
                    'license_key' => $this->config['license_key'],
                    'event_type' => $eventType,
                    'feature_key' => $featureKey,
                    'action' => $action,
                    'client_identifier' => $clientIdentifier,
                    'user_id' => $userId,
                    'metadata' => $metadata,
                ],
            ]);

            return true;
        } catch (\Exception $e) {
            $this->log('warning', 'Failed to log usage event to server', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}
