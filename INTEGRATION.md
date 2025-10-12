# Integration Guide

This guide shows how to integrate the Westel Laravel License package into your existing projects.

## Overview

The package can be integrated into three different scenarios:

1. **westel-admin** - Server mode (provides licenses)
2. **westel-pos** - Client mode (consumes licenses)
3. **exp** - Client mode (consumes licenses)

## Integration into westel-admin (Server Mode)

westel-admin will act as the **license server**, managing licenses for both westel-pos and exp.

### Step 1: Add Package to composer.json

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/westel/laravel-license"
        }
    ],
    "require": {
        "westel/laravel-license": "*"
    }
}
```

### Step 2: Install Package

```bash
cd westel-admin
composer install
```

### Step 3: Run Installation Command

```bash
php artisan license:install
```

Choose **Server Mode** and configure:
- JWT secret (use existing APP_KEY or generate new)
- Grace period (7 days)
- Offline validation period (30 days)
- Enable API routes
- Enable CORS

### Step 4: Environment Variables

Add to `.env`:

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

### Step 5: Migration Strategy

Since westel-admin already has license tables, you have two options:

#### Option A: Use Existing Tables (Recommended)

Configure the package to use your existing tables in `config/license.php`:

```php
'server' => [
    'tables' => [
        'products' => 'products',
        'licenses' => 'licenses',
        'license_activations' => 'license_activations',
        'license_validations' => 'license_validations',
        'license_features' => 'license_features',
        'feature_definitions' => 'feature_definitions',
        'product_feature_assignments' => 'product_feature_assignments',
    ],
],
```

Skip migrations and use existing models.

#### Option B: Run Fresh Migrations

If you prefer the package's schema:

```bash
# Backup existing data
php artisan db:seed --class=BackupLicenseDataSeeder

# Run migrations
php artisan migrate

# Migrate data to new schema
php artisan license:migrate-data
```

### Step 6: Update Routes

The package automatically registers API routes at `/api/license/*`. You can remove your existing license routes in `routes/api.php` if you want to use the package's routes.

### Step 7: Test API Endpoints

```bash
# Test validation endpoint
curl -X POST http://localhost:8000/api/license/validate \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "YOUR-LICENSE-KEY",
    "hardware_fingerprint": "test-fp",
    "product_id": "your-product-uuid"
  }'
```

### Step 8: Create Licenses for westel-pos and exp

```php
use Westel\License\Models\License;
use Westel\License\Models\Product;

// Create product for westel-pos
$posProduct = Product::create([
    'name' => 'Westel POS',
    'version' => '1.0.0',
    'features' => [
        'pos_interface',
        'inventory_management',
        'sales_reporting',
        'multi_warehouse',
        'barcode_scanning',
    ],
    'is_active' => true,
    'default_activation_limit' => 10,
]);

// Create license for westel-pos
$posLicense = License::create([
    'user_id' => 1, // Your user ID
    'product_id' => $posProduct->id,
    'license_key' => License::generateLicenseKey(),
    'status' => 'active',
    'expires_at' => now()->addYear(),
    'activation_limit' => 10,
]);

echo "westel-pos License Key: " . $posLicense->license_key . "\n";

// Create product for exp
$expProduct = Product::create([
    'name' => 'Westel Expediting',
    'version' => '1.0.0',
    'features' => [
        'purchase_orders',
        'shipment_tracking',
        'document_management',
        'analytics',
        'supplier_management',
    ],
    'is_active' => true,
    'default_activation_limit' => 5,
]);

// Create license for exp
$expLicense = License::create([
    'user_id' => 1,
    'product_id' => $expProduct->id,
    'license_key' => License::generateLicenseKey(),
    'status' => 'active',
    'expires_at' => now()->addYear(),
    'activation_limit' => 5,
]);

echo "exp License Key: " . $expLicense->license_key . "\n";
```

---

## Integration into westel-pos (Client Mode)

westel-pos is a Laravel 8 + Vue.js 2 application that will validate licenses from westel-admin.

### Step 1: Add Package to composer.json

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/westel/laravel-license"
        }
    ],
    "require": {
        "westel/laravel-license": "*"
    }
}
```

### Step 2: Install Package

```bash
cd westel-pos
composer install
```

### Step 3: Run Installation Command

```bash
php artisan license:install
```

Choose **Client Mode** and provide:
- Server URL: `http://localhost:8000` (or your westel-admin URL)
- License Key: (from westel-admin)
- Product ID: (POS product UUID from westel-admin)

### Step 4: Environment Variables

Add to `.env`:

```env
LICENSE_MODE=client
LICENSE_SERVER_URL=http://localhost:8000
LICENSE_KEY=ABCD-EFGH-IJKL-MNOP
LICENSE_PRODUCT_ID=your-pos-product-uuid
LICENSE_CACHE_TTL=86400
LICENSE_OFFLINE_MODE=true
LICENSE_AUTO_FINGERPRINT=true
```

### Step 5: Validate License

```bash
php artisan license:check
```

### Step 6: Protect Routes

In `routes/web.php`:

```php
// Protect all authenticated routes
Route::middleware(['auth', 'license.valid'])->group(function () {
    Route::get('/pos', [POSController::class, 'index']);
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/sales', [SalesController::class, 'index']);
});

// Protect premium features
Route::middleware(['auth', 'license.feature:advanced_reports'])->group(function () {
    Route::get('/reports/advanced', [ReportController::class, 'advanced']);
});
```

### Step 7: Add License Check to Vue App

In `resources/src/main.js` or app initialization:

```javascript
import axios from 'axios';

// Check license on app startup
axios.get('/api/license-status')
  .then(response => {
    if (!response.data.valid) {
      window.location.href = '/license-expired';
    }
  })
  .catch(error => {
    console.error('License check failed:', error);
  });
```

Create API endpoint in `routes/api.php`:

```php
Route::get('/license-status', function () {
    return response()->json([
        'valid' => \Westel\License\Facades\License::isValid(),
        'features' => \Westel\License\Facades\License::getFeatures(),
        'days_until_expiry' => \Westel\License\Facades\License::getDaysUntilExpiry(),
    ]);
});
```

### Step 8: Create License Expired View

Create `resources/views/license-expired.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <title>License Expired</title>
</head>
<body>
    <div style="text-align: center; padding: 50px;">
        <h1>License Expired</h1>
        <p>Your license has expired. Please contact support to renew.</p>
        <p>License Key: {{ config('license.client.license_key') }}</p>
    </div>
</body>
</html>
```

### Step 9: Schedule Heartbeat (Optional)

In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Send heartbeat every 24 hours
    $schedule->call(function () {
        \Westel\License\Facades\License::refresh();
    })->daily();
}
```

---

## Integration into exp (Client Mode)

exp is a Laravel 12 + React + Inertia.js application with UUID models.

### Step 1: Add Package to composer.json

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/westel/laravel-license"
        }
    ],
    "require": {
        "westel/laravel-license": "*"
    }
}
```

### Step 2: Install Package

```bash
cd exp
composer install
```

### Step 3: Run Installation Command

```bash
php artisan license:install
```

Choose **Client Mode** and provide:
- Server URL: `http://localhost:8000` (or your westel-admin URL)
- License Key: (from westel-admin)
- Product ID: (exp product UUID from westel-admin)

### Step 4: Environment Variables

Add to `.env`:

```env
LICENSE_MODE=client
LICENSE_SERVER_URL=http://localhost:8000
LICENSE_KEY=WXYZ-1234-5678-9ABC
LICENSE_PRODUCT_ID=your-exp-product-uuid
LICENSE_CACHE_TTL=86400
LICENSE_OFFLINE_MODE=true
```

### Step 5: Validate License

```bash
php artisan license:check --detailed
```

### Step 6: Protect Routes

In `routes/web.php`:

```php
// Protect all app routes
Route::middleware(['auth', 'license.valid'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::resource('shipments', ShipmentController::class);
});

// Feature-gated routes
Route::middleware(['auth', 'license.feature:analytics'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index']);
});
```

### Step 7: Add License Info to Inertia Shared Data

In `app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'auth' => [
            'user' => $request->user(),
        ],
        'license' => [
            'valid' => \Westel\License\Facades\License::isValid(),
            'features' => \Westel\License\Facades\License::getFeatures(),
            'days_until_expiry' => \Westel\License\Facades\License::getDaysUntilExpiry(),
            'status' => \Westel\License\Facades\License::getStatus(),
        ],
        'flash' => [
            'message' => fn () => $request->session()->get('message')
        ],
    ]);
}
```

### Step 8: Use License Info in React Components

```typescript
// resources/js/pages/Dashboard.tsx
import { usePage } from '@inertiajs/react';

export default function Dashboard() {
    const { license } = usePage().props;

    if (!license.valid) {
        return <LicenseExpiredWarning />;
    }

    return (
        <div>
            <h1>Dashboard</h1>
            {license.days_until_expiry < 7 && (
                <Alert>
                    Your license expires in {license.days_until_expiry} days
                </Alert>
            )}

            {license.features.includes('analytics') && (
                <Link href="/analytics">View Analytics</Link>
            )}
        </div>
    );
}
```

### Step 9: Create License Middleware for Inertia

Create `app/Http/Middleware/CheckLicense.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Westel\License\Facades\License;
use Inertia\Inertia;

class CheckLicense
{
    public function handle(Request $request, Closure $next)
    {
        if (!License::isValid()) {
            return Inertia::render('LicenseExpired', [
                'license_key' => config('license.client.license_key'),
                'server_url' => config('license.client.server_url'),
            ]);
        }

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... other middleware
    'check.license' => \App\Http\Middleware\CheckLicense::class,
];
```

---

## Testing the Integration

### 1. Start All Applications

```bash
# Terminal 1: westel-admin (server)
cd westel-admin
composer run dev

# Terminal 2: westel-pos (client)
cd westel-pos
php artisan serve --port=8001

# Terminal 3: exp (client)
cd exp
php artisan serve --port=8002
```

### 2. Test License Validation

```bash
# From westel-pos
curl http://localhost:8001/api/license-status

# From exp
curl http://localhost:8002/api/license-status
```

### 3. Test Feature Gating

Access a protected feature route and verify it checks the license.

### 4. Test Offline Mode

1. Stop westel-admin server
2. Access westel-pos and exp - should work offline
3. Wait for offline token expiry
4. Should show license error

### 5. Test Grace Period

1. In westel-admin, expire a license:
   ```php
   $license = License::where('license_key', 'YOUR-KEY')->first();
   $license->update(['expires_at' => now()->subDay()]);
   ```

2. Access client apps - should show grace period warning

---

## Deployment Considerations

### Production Server URL

Update `.env` in both client apps with production URL:

```env
# westel-pos and exp
LICENSE_SERVER_URL=https://gdml.online
```

### Security

1. Use HTTPS for license server in production
2. Restrict CORS origins in westel-admin:
   ```env
   LICENSE_CORS_ORIGINS=https://pos.yourcompany.com,https://exp.yourcompany.com
   ```

3. Use different license keys for production and staging

### Caching

For better performance, use Redis:

```env
LICENSE_CACHE_STORE=redis
```

### Monitoring

Set up monitoring for:
- License validation failures
- Expired licenses
- API request rates
- Offline token expiry

---

## Troubleshooting

### Issue: Cannot connect to license server

**Solution**:
- Check `LICENSE_SERVER_URL` is correct
- Verify firewall allows connections
- Enable offline mode temporarily

### Issue: License validation fails

**Solution**:
- Run `php artisan license:check --refresh`
- Check license key and product ID match
- Verify license is active in westel-admin

### Issue: Features not available

**Solution**:
- Check feature keys match exactly
- Verify features are assigned to product in westel-admin
- Clear cache: `php artisan cache:clear`

---

## Next Steps

1. Set up automated license renewal
2. Implement license expiry notifications
3. Create customer portal for license management
4. Set up analytics dashboard
5. Configure webhooks for license events
