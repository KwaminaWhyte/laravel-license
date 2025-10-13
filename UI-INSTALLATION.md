# UI-Based License Management Installation Guide

This package includes optional UI components for managing license settings through a web interface instead of `.env` files.

## Features

- 🔐 **Encrypted Storage** - License keys stored encrypted in database
- 🎨 **React/Inertia UI** - Beautiful, modern interface
- 🧪 **Connection Testing** - Test license server connectivity
- 🔄 **Live Updates** - Changes apply without restarting the application
- ✅ **Security** - Role-based access control (admin only)

---

## Installation Steps

### 1. Publish UI Assets

For React/Inertia.js projects:

```bash
# Publish all client UI components
php artisan vendor:publish --tag=license-ui-react
php artisan vendor:publish --tag=license-controllers
php artisan vendor:publish --tag=license-config-migration
php artisan vendor:publish --tag=license-config-model
```

This will create:
- `resources/js/pages/Settings/License.tsx` - React UI component
- `app/Http/Controllers/Settings/LicenseSettingsController.php` - Controller
- `database/migrations/*_create_license_configurations_table.php` - Migration
- `app/Models/LicenseConfiguration.php` - Model

### 2. Run Migration

```bash
php artisan migrate
```

### 3. Add Routes

Add to your `routes/settings.php` or `routes/web.php`:

```php
use App\Http\Controllers\Settings\LicenseSettingsController;

Route::middleware(['auth', 'can:manage-settings'])->group(function () {
    Route::get('settings/license', [LicenseSettingsController::class, 'index'])
        ->name('settings.license');
    Route::put('settings/license', [LicenseSettingsController::class, 'update'])
        ->name('settings.license.update');
    Route::post('settings/license/test', [LicenseSettingsController::class, 'test'])
        ->name('settings.license.test');
});
```

### 4. Add Permission Gate

Add to `app/Providers/AuthServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    $this->registerPolicies();

    // License settings management gate
    Gate::define('manage-settings', function (User $user) {
        // Restrict to admins only
        return $user->is_admin; // or $user->hasRole('admin')
    });
}
```

### 5. Update AppServiceProvider

Add to `app/Providers/AppServiceProvider.php`:

```php
use App\Models\LicenseConfiguration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

public function boot(): void
{
    // Load license config from database
    $this->loadLicenseConfigFromDatabase();
}

protected function loadLicenseConfigFromDatabase(): void
{
    try {
        if (!Schema::hasTable('license_configurations')) {
            return;
        }

        $licenseKey = LicenseConfiguration::get('license_key');
        if ($licenseKey) {
            Config::set('license.license_key', $licenseKey);
        }

        $serverUrl = LicenseConfiguration::get('server_url');
        if ($serverUrl) {
            Config::set('license.server.url', $serverUrl);
        }

        $productId = LicenseConfiguration::get('product_id');
        if ($productId) {
            Config::set('license.product_id', $productId);
        }

        $offlineMode = LicenseConfiguration::get('offline_mode');
        if ($offlineMode !== null) {
            Config::set('license.client.offline_validation', (bool) $offlineMode);
        }
    } catch (\Exception $e) {
        // Silently fail - will use .env values
    }
}
```

### 6. Build Frontend Assets

```bash
npm run build
```

---

## Usage

### Access the UI

Navigate to: `https://yourapp.com/settings/license`

### Update License

1. Enter your license key (will be encrypted)
2. Enter server URL
3. Enter product ID (UUID format)
4. Toggle offline mode
5. Click "Test connection" to verify
6. Click "Save changes"

### Configuration Priority

The system uses this priority order:
1. **Database values** (set via UI)
2. **`.env` values** (fallback)

This means once you set values in the UI, they override `.env` settings.

---

## Security

✅ License keys are encrypted using Laravel's `Crypt` facade
✅ Only users passing the `manage-settings` gate can access
✅ Secure password input fields (no auto-complete)
✅ CSRF protection on all form submissions

---

## Troubleshooting

### "Permission denied" error

Make sure your user passes the `manage-settings` gate:

```php
Gate::allows('manage-settings'); // Should return true
```

### UI not showing

1. Check routes are registered: `php artisan route:list | grep license`
2. Rebuild frontend: `npm run build`
3. Clear cache: `php artisan cache:clear`

### Changes not applying

Clear the license cache:

```php
Artisan::call('cache:forget', ['key' => 'license_validation']);
```

Or restart the application.

---

## Manual Installation (Without Publishing)

If you prefer to keep files in the vendor directory, you can:

1. Use the controller directly:
```php
use Westel\License\Http\Controllers\Client\LicenseSettingsController;
```

2. Copy the React component manually from:
```
vendor/westel/laravel-license/src/Views/React/License.tsx
```

---

## Alternative: Keep Using `.env`

The UI is **completely optional**. If you prefer managing licenses via `.env` files, simply don't publish the UI assets. The package works perfectly without the UI.

---

## Support

For issues or questions:
- GitHub: https://github.com/westel/laravel-license
- Email: support@example.com
