<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class License extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_key',
        'user_id',
        'product_id',
        'subscription_id',
        'status',
        'activated_at',
        'expires_at',
        'activation_limit',
        'activation_count',
        'hardware_fingerprint',
        'grace_period_days',
        'last_validated_at',
        'metadata',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'activation_limit' => 'integer',
        'activation_count' => 'integer',
        'grace_period_days' => 'integer',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(LicenseValidation::class);
    }

    public function activeActivations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class)->where('is_active', true);
    }

    public static function generateLicenseKey(?string $prefix = null, ?string $suffix = null): string
    {
        $normalizedPrefix = static::normalizeKeySegment($prefix, 8, 'WESL');
        $normalizedSuffix = static::normalizeKeySegment($suffix, 4, null);

        do {
            $core = strtoupper(implode('-', [
                Str::upper(Str::random(4)),
                Str::upper(Str::random(4)),
                Str::upper(Str::random(4)),
                Str::upper(Str::random(4)),
            ]));

            $licenseKey = $normalizedPrefix . '-' . $core;

            if ($normalizedSuffix) {
                $licenseKey .= '-' . $normalizedSuffix;
            }
        } while (static::query()->where('license_key', $licenseKey)->exists());

        return $licenseKey;
    }

    protected static function normalizeKeySegment(?string $segment, int $maxLength, ?string $fallback = null): ?string
    {
        $candidate = $segment !== null ? $segment : $fallback;

        if ($candidate === null) {
            return null;
        }

        $candidate = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $candidate) ?? '');

        if ($candidate === '') {
            return $fallback !== null ? static::normalizeKeySegment($fallback, $maxLength, null) : null;
        }

        return substr($candidate, 0, $maxLength);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function canActivate(string $hardwareFingerprint): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        // Check if already activated on this hardware
        if ($this->hardware_fingerprint === $hardwareFingerprint) {
            return true;
        }

        // Check activation limits
        $activeCount = $this->activeActivations()->count();
        return $activeCount < $this->activation_limit;
    }

    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return (int) Carbon::now()->diffInDays($this->expires_at, false);
    }

    public function isInGracePeriod(): bool
    {
        if (!$this->isExpired()) {
            return false;
        }

        $gracePeriodDays = $this->product->grace_period_days ?? config('license.server.grace_period_days', 7);
        $gracePeriodEnd = $this->expires_at->addDays($gracePeriodDays);

        return Carbon::now()->isBefore($gracePeriodEnd);
    }

    public function hasFeature(string $featureKey): bool
    {
        return $this->product ? $this->product->hasFeature($featureKey) : false;
    }

    public function getAvailableFeatures(): array
    {
        return $this->product ? $this->product->getFeaturesList() : [];
    }
}
