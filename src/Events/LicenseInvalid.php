<?php

namespace Westel\License\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseInvalid
{
    use Dispatchable, SerializesModels;

    /**
     * Reason for invalidity
     *
     * @var string
     */
    public string $reason;

    /**
     * Additional data
     *
     * @var array
     */
    public array $data;

    /**
     * Create a new event instance
     *
     * @param string $reason
     * @param array $data
     */
    public function __construct(string $reason, array $data = [])
    {
        $this->reason = $reason;
        $this->data = $data;
    }
}
