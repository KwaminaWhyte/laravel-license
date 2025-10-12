<?php

namespace Westel\License\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseDeactivated
{
    use Dispatchable, SerializesModels;

    /**
     * License key
     *
     * @var string
     */
    public string $licenseKey;

    /**
     * Deactivation reason
     *
     * @var string|null
     */
    public ?string $reason;

    /**
     * Create a new event instance
     *
     * @param string $licenseKey
     * @param string|null $reason
     */
    public function __construct(string $licenseKey, ?string $reason = null)
    {
        $this->licenseKey = $licenseKey;
        $this->reason = $reason;
    }
}
