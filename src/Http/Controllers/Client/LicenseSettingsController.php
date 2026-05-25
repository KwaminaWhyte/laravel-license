<?php

namespace Westel\License\Http\Controllers\Client;

use Illuminate\Routing\Controller;
use Westel\License\Models\LicenseConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class LicenseSettingsController extends Controller
{
    /**
     * Display license settings page
     */
    public function index(): Response
    {
        return Inertia::render('Settings/License', [
            'licenseSettings' => [
                'license_key' => LicenseConfiguration::get('license_key', config('license.license_key')),
                'server_url' => LicenseConfiguration::get('server_url', config('license.server.url')),
                'offline_mode' => LicenseConfiguration::get('offline_mode', config('license.client.offline_validation', true)),
            ],
        ]);
    }

    /**
     * Update license settings
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'license_key' => 'required|string|min:10|max:255',
            'server_url' => 'required|url',
            'offline_mode' => 'boolean',
        ]);

        // Store encrypted license key
        LicenseConfiguration::set(
            'license_key',
            $validated['license_key'],
            encrypt: true,
            type: 'string',
            description: 'License key for the application'
        );

        // Store server URL
        LicenseConfiguration::set(
            'server_url',
            $validated['server_url'],
            encrypt: false,
            type: 'string',
            description: 'License server URL'
        );

        // Store offline mode
        LicenseConfiguration::set(
            'offline_mode',
            $validated['offline_mode'] ?? true,
            encrypt: false,
            type: 'boolean',
            description: 'Enable offline license validation'
        );

        // Clear license cache to force re-validation
        Artisan::call('cache:forget', ['key' => 'license_validation']);

        return back()->with('success', 'License settings updated successfully. Please refresh the page to apply changes.');
    }

    /**
     * Test license connection
     */
    public function test(Request $request)
    {
        try {
            $licenseKey = $request->input('license_key') ?? LicenseConfiguration::get('license_key');
            $serverUrl = $request->input('server_url') ?? LicenseConfiguration::get('server_url');

            if (!$licenseKey || !$serverUrl) {
                return response()->json([
                    'success' => false,
                    'message' => 'License configuration is incomplete',
                ], 400);
            }

            $payload = [
                'license_key' => $licenseKey,
                'hardware_fingerprint' => 'test-connection',
            ];

            // Test connection to license server
            $client = new \GuzzleHttp\Client();
            $response = $client->post("{$serverUrl}/api/license/validate", [
                'json' => $payload,
                'timeout' => 10,
            ]);

            $result = json_decode($response->getBody(), true);

            if (($result['valid'] ?? false) || isset($result['offline_token'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'License server connection successful',
                    'license_status' => $result['status'] ?? 'unknown',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'License validation failed',
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
