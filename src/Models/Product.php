<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'version',
        'description',
        'features',
        'default_activation_limit',
        'grace_period_days',
        'offline_validation_days',
        'is_active',
        'pricing_model',
        'base_price',
        'currency',
        'trial_days',
        'category',
        'tags',
        'website_url',
        'documentation_url',
        'support_email',
        'metadata',
    ];

    protected $casts = [
        'features' => 'array',
        'tags' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'default_activation_limit' => 'integer',
        'grace_period_days' => 'integer',
        'offline_validation_days' => 'integer',
        'trial_days' => 'integer',
        'base_price' => 'decimal:2',
    ];

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function activeLicenses(): HasMany
    {
        return $this->hasMany(License::class)->where('status', 'active');
    }

    public function featureAssignments(): HasMany
    {
        return $this->hasMany(ProductFeatureAssignment::class);
    }

    public function enabledFeatureAssignments(): HasMany
    {
        return $this->hasMany(ProductFeatureAssignment::class)->where('is_enabled', true);
    }

    public function hasFeature(string $feature): bool
    {
        // First check in new feature system
        $hasAssignedFeature = $this->enabledFeatureAssignments()
            ->whereHas('featureDefinition', function ($query) use ($feature) {
                $query->where('key', $feature);
            })
            ->exists();

        if ($hasAssignedFeature) {
            return true;
        }

        // Fallback to legacy features array for backward compatibility
        $legacyFeatures = $this->features ?? [];

        // Only check legacy features if it's an indexed array of strings
        if (!empty($legacyFeatures) && array_is_list($legacyFeatures)) {
            return in_array($feature, $legacyFeatures);
        }

        return false;
    }

    public function getFeaturesList(): array
    {
        // Get new assigned features
        $assignedFeatures = $this->enabledFeatureAssignments()
            ->with('featureDefinition')
            ->get()
            ->pluck('featureDefinition.key')
            ->toArray();

        // Get legacy features
        $legacyFeatures = $this->features ?? [];

        // If legacy features is an associative array (metadata), return only assigned features
        // Legacy features should be an indexed array of feature key strings
        if (!empty($legacyFeatures) && !array_is_list($legacyFeatures)) {
            return $assignedFeatures;
        }

        // Merge and deduplicate
        return array_unique(array_merge($assignedFeatures, $legacyFeatures));
    }

    public function getStructuredFeatures(): array
    {
        return $this->enabledFeatureAssignments()
            ->with('featureDefinition')
            ->get()
            ->map(function ($assignment) {
                return [
                    'key' => $assignment->featureDefinition->key,
                    'name' => $assignment->featureDefinition->name,
                    'category' => $assignment->featureDefinition->category,
                    'type' => $assignment->featureDefinition->type,
                    'configuration' => $assignment->configuration,
                    'display_value' => $assignment->display_value,
                ];
            })
            ->groupBy('category')
            ->toArray();
    }
}
