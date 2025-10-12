# ServerLicenseService Documentation

## Overview

The `ServerLicenseService` class is the core service for SERVER MODE operations in the Laravel License package. It handles license validation, activation/deactivation, JWT offline token generation, feature access control, and heartbeat monitoring.

## Architecture

### Implementation

- **Implements**: `Westel\License\Contracts\LicenseServiceInterface`
- **Namespace**: `Westel\License\Services`
- **Dependencies**:
  - `Firebase\JWT\JWT` for offline token generation
  - Laravel Eloquent Models from `Westel\License\Models\*`

### Key Features

1. **License Validation** - Comprehensive validation with hardware fingerprinting
2. **Activation Management** - Create and manage device activations
3. **Offline Token Generation** - JWT tokens for 30-day offline validation
4. **Grace Period Handling** - Automatic grace period support for expired licenses
5. **Feature Access Control** - Check feature availability and limits
6. **Heartbeat Validation** - Regular license health checks
7. **Logging** - Comprehensive validation attempt logging

## Installation & Setup

### 1. Configuration

The service reads from `config/license.php`:

```php
'mode' => 'server', // Must be 'server' for this service

'server' => [
    'jwt_secret' => env('LICENSE_JWT_SECRET', env('APP_KEY')),
    'jwt_algorithm' => env('LICENSE_JWT_ALGORITHM', 'HS256'),
    'grace_period_days' => (int) env('LICENSE_GRACE_PERIOD_DAYS', 7),
    'offline_validation_days' => (int) env('LICENSE_OFFLINE_VALIDATION_DAYS', 30),
    'default_activation_limit' => (int) env('LICENSE_DEFAULT_ACTIVATION_LIMIT', 5),
],
```

### 2. Environment Variables

```env
LICENSE_MODE=server
LICENSE_JWT_SECRET=your-secret-key
LICENSE_JWT_ALGORITHM=HS256
LICENSE_GRACE_PERIOD_DAYS=7
LICENSE_OFFLINE_VALIDATION_DAYS=30
LICENSE_DEFAULT_ACTIVATION_LIMIT=5
LICENSE_LOG_VALIDATIONS=true
```

## Usage Examples

### Basic License Validation

```php
use Westel\License\Services\ServerLicenseService;

// Create service instance
$service = new ServerLicenseService();

// Validate license
$result = $service->validateLicense(
    licenseKey: 'ABCD1234-EFGH5678-IJKL9012-MNOP3456',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99',
    productId: 'product-uuid-here',
    systemInfo: [
        'os' => 'Linux',
        'php_version' => '8.2.0',
        'laravel_version' => '11.0'
    ]
);

// Check result
if ($result['valid']) {
    echo "License is valid!";
    echo "Status: " . $result['status']; // 'active' or 'grace_period'
    echo "Offline Token: " . $result['offline_token'];
} else {
    echo "License validation failed!";
    echo "Error: " . $result['error'];
    echo "Code: " . $result['error_code'];
}
```

### Using Interface Methods

```php
use Westel\License\Services\ServerLicenseService;

$service = new ServerLicenseService();

// Set license parameters
$service->setLicenseParams(
    licenseKey: 'ABCD1234-EFGH5678-IJKL9012-MNOP3456',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99',
    productId: 'product-uuid-here'
);

// Validate
$result = $service->validate();

// Check if valid
if ($service->isValid()) {
    // Get license info
    $info = $service->getLicenseInfo();

    // Check features
    if ($service->hasFeature('advanced_reporting')) {
        echo "Advanced reporting is available!";
    }

    // Get all features
    $features = $service->getFeatures();

    // Get days until expiry
    $daysLeft = $service->getDaysUntilExpiry();
    echo "License expires in {$daysLeft} days";
}
```

### License Activation

```php
$result = $service->activateLicense(
    licenseKey: 'ABCD1234-EFGH5678-IJKL9012-MNOP3456',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99',
    productId: 'product-uuid-here',
    systemInfo: ['os' => 'Linux', 'hostname' => 'server-01']
);

if ($result['success']) {
    echo "Activation successful!";
    echo "Activation ID: " . $result['activation_id'];
    echo "Offline Token: " . $result['offline_token'];
} else {
    echo "Activation failed: " . $result['error'];
}
```

### License Deactivation

```php
$result = $service->deactivateLicense(
    licenseKey: 'ABCD1234-EFGH5678-IJKL9012-MNOP3456',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99'
);

if ($result['success']) {
    echo "License deactivated successfully!";
}
```

### Heartbeat Validation

```php
use Illuminate\Http\Request;

$request = Request::capture();

$result = $service->heartbeat(
    licenseKey: 'ABCD1234-EFGH5678-IJKL9012-MNOP3456',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99',
    request: $request
);

if ($result['valid']) {
    echo "Status: " . $result['status'];
    echo "Features: " . json_encode($result['features']);
    echo "Expires: " . $result['expires_at'];
}
```

### Feature Access Control

```php
// Check if license has a feature
if ($service->hasFeature('multi_location')) {
    echo "Multi-location feature is enabled";
}

// Check if feature can be used (with usage limits)
$currentUsage = 5; // e.g., 5 locations already created
if ($service->canUseFeature('multi_location', $currentUsage)) {
    echo "Can create more locations";
} else {
    echo "Location limit reached";
}

// Get feature configuration
$config = $service->getFeatureConfig('multi_location');
echo "Limit: " . $config['limit'];
echo "Type: " . $config['type'];
```

### Offline Token Verification

```php
// Verify an offline JWT token
$result = $service->verifyOfflineToken(
    token: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
    hardwareFingerprint: '5f4dcc3b5aa765d61d8327deb882cf99'
);

if ($result['valid']) {
    echo "Offline validation successful!";
    echo "License Key: " . $result['license_key'];
    echo "Features: " . json_encode($result['features']);
} else {
    echo "Offline validation failed: " . $result['error'];
}
```

## API Response Formats

### Successful Validation Response

```php
[
    'valid' => true,
    'status' => 'active', // or 'grace_period'
    'license' => [
        'id' => 'uuid',
        'license_key' => 'ABCD-EFGH-IJKL-MNOP',
        'status' => 'active',
        'activated_at' => '2024-01-01T00:00:00.000000Z',
        'expires_at' => '2025-01-01T00:00:00.000000Z',
        'activation_limit' => 5,
        'activation_count' => 2,
        'days_until_expiry' => 365,
        'is_expired' => false,
        'is_in_grace_period' => false,
        'product' => [
            'id' => 'uuid',
            'name' => 'POS System Pro',
            'version' => '1.0.0',
            'features' => ['sales', 'inventory', 'reporting'],
            'structured_features' => [
                'core' => [...],
                'advanced' => [...]
            ]
        ]
    ],
    'offline_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
    'next_validation' => '2024-01-02T00:00:00.000000Z'
]
```

### Grace Period Response

```php
[
    'valid' => true,
    'status' => 'grace_period',
    'message' => 'License expired but in grace period',
    'grace_period_days' => 7,
    'days_expired' => 3,
    'license' => [...],
    'offline_token' => '...',
    'next_validation' => '...'
]
```

### Error Responses

```php
// License Not Found
[
    'valid' => false,
    'error' => 'License not found',
    'error_code' => 'LICENSE_NOT_FOUND'
]

// License Inactive
[
    'valid' => false,
    'error' => 'License is not active',
    'error_code' => 'LICENSE_INACTIVE',
    'status' => 'suspended'
]

// License Expired
[
    'valid' => false,
    'error' => 'License has expired',
    'error_code' => 'LICENSE_EXPIRED',
    'expires_at' => '2024-01-01T00:00:00.000000Z'
]

// Activation Limit Exceeded
[
    'valid' => false,
    'error' => 'Activation limit exceeded',
    'error_code' => 'ACTIVATION_LIMIT_EXCEEDED',
    'activation_limit' => 5,
    'active_count' => 5
]

// Feature Not Available
[
    'allowed' => false,
    'error' => "Feature 'advanced_reporting' not available in current license",
    'error_code' => 'FEATURE_NOT_AVAILABLE'
]

// Feature Limit Exceeded
[
    'allowed' => false,
    'error' => 'Feature limit exceeded. Maximum: 10, Current: 10',
    'error_code' => 'FEATURE_LIMIT_EXCEEDED',
    'limit' => 10,
    'current_usage' => 10
]
```

## Error Codes Reference

| Error Code | Description |
|------------|-------------|
| `LICENSE_NOT_FOUND` | License key doesn't exist in database |
| `LICENSE_INACTIVE` | License exists but status is not 'active' |
| `LICENSE_EXPIRED` | License has expired and grace period ended |
| `ACTIVATION_LIMIT_EXCEEDED` | Maximum device activations reached |
| `DEVICE_NOT_ACTIVATED` | Hardware fingerprint not found in activations |
| `HARDWARE_MISMATCH` | Offline token hardware doesn't match request |
| `TOKEN_INVALID` | JWT token is invalid or expired |
| `FEATURE_NOT_AVAILABLE` | Feature not assigned to product |
| `FEATURE_DISABLED` | Feature exists but is disabled |
| `FEATURE_LIMIT_EXCEEDED` | Feature usage limit reached |
| `PARAMS_NOT_SET` | License parameters not set before validation |

## Testing

### Unit Test Example

```php
use Tests\TestCase;
use Westel\License\Services\ServerLicenseService;
use Westel\License\Models\License;
use Westel\License\Models\Product;

class ServerLicenseServiceTest extends TestCase
{
    public function test_validates_active_license()
    {
        $product = Product::factory()->create();
        $license = License::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addYear()
        ]);

        $service = new ServerLicenseService();
        $result = $service->validateLicense(
            $license->license_key,
            'test-fingerprint',
            $product->id
        );

        $this->assertTrue($result['valid']);
        $this->assertEquals('active', $result['status']);
        $this->assertArrayHasKey('offline_token', $result);
    }

    public function test_rejects_expired_license_outside_grace_period()
    {
        $product = Product::factory()->create(['grace_period_days' => 7]);
        $license = License::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->subDays(10)
        ]);

        $service = new ServerLicenseService();
        $result = $service->validateLicense(
            $license->license_key,
            'test-fingerprint',
            $product->id
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('LICENSE_EXPIRED', $result['error_code']);
    }
}
```

## Advanced Configuration

### Custom Configuration

```php
$service = new ServerLicenseService([
    'jwt_secret' => 'custom-secret',
    'jwt_algorithm' => 'HS512',
    'grace_period_days' => 14,
    'offline_validation_days' => 60,
    'log_validations' => false
]);
```

### Dependency Injection

```php
// In a service provider
$this->app->bind(LicenseServiceInterface::class, function ($app) {
    return new ServerLicenseService([
        'jwt_secret' => config('license.server.jwt_secret'),
        'jwt_algorithm' => config('license.server.jwt_algorithm'),
    ]);
});

// Usage in controller
public function __construct(
    protected LicenseServiceInterface $licenseService
) {}

public function validate(Request $request)
{
    $result = $this->licenseService->validateLicense(
        $request->input('license_key'),
        $request->input('hardware_fingerprint'),
        $request->input('product_id')
    );

    return response()->json($result);
}
```

## Integration with API Controllers

```php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Westel\License\Services\ServerLicenseService;

class LicenseController extends Controller
{
    public function __construct(
        protected ServerLicenseService $licenseService
    ) {}

    public function validate(Request $request)
    {
        $validated = $request->validate([
            'license_key' => 'required|string',
            'hardware_fingerprint' => 'required|string',
            'product_id' => 'required|uuid',
            'system_info' => 'nullable|array'
        ]);

        $result = $this->licenseService->validateLicense(
            $validated['license_key'],
            $validated['hardware_fingerprint'],
            $validated['product_id'],
            $validated['system_info'] ?? null,
            $request
        );

        return response()->json($result);
    }

    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'license_key' => 'required|string',
            'hardware_fingerprint' => 'required|string',
        ]);

        $result = $this->licenseService->heartbeat(
            $validated['license_key'],
            $validated['hardware_fingerprint'],
            $request
        );

        return response()->json($result);
    }
}
```

## Best Practices

1. **Always use dependency injection** for better testability
2. **Cache license validations** to reduce database queries
3. **Implement rate limiting** on API endpoints to prevent abuse
4. **Log all validation attempts** for security auditing
5. **Use HTTPS** for all license validation requests
6. **Rotate JWT secrets** periodically for enhanced security
7. **Monitor grace period usage** to identify renewal issues
8. **Set appropriate offline validation periods** based on your use case
9. **Validate hardware fingerprints** consistently across client applications
10. **Handle network failures gracefully** with offline token fallback

## Related Documentation

- [LicenseServiceInterface](/docs/Contracts/LicenseServiceInterface.md)
- [Client Mode Service](/docs/ClientLicenseService.md)
- [Models Documentation](/docs/Models.md)
- [API Endpoints](/docs/API.md)

## Support

For issues or questions, please refer to the main package documentation or open an issue on GitHub.
