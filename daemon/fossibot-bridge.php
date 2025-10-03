#!/usr/bin/env php
<?php
// ABOUTME: CLI entry point for Fossibot MQTT Bridge daemon
// Loads config, initializes bridge, runs event loop

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// =============================================================================
// CLI ARGUMENT PARSING
// =============================================================================

function showUsage(): void
{
    echo <<<USAGE
Fossibot MQTT Bridge - ReactPHP Daemon
Usage: fossibot-bridge.php [OPTIONS]

Options:
  -c, --config PATH    Path to config.json file (required)
  -h, --help          Show this help message
  -v, --version       Show version information
  --validate          Validate config and exit (no daemon start)

Examples:
  fossibot-bridge.php --config /etc/fossibot/config.json
  fossibot-bridge.php -c ./config/config.json
  fossibot-bridge.php --config config.json --validate

USAGE;
}

function showVersion(): void
{
    echo "Fossibot MQTT Bridge v2.0.0\n";
    echo "PHP " . PHP_VERSION . "\n";
    echo "ReactPHP Event Loop\n";
}

// Parse CLI arguments
$options = getopt('c:hv', ['config:', 'help', 'version', 'validate']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

if (isset($options['v']) || isset($options['version'])) {
    showVersion();
    exit(0);
}

$configPath = $options['c'] ?? $options['config'] ?? null;

if ($configPath === null) {
    echo "Error: --config argument is required\n\n";
    showUsage();
    exit(1);
}

// Resolve relative paths
if (!str_starts_with($configPath, '/')) {
    $configPath = getcwd() . '/' . $configPath;
}

// =============================================================================
// CONFIG LOADING & VALIDATION
// =============================================================================

function loadConfig(string $path): array
{
    if (!file_exists($path)) {
        throw new \RuntimeException("Config file not found: $path");
    }

    if (!is_readable($path)) {
        throw new \RuntimeException("Config file not readable: $path");
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new \RuntimeException("Failed to read config file: $path");
    }

    $config = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException("Invalid JSON in config file: " . json_last_error_msg());
    }

    return $config;
}

function validateConfig(array $config): array
{
    $errors = [];

    // Validate accounts
    if (!isset($config['accounts']) || !is_array($config['accounts'])) {
        $errors[] = "Missing or invalid 'accounts' array";
    } elseif (empty($config['accounts'])) {
        $errors[] = "No accounts configured (accounts array is empty)";
    } else {
        foreach ($config['accounts'] as $i => $account) {
            if (empty($account['email'])) {
                $errors[] = "Account $i: missing 'email'";
            }
            if (empty($account['password'])) {
                $errors[] = "Account $i: missing 'password'";
            }
            if (!filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Account $i: invalid email format";
            }
        }
    }

    // Validate mosquitto
    if (!isset($config['mosquitto']) || !is_array($config['mosquitto'])) {
        $errors[] = "Missing or invalid 'mosquitto' configuration";
    } else {
        if (empty($config['mosquitto']['host'])) {
            $errors[] = "Missing mosquitto.host";
        }
        if (!isset($config['mosquitto']['port']) || !is_int($config['mosquitto']['port'])) {
            $errors[] = "Missing or invalid mosquitto.port (must be integer)";
        }
        if (empty($config['mosquitto']['client_id'])) {
            $errors[] = "Missing mosquitto.client_id";
        }
    }

    // Validate daemon
    if (!isset($config['daemon']) || !is_array($config['daemon'])) {
        $errors[] = "Missing or invalid 'daemon' configuration";
    } else {
        if (empty($config['daemon']['log_file'])) {
            $errors[] = "Missing daemon.log_file";
        }
        if (empty($config['daemon']['log_level'])) {
            $errors[] = "Missing daemon.log_level";
        } elseif (!in_array($config['daemon']['log_level'], ['debug', 'info', 'warning', 'error'])) {
            $errors[] = "Invalid daemon.log_level (must be: debug, info, warning, error)";
        }
    }

    // Validate bridge
    if (!isset($config['bridge']) || !is_array($config['bridge'])) {
        $errors[] = "Missing or invalid 'bridge' configuration";
    } else {
        if (!isset($config['bridge']['status_publish_interval']) || !is_int($config['bridge']['status_publish_interval'])) {
            $errors[] = "Missing or invalid bridge.status_publish_interval (must be integer)";
        }
        if (!isset($config['bridge']['reconnect_delay_min']) || !is_int($config['bridge']['reconnect_delay_min'])) {
            $errors[] = "Missing or invalid bridge.reconnect_delay_min (must be integer)";
        }
        if (!isset($config['bridge']['reconnect_delay_max']) || !is_int($config['bridge']['reconnect_delay_max'])) {
            $errors[] = "Missing or invalid bridge.reconnect_delay_max (must be integer)";
        }
    }

    return $errors;
}

try {
    echo "Loading config from: $configPath\n";
    $config = loadConfig($configPath);

    echo "Validating config...\n";
    $errors = validateConfig($config);

    if (!empty($errors)) {
        echo "\n❌ Config validation failed:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        exit(1);
    }

    echo "✅ Config valid\n";
    echo "  Accounts: " . count($config['accounts']) . "\n";
    echo "  Mosquitto: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n";
    echo "  Log level: {$config['daemon']['log_level']}\n";

    // If --validate flag, exit here
    if (isset($options['validate'])) {
        echo "\n✅ Validation complete (--validate flag set, not starting daemon)\n";
        exit(0);
    }

} catch (\Throwable $e) {
    echo "\n❌ Config error: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// PID FILE MANAGEMENT
// =============================================================================

/**
 * Check and create PID file.
 *
 * Prevents multiple bridge instances from running simultaneously.
 * Handles stale PID files from crashed processes.
 *
 * @param string $pidFile Path to PID file
 * @throws RuntimeException if another instance is running
 */
function checkAndCreatePidFile(string $pidFile): void
{
    // Create directory if needed
    $pidDir = dirname($pidFile);
    if (!is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }

    // Check for existing PID file
    if (file_exists($pidFile)) {
        $oldPid = (int)trim(file_get_contents($pidFile));

        // Check if process is still running
        if (posix_kill($oldPid, 0)) {
            // Process exists
            throw new \RuntimeException(
                "Bridge is already running with PID $oldPid\n" .
                "PID file: $pidFile\n" .
                "Use 'fossibot-bridge-ctl stop' to stop it first."
            );
        }

        // Stale PID file - remove it
        echo "⚠️  Stale PID file found (process $oldPid not running), removing\n";
        unlink($pidFile);
    }

    // Write our PID
    $currentPid = getmypid();
    file_put_contents($pidFile, $currentPid);

    echo "✅ PID file created: $pidFile (PID: $currentPid)\n";

    // Register shutdown handler to remove PID file
    register_shutdown_function(function() use ($pidFile, $currentPid) {
        if (file_exists($pidFile)) {
            $filePid = (int)trim(file_get_contents($pidFile));

            // Only remove if it's still our PID (not overwritten)
            if ($filePid === $currentPid) {
                unlink($pidFile);
            }
        }
    });
}

/**
 * Get PID file path from config or use default.
 */
function getPidFilePath(array $config): string
{
    // Check config for custom path
    if (isset($config['daemon']['pid_file'])) {
        $pidFile = $config['daemon']['pid_file'];

        // Expand relative paths relative to script directory
        if (!str_starts_with($pidFile, '/')) {
            $pidFile = __DIR__ . '/' . $pidFile;
        }

        return $pidFile;
    }

    // Default: /var/run/fossibot/bridge.pid (production) or ./bridge.pid (dev)
    if (is_dir('/var/run/fossibot')) {
        return '/var/run/fossibot/bridge.pid';
    }

    return __DIR__ . '/bridge.pid';
}

// Check PID file before starting
try {
    $pidFile = getPidFilePath($config);
    checkAndCreatePidFile($pidFile);
} catch (\RuntimeException $e) {
    echo "\n❌ " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// LOGGER SETUP
// =============================================================================

function createLogger(array $config): Logger
{
    $logger = new Logger('fossibot_bridge');

    // Map log level string to Monolog constant
    $logLevel = match($config['daemon']['log_level']) {
        'debug' => Logger::DEBUG,
        'info' => Logger::INFO,
        'warning' => Logger::WARNING,
        'error' => Logger::ERROR,
        default => Logger::INFO
    };

    // Console handler (STDOUT)
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        "Y-m-d H:i:s",
        true,
        true
    );
    $consoleHandler = new StreamHandler('php://stdout', $logLevel);
    $consoleHandler->setFormatter($consoleFormatter);
    $logger->pushHandler($consoleHandler);

    // File handler (rotating, 7 days retention)
    $logFile = $config['daemon']['log_file'];

    // Create log directory if needed
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s",
        true,
        true
    );
    $fileHandler = new RotatingFileHandler($logFile, 7, $logLevel);
    $fileHandler->setFormatter($fileFormatter);
    $logger->pushHandler($fileHandler);

    return $logger;
}

try {
    $logger = createLogger($config);
    echo "✅ Logger initialized\n\n";
} catch (\Throwable $e) {
    echo "❌ Failed to initialize logger: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// DAEMON STARTUP
// =============================================================================

$logger->info('Fossibot MQTT Bridge starting', [
    'version' => '2.0.0',
    'php_version' => PHP_VERSION,
    'config_file' => $configPath,
    'pid' => getmypid()
]);

try {
    $bridge = new MqttBridge($config, $logger);

    echo "Starting bridge (press Ctrl+C to stop)...\n";
    echo "═══════════════════════════════════════\n\n";

    $bridge->run();

} catch (\Throwable $e) {
    $logger->critical('Bridge startup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    echo "\n❌ Bridge startup failed: " . $e->getMessage() . "\n";
    exit(1);
}

// This point is reached after loop->stop() (graceful shutdown)
$logger->info('Bridge stopped');
echo "\n✅ Bridge stopped\n";
exit(0);
