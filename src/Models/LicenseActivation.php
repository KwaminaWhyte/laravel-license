<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_id',
        'hardware_fingerprint',
        'ip_address',
        'system_info',
        'activated_at',
        'last_checked_at',
        'is_active',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'system_info' => 'array',
        'is_active' => 'boolean',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function updateHeartbeat(): void
    {
        $this->update(['last_checked_at' => now()]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function getSystemInfoAttribute($value): array
    {
        return $value ? json_decode($value, true) : [];
    }
}
