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
}
