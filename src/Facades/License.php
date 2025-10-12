<?php

namespace Westel\License\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * License Facade
 *
 * Provides static access to the LicenseService.
 *
 * @method static array validate()
 * @method static bool isValid()
 * @method static bool hasFeature(string $featureKey)
 * @method static bool canUseFeature(string $featureKey, ?int $currentUsage = null)
 * @method static array getFeatures()
 * @method static array|null getFeatureConfig(string $featureKey)
 * @method static array getLicenseInfo()
 * @method static string getStatus()
 * @method static array refresh()
 * @method static int|null getDaysUntilExpiry()
 * @method static array activate() (Client mode only)
 * @method static array deactivate(?string $reason = null) (Client mode only)
 * @method static bool heartbeat() (Client mode only)
 *
 * @see \Westel\License\Contracts\LicenseServiceInterface
 */
class License extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'license';
    }
}
