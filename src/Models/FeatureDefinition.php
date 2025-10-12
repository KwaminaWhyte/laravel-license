<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'type',
        'default_configuration',
        'is_active',
    ];

    protected $casts = [
        'default_configuration' => 'array',
        'is_active' => 'boolean',
    ];

    public function productAssignments(): HasMany
    {
        return $this->hasMany(ProductFeatureAssignment::class);
    }
}
