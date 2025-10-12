<?php

namespace Westel\License\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Westel\License\Contracts\LicenseServiceInterface;
use Westel\License\Exceptions\LicenseException;

class LicenseInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:install
                            {--force : Overwrite existing files}
                            {--skip-prompts : Skip interactive prompts}
                            {--mode= : Set license mode (server or client)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure the Laravel License package';

    /**
     * License service instance
     *
     * @var LicenseServiceInterface
     */
    protected LicenseServiceInterface $licenseService;

    /**
     * Installation mode
     *
     * @var string
     */
    protected string $mode;

    /**
     * Create a new command instance.
     *
     * @param LicenseServiceInterface $licenseService
     */
    public function __construct(LicenseServiceInterface $licenseService)
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
        $this->displayWelcome();

        try {
            // Determine installation mode
            $this->determineMode();

            // Publish config file
            $this->publishConfig();

            // Publish migrations (server mode only)
            if ($this->mode === 'server') {
                $this->publishMigrations();
            }

            // Configure environment variables
            if (!$this->option('skip-prompts')) {
                $this->configureEnvironment();
            }

            // Display next steps
            $this->displayNextSteps();

            $this->newLine();
            $this->info('Installation completed successfully!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Installation failed: ' . $e->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line('<fg=gray>' . $e->getTraceAsString() . '</>');
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display welcome message
     *
     * @return void
     */
    protected function displayWelcome(): void
    {
        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════════════╗');
        $this->line('  ║                                                   ║');
        $this->line('  ║          <fg=cyan>Laravel License Package</>              ║');
        $this->line('  ║                                                   ║');
        $this->line('  ║     License Management & Feature Gating          ║');
        $this->line('  ║                                                   ║');
        $this->line('  ╚═══════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Determine installation mode
     *
     * @return void
     */
    protected function determineMode(): void
    {
        // Check if mode is provided via option
        if ($this->option('mode')) {
            $this->mode = strtolower($this->option('mode'));

            if (!in_array($this->mode, ['server', 'client'])) {
                throw new \InvalidArgumentException('Mode must be either "server" or "client"');
            }

            $this->info("Mode set to: <fg=cyan>{$this->mode}</>");
            $this->newLine();
            return;
        }

        // Check current config
        $currentMode = config('license.mode', 'client');

        // Ask user if not skipping prompts
        if (!$this->option('skip-prompts')) {
            $this->info('Select installation mode:');
            $this->line('  <fg=cyan>Server</>: Manage licenses, handle validation requests');
            $this->line('  <fg=cyan>Client</>: Validate against remote server');
            $this->newLine();

            $mode = $this->choice(
                'Installation mode',
                ['client', 'server'],
                array_search($currentMode, ['client', 'server'])
            );

            $this->mode = $mode;
        } else {
            $this->mode = $currentMode;
        }

        $this->info("Mode: <fg=cyan>{$this->mode}</>");
        $this->newLine();
    }

    /**
     * Publish config file
     *
     * @return void
     */
    protected function publishConfig(): void
    {
        $this->info('Publishing configuration file...');

        $configPath = config_path('license.php');
        $force = $this->option('force');

        if (File::exists($configPath) && !$force) {
            if (!$this->option('skip-prompts')) {
                if (!$this->confirm('Config file already exists. Overwrite?', false)) {
                    $this->warn('Skipped config publishing');
                    $this->newLine();
                    return;
                }
            } else {
                $this->warn('Config file already exists. Use --force to overwrite');
                $this->newLine();
                return;
            }
        }

        $params = ['--tag' => 'license-config'];
        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        $this->line('<fg=green>✓</> Config file published');
        $this->newLine();
    }

    /**
     * Publish migrations (server mode only)
     *
     * @return void
     */
    protected function publishMigrations(): void
    {
        $this->info('Publishing migrations...');

        $force = $this->option('force');
        $params = ['--tag' => 'license-migrations'];

        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        $this->line('<fg=green>✓</> Migrations published');
        $this->newLine();

        if (!$this->option('skip-prompts')) {
            if ($this->confirm('Run migrations now?', true)) {
                $this->info('Running migrations...');
                $this->call('migrate');
                $this->line('<fg=green>✓</> Migrations completed');
                $this->newLine();
            } else {
                $this->warn('Remember to run migrations later: php artisan migrate');
                $this->newLine();
            }
        }
    }

    /**
     * Configure environment variables
     *
     * @return void
     */
    protected function configureEnvironment(): void
    {
        $this->info('Configuring environment variables...');
        $this->newLine();

        $envVars = [];

        // Mode
        $envVars['LICENSE_MODE'] = $this->mode;

        if ($this->mode === 'client') {
            $this->configureClientEnvironment($envVars);
        } else {
            $this->configureServerEnvironment($envVars);
        }

        // Write to .env file
        $this->updateEnvFile($envVars);

        $this->line('<fg=green>✓</> Environment variables configured');
        $this->newLine();
    }

    /**
     * Configure client mode environment variables
     *
     * @param array &$envVars
     * @return void
     */
    protected function configureClientEnvironment(array &$envVars): void
    {
        // Server URL
        $serverUrl = $this->ask(
            'License server URL',
            config('license.client.server_url', 'https://license.yourdomain.com')
        );
        $envVars['LICENSE_SERVER_URL'] = $serverUrl;

        // License Key
        $licenseKey = $this->ask('Your license key');
        if ($licenseKey) {
            $envVars['LICENSE_KEY'] = $licenseKey;
        }

        // Product ID
        $productId = $this->ask('Product ID (optional)');
        if ($productId) {
            $envVars['LICENSE_PRODUCT_ID'] = $productId;
        }

        // Cache TTL
        if ($this->confirm('Configure cache settings?', false)) {
            $cacheTtl = $this->ask('Cache TTL in seconds', config('license.client.cache_ttl', 86400));
            $envVars['LICENSE_CACHE_TTL'] = $cacheTtl;

            $cacheStore = $this->ask('Cache store', config('license.client.cache_store', 'file'));
            $envVars['LICENSE_CACHE_STORE'] = $cacheStore;
        }

        // Offline Mode
        if ($this->confirm('Enable offline mode?', true)) {
            $envVars['LICENSE_OFFLINE_MODE'] = 'true';
        } else {
            $envVars['LICENSE_OFFLINE_MODE'] = 'false';
        }

        // Validate license
        if ($licenseKey && $this->confirm('Validate license now?', true)) {
            $this->validateLicense($licenseKey, $serverUrl);
        }
    }

    /**
     * Configure server mode environment variables
     *
     * @param array &$envVars
     * @return void
     */
    protected function configureServerEnvironment(array &$envVars): void
    {
        $this->line('Server mode configuration:');
        $this->newLine();

        // JWT Secret
        if ($this->confirm('Use APP_KEY as JWT secret?', true)) {
            $this->line('Using APP_KEY for JWT signing');
        } else {
            $jwtSecret = $this->secret('Enter JWT secret');
            if ($jwtSecret) {
                $envVars['LICENSE_JWT_SECRET'] = $jwtSecret;
            }
        }

        // Grace Period
        if ($this->confirm('Configure grace period?', false)) {
            $gracePeriod = $this->ask('Grace period in days', config('license.server.grace_period_days', 7));
            $envVars['LICENSE_GRACE_PERIOD_DAYS'] = $gracePeriod;
        }

        // Offline Validation Days
        if ($this->confirm('Configure offline validation period?', false)) {
            $offlineDays = $this->ask('Offline validation days', config('license.server.offline_validation_days', 30));
            $envVars['LICENSE_OFFLINE_VALIDATION_DAYS'] = $offlineDays;
        }

        // Default Activation Limit
        if ($this->confirm('Configure default activation limit?', false)) {
            $activationLimit = $this->ask('Default activation limit', config('license.server.default_activation_limit', 5));
            $envVars['LICENSE_DEFAULT_ACTIVATION_LIMIT'] = $activationLimit;
        }

        // Enable API Routes
        if ($this->confirm('Enable API routes?', true)) {
            $envVars['LICENSE_ENABLE_API_ROUTES'] = 'true';

            $apiPrefix = $this->ask('API route prefix', config('license.server.api_prefix', 'api/license'));
            $envVars['LICENSE_API_PREFIX'] = $apiPrefix;
        } else {
            $envVars['LICENSE_ENABLE_API_ROUTES'] = 'false';
        }

        // CORS
        if ($this->confirm('Enable CORS?', true)) {
            $envVars['LICENSE_ENABLE_CORS'] = 'true';
        } else {
            $envVars['LICENSE_ENABLE_CORS'] = 'false';
        }
    }

    /**
     * Validate license
     *
     * @param string $licenseKey
     * @param string $serverUrl
     * @return void
     */
    protected function validateLicense(string $licenseKey, string $serverUrl): void
    {
        $this->newLine();
        $this->info('Validating license...');

        try {
            // Temporarily update config
            config(['license.client.license_key' => $licenseKey]);
            config(['license.client.server_url' => $serverUrl]);

            $result = $this->licenseService->validate();

            if ($result['valid'] ?? false) {
                $this->line('<fg=green>✓</> License is valid!');

                if (isset($result['product']['name'])) {
                    $this->line("Product: <fg=cyan>{$result['product']['name']}</>");
                }

                if (isset($result['expires_at'])) {
                    $this->line("Expires: <fg=yellow>{$result['expires_at']}</>");
                }
            } else {
                $this->error('License validation failed: ' . ($result['error'] ?? 'Unknown error'));
            }

        } catch (LicenseException $e) {
            $this->error('License validation failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->error('Validation error: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Update .env file with new variables
     *
     * @param array $vars
     * @return void
     */
    protected function updateEnvFile(array $vars): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            $this->warn('.env file not found. Please configure manually.');
            return;
        }

        $envContent = File::get($envPath);

        foreach ($vars as $key => $value) {
            // Escape value if it contains spaces or special characters
            if (preg_match('/\s|[#;]/', $value)) {
                $value = '"' . str_replace('"', '\\"', $value) . '"';
            }

            // Check if key already exists
            if (preg_match("/^{$key}=/m", $envContent)) {
                // Update existing key
                $envContent = preg_replace(
                    "/^{$key}=.*/m",
                    "{$key}={$value}",
                    $envContent
                );
            } else {
                // Add new key
                $envContent .= "\n{$key}={$value}";
            }
        }

        File::put($envPath, $envContent);
    }

    /**
     * Display next steps
     *
     * @return void
     */
    protected function displayNextSteps(): void
    {
        $this->info('Next Steps:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if ($this->mode === 'server') {
            $this->line('1. <fg=cyan>Review configuration</> in config/license.php');
            $this->line('2. <fg=cyan>Run migrations</> if not done yet: php artisan migrate');
            $this->line('3. <fg=cyan>Create products</> and licenses in your database');
            $this->line('4. <fg=cyan>Configure API routes</> and middleware as needed');
            $this->line('5. <fg=cyan>Set up authentication</> for API endpoints');
        } else {
            $this->line('1. <fg=cyan>Review configuration</> in config/license.php');
            $this->line('2. <fg=cyan>Verify license credentials</> in your .env file');
            $this->line('3. <fg=cyan>Check license status</>: php artisan license:check');
            $this->line('4. <fg=cyan>Activate license</>: Call License::activate() in your code');
            $this->line('5. <fg=cyan>Use middleware</> to protect routes: license.valid, license.feature');
            $this->line('6. <fg=cyan>Use Blade directives</>: @license, @licenseValid, @licenseExpired');
        }

        $this->newLine();
        $this->line('<fg=yellow>Documentation</>: Check package README for detailed usage');
        $this->newLine();
    }
}
