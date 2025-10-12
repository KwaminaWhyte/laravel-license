<?php

namespace Westel\License\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseActivated
{
    use Dispatchable, SerializesModels;

    /**
     * License key
     *
     * @var string
     */
    public string $licenseKey;

    /**
     * Activation data
     *
     * @var array
     */
    public array $activationData;

    /**
     * Create a new event instance
     *
     * @param string $licenseKey
     * @param array $activationData
     */
    public function __construct(string $licenseKey, array $activationData = [])
    {
        $this->licenseKey = $licenseKey;
        $this->activationData = $activationData;
    }
}
