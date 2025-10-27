<?php

namespace Westel\License\Contracts;

interface LicenseServiceInterface
{
    /**
     * Validate the license
     */
    public function validate(): array;

    /**
     * Check if license is valid
     */
    public function isValid(): bool;

    /**
     * Check if license has a specific feature
     */
    public function hasFeature(string $featureKey): bool;

    /**
     * Check if feature can be used (with limit validation)
     */
    public function canUseFeature(string $featureKey, ?int $currentUsage = null): bool;

    /**
     * Get all available features
     */
    public function getFeatures(): array;

    /**
     * Get feature configuration
     */
    public function getFeatureConfig(string $featureKey): ?array;

    /**
     * Get license information
     */
    public function getLicenseInfo(): array;

    /**
     * Get license status
     */
    public function getStatus(): string;

    /**
     * Refresh license data from server
     */
    public function refresh(): array;

    /**
     * Get days until license expiry
     */
    public function getDaysUntilExpiry(): ?int;

    /**
     * Get usage statistics from server
     */
    public function getUsageStats(int $days = 30): array;

    /**
     * Get validation statistics from server
     */
    public function getValidationStats(int $days = 30): array;

    /**
     * Log a validation event to the server
     */
    public function logValidation(
        string $validationType,
        string $result,
        ?string $clientIdentifier = null,
        ?array $context = null
    ): bool;

    /**
     * Log a usage event to the server
     */
    public function logUsage(
        string $eventType,
        ?string $featureKey = null,
        ?string $action = null,
        ?string $clientIdentifier = null,
        ?string $userId = null,
        ?array $metadata = null
    ): bool;
}
