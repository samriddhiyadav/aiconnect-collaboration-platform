<?php
/**
 * TeamSphere - Configuration File
 */

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (is_array($env)) {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

$appEnv = $_ENV['APP_ENV'] ?? 'production';
if ($appEnv === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Database Configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'teamsphere');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Application Settings
define('APP_NAME', 'TeamSphere');
define('APP_VERSION', '1.0.0');
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost/teamsphere');
define('TIMEZONE', $_ENV['APP_TIMEZONE'] ?? 'UTC');

// File Upload Settings
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
define('ALLOWED_FILE_TYPES', [
    'image/jpeg',
    'image/png',
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel'
]);

// Session Configuration
define('SESSION_NAME', 'TEAMSPHERE_SESS');
define('SESSION_LIFETIME', 86400);

// Security Settings
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_COST', 12);
define('DEBUG_MODE', $appEnv === 'development');

// Theme
define('PRIMARY_COLOR', '#6C4DF6');
define('SECONDARY_COLOR', '#4A90E2');
?>
