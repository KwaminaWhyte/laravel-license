<?php

namespace Westel\License\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseValidated
{
    use Dispatchable, SerializesModels;

    /**
     * License data
     *
     * @var array
     */
    public array $licenseData;

    /**
     * Validation source (online, offline, cache)
     *
     * @var string
     */
    public string $source;

    /**
     * Create a new event instance
     *
     * @param array $licenseData
     * @param string $source
     */
    public function __construct(array $licenseData, string $source = 'online')
    {
        $this->licenseData = $licenseData;
        $this->source = $source;
    }
}
