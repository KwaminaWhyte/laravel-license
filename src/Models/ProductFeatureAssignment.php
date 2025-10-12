<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFeatureAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'feature_definition_id',
        'is_enabled',
        'configuration',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'configuration' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function featureDefinition(): BelongsTo
    {
        return $this->belongsTo(FeatureDefinition::class);
    }

    public function getConfiguredValue(string $key, $default = null)
    {
        return $this->configuration[$key] ?? $default;
    }

    public function getDisplayValueAttribute(): string
    {
        $config = $this->configuration ?? [];

        if (isset($config['limit'])) {
            $limit = $config['limit'];
            return $limit === -1 ? 'Unlimited' : (string) $limit;
        }

        if (isset($config['quota'])) {
            return (string) $config['quota'];
        }

        return 'Enabled';
    }
}
