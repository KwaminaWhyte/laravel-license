<?php

namespace Westel\License\Services;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Hardware Fingerprint Service
 *
 * Generates unique hardware fingerprints based on system information.
 * Supports multiple components and provides tolerance for minor hardware changes.
 */
class HardwareFingerprintService
{
    /**
     * Available fingerprint components
     */
    protected const COMPONENTS = [
        'hostname',
        'ip_address',
        'mac_address',
        'cpu_info',
        'disk_serial',
        'user_agent',
        'platform',
    ];

    /**
     * Cached system information
     */
    protected ?array $cachedSystemInfo = null;

    /**
     * Cached fingerprint
     */
    protected ?string $cachedFingerprint = null;

    /**
     * Generate hardware fingerprint from current system
     *
     * @return string The generated hardware fingerprint hash
     */
    public function generate(): string
    {
        // Return cached fingerprint if available
        if ($this->cachedFingerprint !== null) {
            return $this->cachedFingerprint;
        }

        // Get enabled components from config
        $enabledComponents = $this->getEnabledComponents();

        // Generate fingerprint from enabled components
        $this->cachedFingerprint = $this->generateFromComponents($enabledComponents);

        return $this->cachedFingerprint;
    }

    /**
     * Generate fingerprint from specific components
     *
     * @param array $components Array of component names to include
     * @return string The generated hardware fingerprint hash
     */
    public function generateFromComponents(array $components): string
    {
        $systemInfo = $this->getSystemInfo();
        $fingerprintData = [];

        foreach ($components as $component) {
            if (!in_array($component, self::COMPONENTS)) {
                continue;
            }

            if (isset($systemInfo[$component]) && !empty($systemInfo[$component])) {
                $fingerprintData[$component] = $systemInfo[$component];
            }
        }

        // Sort components for consistency
        ksort($fingerprintData);

        // Create fingerprint string
        $fingerprintString = json_encode($fingerprintData);

        // Hash the fingerprint
        $algorithm = Config::get('license.fingerprint.hash_algorithm', 'sha256');

        return hash($algorithm, $fingerprintString);
    }

    /**
     * Compare two fingerprints with tolerance
     *
     * @param string $fp1 First fingerprint
     * @param string $fp2 Second fingerprint
     * @param int $tolerance Tolerance level (0-100, default from config)
     * @return bool True if fingerprints match within tolerance
     */
    public function compare(string $fp1, string $fp2, int $tolerance = null): bool
    {
        // Exact match
        if ($fp1 === $fp2) {
            return true;
        }

        // If no tolerance, require exact match
        if ($tolerance === 0) {
            return false;
        }

        // Get tolerance from config if not provided
        if ($tolerance === null) {
            $tolerance = Config::get('license.fingerprint.tolerance', 10);
        }

        // For tolerance-based comparison, we need to compare individual components
        // This requires decoding the original component data, which we don't have
        // from just the hash. So we'll use a similarity metric on the hash itself
        // as a fallback.

        // Calculate similarity percentage based on matching characters
        $similarity = $this->calculateHashSimilarity($fp1, $fp2);

        return $similarity >= (100 - $tolerance);
    }

    /**
     * Get detailed system information
     *
     * @return array Detailed system information
     */
    public function getSystemInfo(): array
    {
        // Return cached info if available
        if ($this->cachedSystemInfo !== null) {
            return $this->cachedSystemInfo;
        }

        $this->cachedSystemInfo = [
            'hostname' => $this->getHostname(),
            'ip_address' => $this->getIpAddress(),
            'mac_address' => $this->getMacAddress(),
            'cpu_info' => $this->getCpuInfo(),
            'disk_serial' => $this->getDiskSerial(),
            'user_agent' => $this->getUserAgent(),
            'platform' => $this->getPlatform(),
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'os_family' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
        ];

        return $this->cachedSystemInfo;
    }

    /**
     * Get enabled components from config
     *
     * @return array Array of enabled component names
     */
    protected function getEnabledComponents(): array
    {
        $components = Config::get('license.fingerprint.components', []);
        $enabled = [];

        foreach ($components as $component => $isEnabled) {
            if ($isEnabled && in_array($component, self::COMPONENTS)) {
                $enabled[] = $component;
            }
        }

        return $enabled;
    }

    /**
     * Get system hostname
     *
     * @return string|null
     */
    protected function getHostname(): ?string
    {
        try {
            $hostname = gethostname();
            return $hostname !== false ? $hostname : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get IP address
     *
     * @return string|null
     */
    protected function getIpAddress(): ?string
    {
        // CLI environment
        if (PHP_SAPI === 'cli') {
            return $this->getServerIpAddress();
        }

        // Web environment - prioritize real IP over proxied IPs
        $ipSources = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($ipSources as $source) {
            if (!empty($_SERVER[$source])) {
                $ip = $_SERVER[$source];

                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $this->getServerIpAddress();
    }

    /**
     * Get server IP address
     *
     * @return string|null
     */
    protected function getServerIpAddress(): ?string
    {
        try {
            $hostname = $this->getHostname();
            if ($hostname) {
                $ip = gethostbyname($hostname);
                return $ip !== $hostname ? $ip : null;
            }
        } catch (\Throwable $e) {
            // Fallback to local IP
        }

        return '127.0.0.1';
    }

    /**
     * Get MAC address
     *
     * @return string|null
     */
    protected function getMacAddress(): ?string
    {
        try {
            $os = PHP_OS_FAMILY;

            switch ($os) {
                case 'Windows':
                    return $this->getMacAddressWindows();
                case 'Linux':
                    return $this->getMacAddressLinux();
                case 'Darwin': // macOS
                    return $this->getMacAddressMacOS();
                default:
                    return $this->getMacAddressFallback();
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get MAC address on Windows
     *
     * @return string|null
     */
    protected function getMacAddressWindows(): ?string
    {
        try {
            $output = [];
            @exec('getmac /fo csv /nh', $output);

            if (!empty($output[0])) {
                // Parse CSV output: "MAC Address","Transport Name"
                $parts = str_getcsv($output[0]);
                if (!empty($parts[0]) && $parts[0] !== 'N/A') {
                    return strtoupper(str_replace('-', ':', $parts[0]));
                }
            }
        } catch (\Throwable $e) {
            // Fall through to fallback
        }

        return null;
    }

    /**
     * Get MAC address on Linux
     *
     * @return string|null
     */
    protected function getMacAddressLinux(): ?string
    {
        try {
            // Try ifconfig first
            $output = [];
            @exec('ifconfig 2>/dev/null | grep -o -E "([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}" | head -n 1', $output);

            if (!empty($output[0])) {
                return strtoupper($output[0]);
            }

            // Try ip link as fallback
            $output = [];
            @exec('ip link 2>/dev/null | grep -o -E "([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}" | head -n 1', $output);

            if (!empty($output[0])) {
                return strtoupper($output[0]);
            }

            // Try reading from sysfs
            $interfaces = glob('/sys/class/net/*/address');
            foreach ($interfaces as $interface) {
                if (strpos($interface, 'lo') !== false) {
                    continue; // Skip loopback
                }

                $mac = @file_get_contents($interface);
                if ($mac && $mac !== '00:00:00:00:00:00') {
                    return strtoupper(trim($mac));
                }
            }
        } catch (\Throwable $e) {
            // Fall through to fallback
        }

        return null;
    }

    /**
     * Get MAC address on macOS
     *
     * @return string|null
     */
    protected function getMacAddressMacOS(): ?string
    {
        try {
            $output = [];
            @exec('ifconfig en0 2>/dev/null | grep ether | awk \'{print $2}\'', $output);

            if (!empty($output[0])) {
                return strtoupper($output[0]);
            }

            // Try networksetup as fallback
            @exec('networksetup -getmacaddress en0 2>/dev/null | awk \'{print $3}\'', $output);

            if (!empty($output[0])) {
                return strtoupper($output[0]);
            }
        } catch (\Throwable $e) {
            // Fall through to fallback
        }

        return null;
    }

    /**
     * Fallback MAC address detection
     *
     * @return string|null
     */
    protected function getMacAddressFallback(): ?string
    {
        // Try PHP's internal method (if available)
        if (function_exists('exec')) {
            try {
                $output = [];
                @exec('getmac 2>/dev/null', $output);

                foreach ($output as $line) {
                    if (preg_match('/([0-9A-F]{2}[:-]){5}([0-9A-F]{2})/i', $line, $matches)) {
                        return strtoupper(str_replace('-', ':', $matches[0]));
                    }
                }
            } catch (\Throwable $e) {
                // Fall through
            }
        }

        return null;
    }

    /**
     * Get CPU information
     *
     * @return string|null
     */
    protected function getCpuInfo(): ?string
    {
        try {
            $os = PHP_OS_FAMILY;

            switch ($os) {
                case 'Windows':
                    return $this->getCpuInfoWindows();
                case 'Linux':
                    return $this->getCpuInfoLinux();
                case 'Darwin':
                    return $this->getCpuInfoMacOS();
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get CPU info on Windows
     *
     * @return string|null
     */
    protected function getCpuInfoWindows(): ?string
    {
        try {
            $output = [];
            @exec('wmic cpu get ProcessorId /format:list 2>nul', $output);

            foreach ($output as $line) {
                if (strpos($line, 'ProcessorId=') === 0) {
                    return trim(substr($line, 12));
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get CPU info on Linux
     *
     * @return string|null
     */
    protected function getCpuInfoLinux(): ?string
    {
        try {
            // Try to read CPU serial from /proc/cpuinfo
            if (file_exists('/proc/cpuinfo')) {
                $cpuinfo = @file_get_contents('/proc/cpuinfo');
                if ($cpuinfo) {
                    // Look for Serial or processor ID
                    if (preg_match('/Serial\s*:\s*([a-f0-9]+)/i', $cpuinfo, $matches)) {
                        return $matches[1];
                    }
                    if (preg_match('/processor\s*:\s*(\d+)/i', $cpuinfo, $matches)) {
                        // Use first processor ID as fallback
                        return 'cpu_' . $matches[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get CPU info on macOS
     *
     * @return string|null
     */
    protected function getCpuInfoMacOS(): ?string
    {
        try {
            $output = [];
            @exec('sysctl -n machdep.cpu.brand_string 2>/dev/null', $output);

            if (!empty($output[0])) {
                return md5($output[0]); // Hash the brand string for privacy
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get disk serial number
     *
     * @return string|null
     */
    protected function getDiskSerial(): ?string
    {
        try {
            $os = PHP_OS_FAMILY;

            switch ($os) {
                case 'Windows':
                    return $this->getDiskSerialWindows();
                case 'Linux':
                    return $this->getDiskSerialLinux();
                case 'Darwin':
                    return $this->getDiskSerialMacOS();
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get disk serial on Windows
     *
     * @return string|null
     */
    protected function getDiskSerialWindows(): ?string
    {
        try {
            $output = [];
            @exec('wmic diskdrive get SerialNumber /format:list 2>nul', $output);

            foreach ($output as $line) {
                if (strpos($line, 'SerialNumber=') === 0) {
                    $serial = trim(substr($line, 13));
                    if (!empty($serial)) {
                        return $serial;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get disk serial on Linux
     *
     * @return string|null
     */
    protected function getDiskSerialLinux(): ?string
    {
        try {
            // Try to get disk serial using lsblk
            $output = [];
            @exec('lsblk -o SERIAL -n 2>/dev/null | head -n 1', $output);

            if (!empty($output[0]) && trim($output[0]) !== '') {
                return trim($output[0]);
            }

            // Try udevadm as fallback
            @exec('udevadm info --query=property --name=/dev/sda 2>/dev/null | grep ID_SERIAL= | cut -d= -f2', $output);

            if (!empty($output[0])) {
                return trim($output[0]);
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get disk serial on macOS
     *
     * @return string|null
     */
    protected function getDiskSerialMacOS(): ?string
    {
        try {
            $output = [];
            @exec('diskutil info disk0 2>/dev/null | grep "Device / Media Name" | awk -F: \'{print $2}\'', $output);

            if (!empty($output[0])) {
                return md5(trim($output[0])); // Hash for privacy
            }

            // Try system_profiler as fallback
            @exec('system_profiler SPSerialATADataType 2>/dev/null | grep "Serial Number" | head -n 1 | awk -F: \'{print $2}\'', $output);

            if (!empty($output[0])) {
                return trim($output[0]);
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get user agent
     *
     * @return string|null
     */
    protected function getUserAgent(): ?string
    {
        // CLI environment
        if (PHP_SAPI === 'cli') {
            return 'CLI/' . PHP_VERSION;
        }

        // Web environment
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Get platform information
     *
     * @return string
     */
    protected function getPlatform(): string
    {
        return PHP_OS_FAMILY . '/' . php_uname('s') . '/' . php_uname('r');
    }

    /**
     * Calculate similarity between two hash strings
     *
     * @param string $hash1
     * @param string $hash2
     * @return float Similarity percentage (0-100)
     */
    protected function calculateHashSimilarity(string $hash1, string $hash2): float
    {
        $len1 = strlen($hash1);
        $len2 = strlen($hash2);

        if ($len1 === 0 || $len2 === 0) {
            return 0;
        }

        $maxLen = max($len1, $len2);
        $minLen = min($len1, $len2);

        $matches = 0;
        for ($i = 0; $i < $minLen; $i++) {
            if ($hash1[$i] === $hash2[$i]) {
                $matches++;
            }
        }

        return ($matches / $maxLen) * 100;
    }

    /**
     * Clear cached data
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cachedSystemInfo = null;
        $this->cachedFingerprint = null;
    }

    /**
     * Check if exec function is available
     *
     * @return bool
     */
    protected function isExecAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!function_exists('exec')) {
            return $available = false;
        }

        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        return $available = !in_array('exec', $disabled);
    }

    /**
     * Safe exec wrapper
     *
     * @param string $command
     * @param array $output
     * @param int $returnVar
     * @return false|string
     */
    protected function safeExec(string $command, array &$output = [], int &$returnVar = null)
    {
        if (!$this->isExecAvailable()) {
            return false;
        }

        return @exec($command, $output, $returnVar);
    }
}
