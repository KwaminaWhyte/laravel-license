<?php

namespace Westel\License\Console;

use Illuminate\Console\Command;
use Westel\License\Services\LicenseService;
use Westel\License\Exceptions\LicenseException;
use Carbon\Carbon;

class LicenseCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:check
                            {--refresh : Force refresh license data from server}
                            {--detailed : Show detailed license information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate the current license and display status information';

    /**
     * License service instance
     *
     * @var LicenseService
     */
    protected LicenseService $licenseService;

    /**
     * Create a new command instance.
     *
     * @param LicenseService $licenseService
     */
    public function __construct(LicenseService $licenseService)
    {
        parent::__construct();
        $this->licenseService = $licenseService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $mode = config('license.mode', 'client');

        $this->info('License Validation Check');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Display mode
        $this->line("Mode: <fg=cyan>{$mode}</>");
        $this->newLine();

        try {
            // Refresh if requested
            if ($this->option('refresh')) {
                $this->info('Refreshing license data...');
                $this->licenseService->refresh();
                $this->newLine();
            }

            // Get license information
            $licenseInfo = $this->licenseService->getLicenseInfo();

            // Display license status
            $this->displayLicenseStatus($licenseInfo);

            // Display features if detailed option is provided
            if ($this->option('detailed')) {
                $this->newLine();
                $this->displayFeatures();
            }

            // Determine exit code based on license validity
            if ($licenseInfo['is_valid'] ?? false) {
                if ($licenseInfo['in_grace_period'] ?? false) {
                    $this->warn('License is in grace period. Please renew soon!');
                    return Command::SUCCESS;
                }
                return Command::SUCCESS;
            } else {
                $this->error('License validation failed!');
                return Command::FAILURE;
            }

        } catch (LicenseException $e) {
            $this->newLine();
            $this->error('License Validation Error:');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line('<fg=gray>' . $e->getTraceAsString() . '</>');
            }

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Unexpected Error:');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line('<fg=gray>' . $e->getTraceAsString() . '</>');
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display license status information
     *
     * @param array $licenseInfo
     * @return void
     */
    protected function displayLicenseStatus(array $licenseInfo): void
    {
        // Prepare table data
        $tableData = [];

        // License Key
        if (isset($licenseInfo['license_key'])) {
            $maskedKey = $this->maskLicenseKey($licenseInfo['license_key']);
            $tableData[] = ['License Key', $maskedKey];
        }

        // Status
        $status = $licenseInfo['status'] ?? 'unknown';
        $statusColor = $this->getStatusColor($status);
        $tableData[] = ['Status', "<fg={$statusColor}>" . strtoupper($status) . "</>"];

        // Validity
        $isValid = $licenseInfo['is_valid'] ?? false;
        $validityText = $isValid ? '<fg=green>VALID</>' : '<fg=red>INVALID</>';
        $tableData[] = ['Validity', $validityText];

        // Expiration
        if (isset($licenseInfo['expires_at'])) {
            $expiresAt = Carbon::parse($licenseInfo['expires_at']);
            $expiresAtFormatted = $expiresAt->format('Y-m-d H:i:s');

            $daysUntilExpiry = $this->licenseService->getDaysUntilExpiry();
            if ($daysUntilExpiry !== null) {
                if ($daysUntilExpiry > 30) {
                    $expiryInfo = "<fg=green>{$expiresAtFormatted}</> ({$daysUntilExpiry} days)";
                } elseif ($daysUntilExpiry > 7) {
                    $expiryInfo = "<fg=yellow>{$expiresAtFormatted}</> ({$daysUntilExpiry} days)";
                } elseif ($daysUntilExpiry > 0) {
                    $expiryInfo = "<fg=red>{$expiresAtFormatted}</> ({$daysUntilExpiry} days)";
                } else {
                    $expiryInfo = "<fg=red>{$expiresAtFormatted}</> (EXPIRED)";
                }
            } else {
                $expiryInfo = $expiresAtFormatted;
            }

            $tableData[] = ['Expires At', $expiryInfo];
        } elseif (isset($licenseInfo['is_expired'])) {
            $isExpired = $licenseInfo['is_expired'];
            $tableData[] = ['Expired', $isExpired ? '<fg=red>YES</>' : '<fg=green>NO</>'];
        }

        // Grace Period
        if (isset($licenseInfo['in_grace_period']) && $licenseInfo['in_grace_period']) {
            $graceDays = $licenseInfo['grace_period_days_remaining'] ?? 'unknown';
            $tableData[] = ['Grace Period', "<fg=yellow>Active ({$graceDays} days remaining)</>"];
        }

        // Product
        if (isset($licenseInfo['product']['name'])) {
            $productName = $licenseInfo['product']['name'];
            if (isset($licenseInfo['product']['version'])) {
                $productName .= " v{$licenseInfo['product']['version']}";
            }
            $tableData[] = ['Product', $productName];
        }

        // Customer
        if (isset($licenseInfo['customer']['name'])) {
            $tableData[] = ['Customer', $licenseInfo['customer']['name']];
        }

        // Activation Info
        if (isset($licenseInfo['activation_limit'])) {
            $activationCount = $licenseInfo['activation_count'] ?? 0;
            $activationLimit = $licenseInfo['activation_limit'];
            $activationInfo = "{$activationCount} / {$activationLimit}";

            if ($activationCount >= $activationLimit) {
                $activationInfo = "<fg=red>{$activationInfo}</>";
            } elseif ($activationCount >= $activationLimit * 0.8) {
                $activationInfo = "<fg=yellow>{$activationInfo}</>";
            } else {
                $activationInfo = "<fg=green>{$activationInfo}</>";
            }

            $tableData[] = ['Activations', $activationInfo];
        }

        // Display table
        $this->table(['Property', 'Value'], $tableData);
    }

    /**
     * Display available features
     *
     * @return void
     */
    protected function displayFeatures(): void
    {
        try {
            $features = $this->licenseService->getFeatures();

            if (empty($features)) {
                $this->warn('No features available');
                return;
            }

            $this->info('Available Features:');
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();

            $tableData = [];

            foreach ($features as $key => $feature) {
                $name = is_array($feature) ? ($feature['name'] ?? $key) : $key;
                $type = is_array($feature) ? ($feature['type'] ?? 'feature') : 'feature';
                $enabled = is_array($feature) ? ($feature['enabled'] ?? true) : true;

                $enabledText = $enabled ? '<fg=green>✓</>' : '<fg=red>✗</>';

                // Display value
                $displayValue = '';
                if (is_array($feature)) {
                    if (isset($feature['display_value'])) {
                        $displayValue = $feature['display_value'];
                    } elseif (isset($feature['limit'])) {
                        $limit = $feature['limit'];
                        $displayValue = $limit === -1 ? 'Unlimited' : $limit;
                    } elseif (isset($feature['quota'])) {
                        $displayValue = $feature['quota'];
                    }
                }

                $tableData[] = [
                    $key,
                    $name,
                    ucfirst($type),
                    $enabledText,
                    $displayValue
                ];
            }

            $this->table(
                ['Key', 'Name', 'Type', 'Enabled', 'Value'],
                $tableData
            );

        } catch (\Exception $e) {
            $this->error('Failed to retrieve features: ' . $e->getMessage());
        }
    }

    /**
     * Mask license key for display
     *
     * @param string $licenseKey
     * @return string
     */
    protected function maskLicenseKey(string $licenseKey): string
    {
        $length = strlen($licenseKey);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        $visible = 4;
        $start = substr($licenseKey, 0, $visible);
        $end = substr($licenseKey, -$visible);
        $masked = str_repeat('*', $length - ($visible * 2));

        return $start . $masked . $end;
    }

    /**
     * Get color for status display
     *
     * @param string $status
     * @return string
     */
    protected function getStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'green',
            'grace_period' => 'yellow',
            'expired', 'invalid', 'suspended' => 'red',
            'pending' => 'yellow',
            default => 'gray',
        };
    }
}
