# Installation Guide

This guide provides step-by-step instructions for installing and configuring the Westel Laravel License package in both server and client modes.

## Requirements

- PHP 8.1 or higher
- Laravel 10.x, 11.x, or 12.x
- Composer
- (Optional) Redis for caching

## Installation

### Step 1: Install via Composer

```bash
composer require westel/laravel-license
```

### Step 2: Run Interactive Installation

The easiest way to configure the package is using the interactive installation command:

```bash
php artisan license:install
```

This will guide you through:
- Selecting server or client mode
- Publishing configuration files
- Setting up environment variables
- Running migrations (server mode only)
- Validating your license (client mode)

### Step 3: Manual Configuration (Alternative)

If you prefer manual configuration:

#### Publish Configuration

```bash
php artisan vendor:publish --tag=license-config
```

This creates `config/license.php`.

#### Publish Migrations (Server Mode Only)

```bash
php artisan vendor:publish --tag=license-migrations
php artisan migrate
```

## Configuration by Mode

### Server Mode Setup

Server mode is used by the application that **provides** licenses to other applications.

#### 1. Set Mode in `.env`

```env
LICENSE_MODE=server
LICENSE_JWT_SECRET="${APP_KEY}"
LICENSE_GRACE_PERIOD_DAYS=7
LICENSE_OFFLINE_VALIDATION_DAYS=30
LICENSE_DEFAULT_ACTIVATION_LIMIT=5
LICENSE_ENABLE_API_ROUTES=true
LICENSE_API_PREFIX=api/license
LICENSE_ENABLE_CORS=true
LICENSE_CORS_ORIGINS=*
```

#### 2. Run Migrations

```bash
php artisan migrate
```

This creates the following tables:
- `products` - Your SaaS products
- `licenses` - License records
- `license_activations` - Hardware activations
- `license_validations` - Validation logs
- `feature_definitions` - Available features
- `product_feature_assignments` - Features per product

#### 3. Create Your First Product

```php
use Westel\License\Models\Product;

$product = Product::create([
    'name' => 'My SaaS Product',
    'version' => '1.0.0',
    'description' => 'Description of your product',
    'features' => ['feature1', 'feature2', 'feature3'],
    'is_active' => true,
    'default_activation_limit' => 5,
    'grace_period_days' => 7,
    'offline_validation_days' => 30,
]);
```

#### 4. Generate License for Customer

```php
use Westel\License\Models\License;
use Westel\License\Models\User;

$license = License::create([
    'user_id' => $user->id,
    'product_id' => $product->id,
    'license_key' => License::generateLicenseKey(), // e.g., ABCD-EFGH-IJKL-MNOP
    'status' => 'active',
    'expires_at' => now()->addYear(),
    'activation_limit' => 5,
]);

// Give license key to customer
echo $license->license_key;
```

#### 5. API Endpoints Available

Once configured, your server will expose these endpoints:

```
POST   https://yourapp.com/api/license/validate
POST   https://yourapp.com/api/license/activate
POST   https://yourapp.com/api/license/deactivate
POST   https://yourapp.com/api/license/heartbeat
GET    https://yourapp.com/api/license/status
GET    https://yourapp.com/api/license/features
POST   https://yourapp.com/api/license/validate-feature
GET    https://yourapp.com/api/license/tiers
GET    https://yourapp.com/api/license/info/{key}
GET    https://yourapp.com/api/license/analytics
```

### Client Mode Setup

Client mode is used by applications that **consume** licenses from a server.

#### 1. Set Mode in `.env`

```env
LICENSE_MODE=client
LICENSE_SERVER_URL=https://license.yourcompany.com
LICENSE_KEY=ABCD-EFGH-IJKL-MNOP
LICENSE_PRODUCT_ID=your-product-uuid
LICENSE_CACHE_TTL=86400
LICENSE_OFFLINE_MODE=true
LICENSE_AUTO_FINGERPRINT=true
LICENSE_HTTP_TIMEOUT=10
LICENSE_RETRY_ENABLED=true
LICENSE_RETRY_TIMES=3
LICENSE_HEARTBEAT_INTERVAL=86400
```

#### 2. Validate License

```bash
php artisan license:check
```

This will:
- Contact the license server
- Validate your license key
- Cache the result
- Display license status

#### 3. Use in Your Application

##### Check License Validity

```php
use Westel\License\Facades\License;

if (License::isValid()) {
    // License is active
} else {
    // License is invalid or expired
}
```

##### Check Features

```php
if (License::hasFeature('advanced_reports')) {
    // Feature is available
}
```

##### Protect Routes

```php
// routes/web.php
Route::middleware(['license.valid'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

Route::middleware(['license.feature:premium_features'])->group(function () {
    Route::get('/premium', [PremiumController::class, 'index']);
});
```

##### Use in Blade Templates

```blade
@licenseValid
    <p>Your license is active</p>
@endlicenseValid

@license('premium_feature')
    <button>Premium Feature</button>
@endlicense
```

## Configuration Options

### Server Configuration

Edit `config/license.php`:

```php
'server' => [
    'jwt_secret' => env('LICENSE_JWT_SECRET'),
    'jwt_algorithm' => 'HS256',
    'grace_period_days' => 7,
    'offline_validation_days' => 30,
    'default_activation_limit' => 5,
    'enable_api_routes' => true,
    'api_prefix' => 'api/license',
    'enable_cors' => true,
    'cors_origins' => '*',
    'rate_limiting' => [
        'enabled' => true,
        'max_attempts' => 60,
        'decay_minutes' => 1,
    ],
],
```

### Client Configuration

```php
'client' => [
    'server_url' => env('LICENSE_SERVER_URL'),
    'license_key' => env('LICENSE_KEY'),
    'product_id' => env('LICENSE_PRODUCT_ID'),
    'cache_ttl' => 86400, // 24 hours
    'cache_store' => 'file',
    'offline_mode' => true,
    'auto_fingerprint' => true,
    'http_timeout' => 10,
    'retry' => [
        'enabled' => true,
        'times' => 3,
        'sleep' => 1000, // milliseconds
    ],
    'heartbeat_interval' => 86400,
    'on_failure' => 'throw', // 'throw', 'log', 'silent'
],
```

## Middleware Configuration

```php
'middleware' => [
    'auto_register' => true,
    'aliases' => [
        'license.valid' => \Westel\License\Http\Middleware\EnsureLicenseValid::class,
        'license.feature' => \Westel\License\Http\Middleware\CheckFeatureAccess::class,
        'license.server' => \Westel\License\Http\Middleware\EnsureServerMode::class,
    ],
    'redirect_route' => 'license.invalid',
    'throw_exception' => false,
],
```

## Testing Your Setup

### Server Mode

```bash
# Create test license
php artisan tinker
>>> $license = \Westel\License\Models\License::create([
...     'user_id' => 1,
...     'product_id' => 'your-product-id',
...     'license_key' => \Westel\License\Models\License::generateLicenseKey(),
...     'status' => 'active',
...     'expires_at' => now()->addYear(),
...     'activation_limit' => 5,
... ]);
>>> echo $license->license_key;

# Test API endpoint
curl -X POST https://yourapp.com/api/license/validate \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "ABCD-EFGH-IJKL-MNOP",
    "hardware_fingerprint": "test-fingerprint",
    "product_id": "your-product-id"
  }'
```

### Client Mode

```bash
# Check license status
php artisan license:check

# Check with detailed info
php artisan license:check --detailed

# Force refresh from server
php artisan license:check --refresh
```

## Troubleshooting

### Server Mode Issues

**Problem**: Migrations fail

**Solution**: Ensure database is configured and accessible. Check `DB_*` variables in `.env`.

**Problem**: API routes not working

**Solution**:
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

**Problem**: CORS errors from client

**Solution**: Check `LICENSE_ENABLE_CORS=true` and `LICENSE_CORS_ORIGINS=*` in server `.env`.

### Client Mode Issues

**Problem**: Cannot connect to license server

**Solution**:
- Verify `LICENSE_SERVER_URL` is correct
- Check server is accessible
- Enable offline mode: `LICENSE_OFFLINE_MODE=true`

**Problem**: License validation fails

**Solution**:
- Check `LICENSE_KEY` and `LICENSE_PRODUCT_ID` are correct
- Run `php artisan license:check --refresh`
- Check server logs for errors

**Problem**: Offline validation not working

**Solution**:
- Ensure you validated online at least once (to get JWT token)
- Check JWT token hasn't expired
- Verify `LICENSE_OFFLINE_MODE=true`

## Next Steps

After installation:

### For Server Mode

1. Create products and features
2. Set up license generation workflow
3. Configure user/customer management
4. Set up webhooks for license events
5. Monitor validation logs and analytics

### For Client Mode

1. Implement feature gating in your app
2. Protect routes with middleware
3. Set up scheduled heartbeat (optional)
4. Handle license expiration gracefully
5. Test offline mode functionality

## Support

For issues or questions:
- GitHub Issues: https://github.com/westel/laravel-license
- Documentation: https://docs.westel.com/laravel-license
- Email: support@westel.com
