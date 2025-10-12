# Westel Laravel License - Package Summary

## Overview

**westel/laravel-license** is a comprehensive Laravel package for license management and feature gating with dual server-client architecture, hardware fingerprinting, offline validation, and complete API support.

---

## 🎯 Key Features

### Core Capabilities

- ✅ **Dual Mode Architecture** - Works as both license server and client
- ✅ **Hardware Fingerprinting** - Secure device binding with cross-platform support
- ✅ **Offline Validation** - JWT-based validation for up to 30 days offline
- ✅ **Feature Gating** - Granular control over feature access per license
- ✅ **Grace Periods** - Configurable grace periods for expired licenses
- ✅ **Multi-Product Support** - Manage licenses for multiple SaaS products
- ✅ **RESTful API** - Complete API for external integrations
- ✅ **Middleware Protection** - Easy route and feature protection
- ✅ **Comprehensive Logging** - Track all validation attempts and access patterns

### Server Mode Features

- License creation and management
- Hardware activation tracking
- JWT offline token generation
- Feature assignment and validation
- API endpoint exposure
- Validation logging and analytics
- Grace period management
- Multi-product/multi-tenant support

### Client Mode Features

- HTTP client with retry mechanism
- Intelligent caching system
- Offline mode with JWT validation
- Auto-generated hardware fingerprints
- Heartbeat pings to maintain connection
- Feature validation and limit checking
- Event dispatching for lifecycle events
- Graceful server downtime handling

---

## 📦 Package Structure

```
packages/westel/laravel-license/
├── config/
│   └── license.php                    # Main configuration file
├── src/
│   ├── Console/
│   │   ├── LicenseCheckCommand.php   # License status command
│   │   └── LicenseInstallCommand.php # Interactive installation
│   ├── Contracts/
│   │   └── LicenseServiceInterface.php # Service contract
│   ├── Database/
│   │   ├── Migrations/                 # Database migrations (server mode)
│   │   └── Seeders/                    # Database seeders
│   ├── Events/
│   │   ├── LicenseActivated.php
│   │   ├── LicenseDeactivated.php
│   │   ├── LicenseExpired.php
│   │   ├── LicenseInvalid.php
│   │   └── LicenseValidated.php
│   ├── Exceptions/
│   │   ├── LicenseException.php
│   │   ├── LicenseExpiredException.php
│   │   ├── LicenseInvalidException.php
│   │   └── LicenseServerException.php
│   ├── Facades/
│   │   └── License.php                 # Static facade
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── LicenseApiController.php # API controller
│   │   └── Middleware/
│   │       ├── CheckFeatureAccess.php   # Feature gating
│   │       ├── EnsureLicenseValid.php   # License validation
│   │       └── EnsureServerMode.php     # Server mode check
│   ├── Models/
│   │   ├── FeatureDefinition.php
│   │   ├── License.php
│   │   ├── LicenseActivation.php
│   │   ├── LicenseValidation.php
│   │   ├── Product.php
│   │   └── ProductFeatureAssignment.php
│   ├── routes/
│   │   └── api.php                      # API routes
│   ├── Services/
│   │   ├── ClientLicenseService.php     # Client mode service
│   │   ├── HardwareFingerprintService.php # Fingerprinting
│   │   ├── LicenseService.php           # Base service
│   │   └── ServerLicenseService.php     # Server mode service
│   ├── Traits/                          # Reusable traits
│   └── LicenseServiceProvider.php       # Service provider
├── tests/
│   ├── Feature/                         # Feature tests
│   └── Unit/                            # Unit tests
├── CHANGELOG.md                         # Version history
├── composer.json                        # Composer configuration
├── INSTALLATION.md                      # Installation guide
├── INTEGRATION.md                       # Integration guide
├── LICENSE.md                           # MIT License
├── PACKAGE-SUMMARY.md                   # This file
└── README.md                            # Main documentation
```

---

## 🚀 Quick Start

### Installation

```bash
composer require westel/laravel-license
php artisan license:install
```

### Server Mode (License Provider)

```php
// config/license.php
'mode' => 'server',

// Create products and licenses
use Westel\License\Models\{Product, License};

$product = Product::create([
    'name' => 'My SaaS',
    'features' => ['feature1', 'feature2'],
    'default_activation_limit' => 5,
]);

$license = License::create([
    'user_id' => $user->id,
    'product_id' => $product->id,
    'license_key' => License::generateLicenseKey(),
    'status' => 'active',
    'expires_at' => now()->addYear(),
]);
```

### Client Mode (License Consumer)

```php
// config/license.php
'mode' => 'client',
'client' => [
    'server_url' => 'https://license.yourcompany.com',
    'license_key' => 'ABCD-EFGH-IJKL-MNOP',
    'product_id' => 'your-product-uuid',
],

// Use in application
use Westel\License\Facades\License;

if (License::isValid()) {
    // License is active
}

if (License::hasFeature('premium_feature')) {
    // Feature available
}

// Protect routes
Route::middleware(['license.valid'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

Route::middleware(['license.feature:advanced_reports'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index']);
});
```

---

## 📚 API Endpoints (Server Mode)

The package exposes these REST API endpoints:

| Method | Endpoint                        | Description                            |
| ------ | ------------------------------- | -------------------------------------- |
| POST   | `/api/license/validate`         | Validate license and get offline token |
| POST   | `/api/license/activate`         | Activate license on hardware           |
| POST   | `/api/license/deactivate`       | Deactivate license                     |
| POST   | `/api/license/heartbeat`        | Heartbeat check                        |
| GET    | `/api/license/status`           | Get license status                     |
| GET    | `/api/license/info/{key}`       | Get license information                |
| GET    | `/api/license/features`         | Get available features                 |
| POST   | `/api/license/validate-feature` | Validate feature access                |
| GET    | `/api/license/tiers`            | Get available products                 |
| GET    | `/api/license/analytics`        | Get usage analytics                    |
| GET    | `/api/license/validation-stats` | Get validation statistics              |
| GET    | `/api/license/hardware-info`    | Generate hardware fingerprint          |

---

## 🔧 Configuration Options

### Server Configuration

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
    'rate_limiting' => [
        'enabled' => true,
        'max_attempts' => 60,
    ],
]
```

### Client Configuration

```php
'client' => [
    'server_url' => env('LICENSE_SERVER_URL'),
    'license_key' => env('LICENSE_KEY'),
    'product_id' => env('LICENSE_PRODUCT_ID'),
    'cache_ttl' => 86400,
    'offline_mode' => true,
    'auto_fingerprint' => true,
    'retry' => [
        'enabled' => true,
        'times' => 3,
    ],
    'heartbeat_interval' => 86400,
]
```

---

## 🛡️ Middleware

### EnsureLicenseValid

Ensures license is valid before allowing route access.

```php
Route::middleware(['license.valid'])->group(function () {
    // Protected routes
});
```

### CheckFeatureAccess

Checks if license has access to specific feature.

```php
Route::middleware(['license.feature:premium'])->group(function () {
    // Feature-gated routes
});
```

### EnsureServerMode

Protects server-only routes in mixed-mode environments.

```php
Route::middleware(['license.server'])->group(function () {
    // Server mode only
});
```

---

## 🎨 Blade Directives

```blade
@licenseValid
    <p>License is active</p>
@endlicenseValid

@license('feature_key')
    <button>Premium Feature</button>
@endlicense

@licenseExpired
    <div class="alert">License has expired</div>
@endlicenseExpired

@licenseGracePeriod
    <div class="warning">Grace period active</div>
@endlicenseGracePeriod
```

---

## 🔄 Events

The package dispatches these events:

- `LicenseValidated` - License validated successfully
- `LicenseActivated` - License activated on hardware
- `LicenseDeactivated` - License deactivated
- `LicenseExpired` - License expired
- `LicenseInvalid` - License validation failed

Listen to events in `EventServiceProvider`:

```php
protected $listen = [
    \Westel\License\Events\LicenseExpired::class => [
        \App\Listeners\SendLicenseExpiryNotification::class,
    ],
];
```

---

## 🖥️ Console Commands

### license:check

Display license status and information.

```bash
php artisan license:check
php artisan license:check --detailed
php artisan license:check --refresh
```

### license:install

Interactive package installation and configuration.

```bash
php artisan license:install
php artisan license:install --mode=client
php artisan license:install --force
```

---

## 📊 Database Schema (Server Mode)

### Tables Created

1. **products** - SaaS products/tiers
2. **licenses** - License records
3. **license_activations** - Hardware activations
4. **license_validations** - Validation logs
5. **feature_definitions** - Available features
6. **product_feature_assignments** - Features per product

---

## 🔐 Security Features

- SHA-256 hardware fingerprint hashing
- JWT token signing (HS256/HS512/RS256)
- CORS support for API endpoints
- Rate limiting for API requests
- Secure hardware fingerprint validation
- Activation limits per license
- Grace period support
- Offline validation with expiry

---

## 🧪 Testing

Run package tests:

```bash
composer test
```

---

## 📖 Documentation Files

- **README.md** - Main documentation
- **INSTALLATION.md** - Installation instructions
- **INTEGRATION.md** - Integration guide for westel-admin, westel-pos, exp
- **CHANGELOG.md** - Version history
- **LICENSE.md** - MIT License
- **PACKAGE-SUMMARY.md** - This file

---

## 🎯 Use Cases

### 1. SaaS License Management

Manage licenses for multiple SaaS products from a central server.

### 2. Feature Gating

Control access to premium features based on license tier.

### 3. Multi-Application Licensing

One license server can manage licenses for multiple client applications.

### 4. Offline-First Applications

Support applications that need to work offline for extended periods.

### 5. Hardware-Bound Licenses

Bind licenses to specific hardware to prevent unauthorized usage.

---

## 🔄 Integration Status

### westel-admin (Server Mode)

- **Status**: Ready for integration
- **Role**: License provider
- **Configuration**: Use existing tables, expose API endpoints

### westel-pos (Client Mode)

- **Status**: Ready for integration
- **Role**: License consumer
- **Stack**: Laravel 8 + Vue.js 2

### exp (Client Mode)

- **Status**: Ready for integration
- **Role**: License consumer
- **Stack**: Laravel 12 + React + Inertia.js

---

## 📦 Dependencies

### Required

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- firebase/php-jwt ^6.11
- guzzlehttp/guzzle ^7.0

### Optional

- Redis (for better caching)
- MySQL/PostgreSQL (server mode)

---

## 🚀 Deployment

### Publishing to Packagist

```bash
# Tag version
git tag v1.0.0
git push origin v1.0.0

# Packagist auto-updates from GitHub
```

### Using in Projects

```json
{
  "require": {
    "westel/laravel-license": "^1.0"
  }
}
```

---

## 🆘 Support

- **Documentation**: See README.md and INSTALLATION.md
- **Issues**: GitHub Issues
- **Email**: stanleyotabil10@gmail.com
- **Integration Help**: See INTEGRATION.md

---

## 📄 License

MIT License - See LICENSE.md for details

---

## 🙏 Credits

Created by the Westel Team

---

## ✅ Package Status

- [x] Core architecture designed
- [x] Server mode implemented
- [x] Client mode implemented
- [x] Hardware fingerprinting
- [x] Offline validation
- [x] API endpoints
- [x] Middleware
- [x] Events
- [x] Console commands
- [x] Facade
- [x] Blade directives
- [x] Documentation
- [x] Integration guides
- [ ] Unit tests
- [ ] Feature tests
- [ ] Published to Packagist

**Ready for deployment and integration!** 🎉
