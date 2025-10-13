<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class LicenseConfiguration extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'is_encrypted',
        'description',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    /**
     * Get the decrypted value
     */
    public function getDecryptedValueAttribute()
    {
        if (!$this->value) {
            return null;
        }

        if ($this->is_encrypted) {
            try {
                return Crypt::decryptString($this->value);
            } catch (\Exception $e) {
                return null;
            }
        }

        return $this->castValue($this->value);
    }

    /**
     * Set encrypted value
     */
    public function setEncryptedValue($value, bool $encrypt = true): void
    {
        $this->is_encrypted = $encrypt;

        if ($encrypt) {
            $this->value = Crypt::encryptString((string) $value);
        } else {
            $this->value = (string) $value;
        }
    }

    /**
     * Cast value to appropriate type
     */
    protected function castValue(string $value)
    {
        switch ($this->type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            default:
                return $value;
        }
    }

    /**
     * Get a configuration value
     */
    public static function get(string $key, $default = null)
    {
        $config = static::where('key', $key)->first();

        if (!$config) {
            return $default;
        }

        return $config->decrypted_value ?? $default;
    }

    /**
     * Set a configuration value
     */
    public static function set(string $key, $value, bool $encrypt = false, string $type = 'string', ?string $description = null): self
    {
        $config = static::firstOrNew(['key' => $key]);
        $config->type = $type;
        $config->description = $description;
        $config->setEncryptedValue($value, $encrypt);
        $config->save();

        return $config;
    }
}
