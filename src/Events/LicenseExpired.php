<?php

namespace Westel\License\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseExpired
{
    use Dispatchable, SerializesModels;

    /**
     * License data
     *
     * @var array
     */
    public array $licenseData;

    /**
     * Whether in grace period
     *
     * @var bool
     */
    public bool $inGracePeriod;

    /**
     * Create a new event instance
     *
     * @param array $licenseData
     * @param bool $inGracePeriod
     */
    public function __construct(array $licenseData, bool $inGracePeriod = false)
    {
        $this->licenseData = $licenseData;
        $this->inGracePeriod = $inGracePeriod;
    }
}
