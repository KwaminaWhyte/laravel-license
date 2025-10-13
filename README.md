# Westel Laravel License

A comprehensive Laravel package for license management and feature gating with server-client architecture, hardware fingerprinting, and offline validation.

## Features

- **Dual Mode**: Works as both license server and client
- **Hardware Fingerprinting**: Secure device binding
- **Offline Validation**: JWT-based validation for up to 30 days offline
- **Feature Gating**: Control feature access per license
- **Grace Periods**: Configurable grace periods for expired licenses
- **Multi-Product Support**: Manage licenses for multiple SaaS products
- **RESTful API**: Complete API for external integrations
- **Middleware**: Easy route and feature protection
- **Comprehensive Logging**: Track all validation attempts
- **🎨 UI Components** (Optional): Pre-built React/Inertia.js interface for license management
- **🔐 Encrypted Storage**: Database-backed license configuration with encryption

## Installation

Install the package via composer:

```bash
composer require westel/laravel-license
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=license-config
```

Publish and run migrations (Server Mode only):

```bash
php artisan vendor:publish --tag=license-migrations
php artisan migrate
```

## Optional: UI-Based License Management

**NEW!** The package now includes optional UI components for managing license settings through a web interface instead of `.env` files.

See [UI-INSTALLATION.md](UI-INSTALLATION.md) for detailed setup instructions.

**Quick setup:**

```bash
# Publish UI assets (React/Inertia.js)
php artisan vendor:publish --tag=license-ui-react
php artisan vendor:publish --tag=license-controllers
php artisan vendor:publish --tag=license-config-migration
php artisan vendor:publish --tag=license-config-model

# Run migration
php artisan migrate

# Build frontend
npm run build
```

Features:
- 🔐 Encrypted license key storage
- 🧪 Connection testing
- 🎨 Beautiful React/Inertia UI
- 🔄 Live configuration updates
- ✅ Admin-only access

## Configuration

Configure the package in `config/license.php`:

### Server Mode

```php
return [
    'mode' => 'server', // or 'client'

    // Server-specific settings
    'jwt_secret' => env('LICENSE_JWT_SECRET', env('APP_KEY')),
    'grace_period_days' => 7,
    'offline_validation_days' => 30,
    'default_activation_limit' => 5,
];
```

### Client Mode

```php
return [
    'mode' => 'client',

    // Client-specific settings
    'server_url' => env('LICENSE_SERVER_URL', 'https://license.yourdomain.com'),
    'license_key' => env('LICENSE_KEY'),
    'product_id' => env('LICENSE_PRODUCT_ID'),
    'cache_ttl' => 86400, // 24 hours
    'offline_mode' => true,
];
```

## Usage

### Server Mode

#### 1. Create Products and Licenses

```php
use Westel\License\Models\Product;
use Westel\License\Models\License;

// Create a product
$product = Product::create([
    'name' => 'My SaaS Product',
    'version' => '1.0.0',
    'features' => ['feature1', 'feature2', 'feature3'],
    'default_activation_limit' => 5,
    'grace_period_days' => 7,
    'offline_validation_days' => 30,
]);

// Generate a license
$license = License::create([
    'user_id' => $user->id,
    'product_id' => $product->id,
    'license_key' => License::generateLicenseKey(),
    'status' => 'active',
    'expires_at' => now()->addYear(),
    'activation_limit' => 5,
]);
```

#### 2. API Endpoints

The package automatically registers these API endpoints:

```
POST   /api/license/validate         - Validate license and get offline token
POST   /api/license/activate         - Activate license on new device
POST   /api/license/deactivate       - Deactivate device
POST   /api/license/heartbeat        - Quick validation check
GET    /api/license/status           - Get license status
GET    /api/license/features         - Get available features
POST   /api/license/validate-feature - Validate feature access
GET    /api/license/tiers            - Get available products/tiers
```

### Client Mode

#### 1. Initialize License Client

```php
use Westel\License\Facades\License;

// Validate license (uses cache if available)
$result = License::validate();

if ($result['valid']) {
    // License is valid
    $features = $result['features'];
} else {
    // Handle invalid license
    return redirect()->route('license.invalid');
}
```

#### 2. Check Features

```php
use Westel\License\Facades\License;

if (License::hasFeature('advanced_reports')) {
    // Feature is available
}

// Check feature with limits
if (License::canUseFeature('api_calls', $currentUsage)) {
    // Feature is within limits
}
```

#### 3. Middleware Protection

Protect routes:

```php
// In routes/web.php
Route::middleware(['license.valid'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// Protect specific features
Route::middleware(['license.feature:advanced_reports'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index']);
});
```

#### 4. Blade Directives

```blade
@license('feature_key')
    <!-- This content only shows if feature is available -->
    <button>Advanced Feature</button>
@endlicense

@licenseValid
    <!-- Shows only when license is valid -->
    <p>Your license is active until {{ $licenseExpiry }}</p>
@endlicenseValid
```

#### 5. Hardware Fingerprinting

```php
use Westel\License\Services\HardwareFingerprintService;

$fingerprint = app(HardwareFingerprintService::class)->generate();
```

#### 6. Offline Mode

The package automatically handles offline validation:

```php
// First validation (online) stores JWT token
License::validate();

// Subsequent validations use cached token
// Valid for configured offline_validation_days
License::validate(); // Uses offline token if server unreachable
```

## Advanced Usage

### Custom Feature Validation

```php
use Westel\License\Services\LicenseService;

$licenseService = app(LicenseService::class);

// Validate feature with custom logic
$result = $licenseService->validateFeature('custom_feature', [
    'current_usage' => 100,
    'additional_data' => ['key' => 'value']
]);

if ($result['allowed']) {
    // Proceed with feature
    $remaining = $result['remaining'];
}
```

### License Events

Listen for license events:

```php
use Westel\License\Events\LicenseValidated;
use Westel\License\Events\LicenseExpired;
use Westel\License\Events\LicenseActivated;

// In EventServiceProvider
protected $listen = [
    LicenseValidated::class => [
        SendLicenseValidatedNotification::class,
    ],
    LicenseExpired::class => [
        NotifyLicenseExpired::class,
    ],
];
```

### Grace Period Handling

```php
$license = License::find($licenseId);

if ($license->isExpired() && $license->isInGracePeriod()) {
    // Show warning to user
    $daysRemaining = $license->getGracePeriodDaysRemaining();
    flash("Your license expired. {$daysRemaining} days remaining in grace period.");
}
```

## API Reference

### Client SDK Methods

```php
// Validation
License::validate(): array
License::isValid(): bool
License::getStatus(): string

// Features
License::hasFeature(string $featureKey): bool
License::canUseFeature(string $featureKey, ?int $currentUsage = null): bool
License::getFeatures(): array
License::getFeatureConfig(string $featureKey): ?array

// Information
License::getLicenseInfo(): array
License::getExpiryDate(): ?Carbon
License::getDaysUntilExpiry(): ?int

// Actions
License::activate(string $hardwareFingerprint): array
License::deactivate(): array
License::refresh(): array
```

### Server API Endpoints

#### POST /api/license/validate

**Request:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "hardware_fingerprint": "abc123...",
  "product_id": "uuid",
  "system_info": {}
}
```

**Response:**

```json
{
  "valid": true,
  "status": "active",
  "license": {
    "license_key": "XXXX-XXXX-XXXX-XXXX",
    "expires_at": "2025-12-31T23:59:59Z",
    "features": ["feature1", "feature2"]
  },
  "offline_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "next_validation": "2025-01-13T12:00:00Z"
}
```

## Testing

Run the package tests:

```bash
composer test
```

## Security

- Hardware fingerprints are hashed using SHA-256
- JWT tokens are signed with HS256 (or RS256 for production)
- All API requests support CORS configuration
- Validation attempts are logged for audit trails

## Migration from Existing System

If you have an existing license system:

1. Backup your database
2. Install the package
3. Run migrations
4. Map your existing data to package models
5. Update API endpoints to use package routes
6. Test thoroughly before deploying

## Troubleshooting

### License validation fails in offline mode

Check that:

- JWT secret is correctly configured
- Offline token was generated during last online validation
- Token has not expired (check `offline_validation_days`)

### Hardware fingerprint mismatch

- Ensure consistent fingerprint generation
- Check tolerance settings in config
- Review system info being sent

### Feature gating not working

- Verify feature keys match exactly
- Check license is active and not expired
- Ensure features are assigned to product/plan

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

For support, email stanleyotabil10@gmail.com or open an issue on GitHub.

## Credits

- [Westel Team](https://github.com/westel)
- [All Contributors](../../contributors)
