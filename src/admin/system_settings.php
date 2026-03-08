<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/auth.php');
    exit;
}

// Verify admin role
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../../dashboard/dashboard.php');
    exit;
}

// Define root path and require database connection
define('ROOT_PATH', realpath(dirname(__DIR__ . '/../..')));
require_once ROOT_PATH . '../../includes/db_connect.php';

// Initialize variables
$success = '';
$error = '';
$systemSettings = [];
$mailSettings = [];
$securitySettings = [];
$backupSettings = [];

try {
    $pdo = Database::getInstance();

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_system_settings':
                handleUpdateSystemSettings($pdo);
                break;
            case 'update_mail_settings':
                handleUpdateMailSettings($pdo);
                break;
            case 'update_security_settings':
                handleUpdateSecuritySettings($pdo);
                break;
            case 'update_backup_settings':
                handleUpdateBackupSettings($pdo);
                break;
            case 'run_backup':
                handleRunBackup($pdo);
                break;
            case 'clear_cache':
                handleClearCache($pdo);
                break;
        }
    }

    // Get current settings
    $systemSettings = getSystemSettings($pdo);
    $mailSettings = getMailSettings($pdo);
    $securitySettings = getSecuritySettings($pdo);
    $backupSettings = getBackupSettings($pdo);

    // Log system settings access
    logActivity($pdo, $_SESSION['user_id'], 'system_settings', 'Accessed system settings');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "An error occurred while loading system settings.";
}

// Helper functions

function logActivity($pdo, $user_id, $action, $details = '')
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log 
            (user_id, role, action, details, ip_address)
            VALUES 
            (:user_id, :role, :action, :details, :ip_address)
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':role' => $_SESSION['role'],
            ':action' => $action,
            ':details' => $details,
            ':ip_address' => $_SERVER['REMOTE_ADDR']
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Activity log failed: " . $e->getMessage());
        return false;
    }
}
function getSystemSettings($pdo)
{
    $stmt = $pdo->query("SELECT * FROM system_settings");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getMailSettings($pdo)
{
    $stmt = $pdo->query("SELECT * FROM mail_settings");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getSecuritySettings($pdo)
{
    $stmt = $pdo->query("SELECT * FROM security_settings");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getBackupSettings($pdo)
{
    $stmt = $pdo->query("SELECT * FROM backup_settings");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function handleUpdateSystemSettings($pdo)
{
    global $success, $error;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO system_settings 
            (app_name, timezone, maintenance_mode, logo_url, favicon_url, theme_color, updated_at)
            VALUES 
            (:app_name, :timezone, :maintenance_mode, :logo_url, :favicon_url, :theme_color, NOW())
            ON DUPLICATE KEY UPDATE
            app_name = VALUES(app_name),
            timezone = VALUES(timezone),
            maintenance_mode = VALUES(maintenance_mode),
            logo_url = VALUES(logo_url),
            favicon_url = VALUES(favicon_url),
            theme_color = VALUES(theme_color),
            updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':app_name' => $_POST['app_name'] ?? 'TeamSphere',
            ':timezone' => $_POST['timezone'] ?? 'UTC',
            ':maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0,
            ':logo_url' => $_POST['logo_url'] ?? '',
            ':favicon_url' => $_POST['favicon_url'] ?? '',
            ':theme_color' => $_POST['theme_color'] ?? '#6C4DF6'
        ]);

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'system_settings_update', 'Updated system settings');
        $success = "System settings updated successfully";

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("System settings update failed: " . $e->getMessage());
        $error = "Failed to update system settings";
    }
}

function handleUpdateMailSettings($pdo)
{
    global $success, $error;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO mail_settings 
            (mailer, host, port, username, password, encryption, from_address, from_name, updated_at)
            VALUES 
            (:mailer, :host, :port, :username, :password, :encryption, :from_address, :from_name, NOW())
            ON DUPLICATE KEY UPDATE
            mailer = VALUES(mailer),
            host = VALUES(host),
            port = VALUES(port),
            username = VALUES(username),
            password = VALUES(password),
            encryption = VALUES(encryption),
            from_address = VALUES(from_address),
            from_name = VALUES(from_name),
            updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':mailer' => $_POST['mailer'] ?? 'smtp',
            ':host' => $_POST['host'] ?? '',
            ':port' => $_POST['port'] ?? 587,
            ':username' => $_POST['username'] ?? '',
            ':password' => $_POST['password'] ?? '',
            ':encryption' => $_POST['encryption'] ?? 'tls',
            ':from_address' => $_POST['from_address'] ?? '',
            ':from_name' => $_POST['from_name'] ?? ''
        ]);

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'mail_settings_update', 'Updated mail settings');
        $success = "Mail settings updated successfully";

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Mail settings update failed: " . $e->getMessage());
        $error = "Failed to update mail settings";
    }
}

function handleUpdateSecuritySettings($pdo)
{
    global $success, $error;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO security_settings 
            (password_policy, min_password_length, require_mixed_case, require_numbers, 
             require_special_chars, login_attempts, lockout_time, two_factor_auth, updated_at)
            VALUES 
            (:password_policy, :min_password_length, :require_mixed_case, :require_numbers, 
             :require_special_chars, :login_attempts, :lockout_time, :two_factor_auth, NOW())
            ON DUPLICATE KEY UPDATE
            password_policy = VALUES(password_policy),
            min_password_length = VALUES(min_password_length),
            require_mixed_case = VALUES(require_mixed_case),
            require_numbers = VALUES(require_numbers),
            require_special_chars = VALUES(require_special_chars),
            login_attempts = VALUES(login_attempts),
            lockout_time = VALUES(lockout_time),
            two_factor_auth = VALUES(two_factor_auth),
            updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':password_policy' => $_POST['password_policy'] ?? 'medium',
            ':min_password_length' => $_POST['min_password_length'] ?? 8,
            ':require_mixed_case' => isset($_POST['require_mixed_case']) ? 1 : 0,
            ':require_numbers' => isset($_POST['require_numbers']) ? 1 : 0,
            ':require_special_chars' => isset($_POST['require_special_chars']) ? 1 : 0,
            ':login_attempts' => $_POST['login_attempts'] ?? 5,
            ':lockout_time' => $_POST['lockout_time'] ?? 15,
            ':two_factor_auth' => isset($_POST['two_factor_auth']) ? 1 : 0
        ]);

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'security_settings_update', 'Updated security settings');
        $success = "Security settings updated successfully";

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Security settings update failed: " . $e->getMessage());
        $error = "Failed to update security settings";
    }
}

function handleUpdateBackupSettings($pdo)
{
    global $success, $error;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO backup_settings 
            (backup_frequency, backup_time, keep_backups, backup_type, storage_location, updated_at)
            VALUES 
            (:backup_frequency, :backup_time, :keep_backups, :backup_type, :storage_location, NOW())
            ON DUPLICATE KEY UPDATE
            backup_frequency = VALUES(backup_frequency),
            backup_time = VALUES(backup_time),
            keep_backups = VALUES(keep_backups),
            backup_type = VALUES(backup_type),
            storage_location = VALUES(storage_location),
            updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':backup_frequency' => $_POST['backup_frequency'] ?? 'daily',
            ':backup_time' => $_POST['backup_time'] ?? '02:00',
            ':keep_backups' => $_POST['keep_backups'] ?? 7,
            ':backup_type' => $_POST['backup_type'] ?? 'full',
            ':storage_location' => $_POST['storage_location'] ?? 'local'
        ]);

        $pdo->commit();

        logActivity($pdo, $_SESSION['user_id'], 'backup_settings_update', 'Updated backup settings');
        $success = "Backup settings updated successfully";

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Backup settings update failed: " . $e->getMessage());
        $error = "Failed to update backup settings";
    }
}

function handleRunBackup($pdo)
{
    global $success, $error;

    try {
        // In a real application, this would trigger an actual backup process
        // For this example, we'll just log the action

        logActivity($pdo, $_SESSION['user_id'], 'backup_run', 'Manual backup initiated');
        $success = "Backup process has been initiated. You will be notified when complete.";

    } catch (Exception $e) {
        error_log("Backup failed: " . $e->getMessage());
        $error = "Failed to initiate backup: " . $e->getMessage();
    }
}

function handleClearCache($pdo)
{
    global $success, $error;

    try {
        // In a real application, this would clear various caches
        // For this example, we'll simulate it

        logActivity($pdo, $_SESSION['user_id'], 'cache_clear', 'Cleared system cache');
        $success = "System cache has been cleared successfully";

    } catch (Exception $e) {
        error_log("Cache clear failed: " . $e->getMessage());
        $error = "Failed to clear cache: " . $e->getMessage();
    }
}

// Get timezone list for dropdown
$timezones = DateTimeZone::listIdentifiers();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | System Settings</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .settings-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
            background-color: var(--deep-space);
            color: var(--neon-white);
        }

        .sidebar {
            background: rgba(15, 15, 26, 0.8);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--quantum-foam);
            padding: 1.5rem 1rem;
            position: relative;
            z-index: 10;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .sidebar-logo {
            font-size: 1.5rem;
            color: var(--nebula-purple);
        }

        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            color: var(--neon-white);
            transition: var(--transition-normal);
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(108, 77, 246, 0.2);
            color: var(--cosmic-pink);
        }

        .nav-link i {
            width: 24px;
            text-align: center;
        }

        .main-content {
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--nebula-purple);
        }

        .username {
            font-weight: 600;
        }

        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
        }

        .settings-tabs {
            display: flex;
            border-bottom: 1px solid var(--quantum-foam);
            margin-bottom: 2rem;
        }

        .tab-btn {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--neon-white);
            cursor: pointer;
            position: relative;
            font-weight: 500;
            transition: var(--transition-normal);
        }

        .tab-btn.active {
            color: var(--cosmic-pink);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .settings-panel {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            margin-bottom: 1.5rem;
        }

        .settings-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .settings-panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--neon-white);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(15, 15, 26, 0.5);
            border: 1px solid var(--quantum-foam);
            border-radius: var(--radius-md);
            color: var(--neon-white);
            font-family: var(--font-primary);
            transition: var(--transition-normal);
        }

        .form-control:focus {
            border-color: var(--cosmic-pink);
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.3);
            outline: none;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            accent-color: var(--cosmic-pink);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .settings-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(15, 15, 26, 0.3);
            border-radius: var(--radius-md);
        }

        .preview-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .preview-app-name {
            font-weight: 600;
            font-size: 1.25rem;
        }

        /* Space decorations */
        .space-decoration {
            position: absolute;
            border-radius: 50%;
            filter: blur(20px);
            opacity: 0.3;
            z-index: -1;
        }

        .planet-1 {
            width: 300px;
            height: 300px;
            background: var(--nebula-purple);
            top: -150px;
            right: -150px;
            animation: float 15s infinite ease-in-out;
        }

        .planet-2 {
            width: 200px;
            height: 200px;
            background: var(--stellar-blue);
            bottom: -100px;
            left: -100px;
            animation: float 12s infinite ease-in-out reverse;
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .settings-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .settings-tabs {
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 768px) {
            .management-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="settings-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-astronaut"></i>
                </div>
                <div class="sidebar-title">TeamSphere</div>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-rocket"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>User Management</span>
                </a>
                <a href="departments.php" class="nav-link">
                    <i class="fas fa-globe"></i>
                    <span>Departments</span>
                </a>
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="system_settings.php" class="nav-link active">
                    <i class="fas fa-cog"></i>
                    <span>System Settings</span>
                </a>
                <a href="../auth/auth.php?action=logout" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Space decorations -->
            <div class="space-decoration planet-1"></div>
            <div class="space-decoration planet-2"></div>

            <!-- Management Header -->
            <div class="management-header">
                <h1 class="page-title">System Settings</h1>
                <div class="user-profile">
                    <div class="avatar"
                        style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                        <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="username"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        <div class="role-badge"><?= htmlspecialchars($_SESSION['role']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Display success/error messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div class="alert-content"><?= htmlspecialchars($success) ?></div>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Settings Tabs -->
            <div class="settings-tabs">
                <button class="tab-btn active" onclick="openTab('system-settings')">
                    <i class="fas fa-cog"></i> System
                </button>
                <button class="tab-btn" onclick="openTab('mail-settings')">
                    <i class="fas fa-envelope"></i> Mail
                </button>
                <button class="tab-btn" onclick="openTab('security-settings')">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="tab-btn" onclick="openTab('backup-settings')">
                    <i class="fas fa-database"></i> Backup
                </button>
                <button class="tab-btn" onclick="openTab('maintenance-settings')">
                    <i class="fas fa-tools"></i> Maintenance
                </button>
            </div>

            <!-- System Settings Tab -->
            <div id="system-settings" class="tab-content active">
                <form method="post" action="system_settings.php">
                    <input type="hidden" name="action" value="update_system_settings">

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">General Settings</h2>
                        </div>

                        <div class="form-group">
                            <label for="app_name" class="form-label">Application Name</label>
                            <input type="text" id="app_name" name="app_name" class="form-control"
                                value="<?= htmlspecialchars($systemSettings['app_name'] ?? 'TeamSphere') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select id="timezone" name="timezone" class="form-control" required>
                                <?php foreach ($timezones as $tz): ?>
                                    <option value="<?= htmlspecialchars($tz) ?>" <?= ($systemSettings['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tz) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="theme_color" class="form-label">Theme Color</label>
                            <input type="color" id="theme_color" name="theme_color" class="form-control"
                                style="height: 40px;"
                                value="<?= htmlspecialchars($systemSettings['theme_color'] ?? '#6C4DF6') ?>">
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="maintenance_mode" name="maintenance_mode"
                                class="form-check-input" <?= ($systemSettings['maintenance_mode'] ?? 0) ? 'checked' : '' ?>>
                            <label for="maintenance_mode" class="form-label">Maintenance Mode</label>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Branding</h2>
                        </div>

                        <div class="form-group">
                            <label for="logo_url" class="form-label">Logo URL</label>
                            <input type="text" id="logo_url" name="logo_url" class="form-control"
                                value="<?= htmlspecialchars($systemSettings['logo_url'] ?? '') ?>">
                            <small class="form-text">Path to your logo image (e.g., /assets/images/logo.png)</small>
                        </div>

                        <div class="form-group">
                            <label for="favicon_url" class="form-label">Favicon URL</label>
                            <input type="text" id="favicon_url" name="favicon_url" class="form-control"
                                value="<?= htmlspecialchars($systemSettings['favicon_url'] ?? '') ?>">
                            <small class="form-text">Path to your favicon (e.g., /assets/images/favicon.ico)</small>
                        </div>

                        <?php if (!empty($systemSettings['logo_url'])): ?>
                            <div class="settings-preview">
                                <img src="<?= htmlspecialchars($systemSettings['logo_url']) ?>" alt="Logo Preview"
                                    class="preview-logo">
                                <span
                                    class="preview-app-name"><?= htmlspecialchars($systemSettings['app_name'] ?? 'TeamSphere') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetSystemForm()">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Mail Settings Tab -->
            <div id="mail-settings" class="tab-content">
                <form method="post" action="system_settings.php">
                    <input type="hidden" name="action" value="update_mail_settings">

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Mail Configuration</h2>
                        </div>

                        <div class="form-group">
                            <label for="mailer" class="form-label">Mailer</label>
                            <select id="mailer" name="mailer" class="form-control" required>
                                <option value="smtp" <?= ($mailSettings['mailer'] ?? 'smtp') === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                                <option value="sendmail" <?= ($mailSettings['mailer'] ?? 'smtp') === 'sendmail' ? 'selected' : '' ?>>Sendmail</option>
                                <option value="mail" <?= ($mailSettings['mailer'] ?? 'smtp') === 'mail' ? 'selected' : '' ?>>PHP Mail</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="host" class="form-label">SMTP Host</label>
                            <input type="text" id="host" name="host" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['host'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="port" class="form-label">SMTP Port</label>
                            <input type="number" id="port" name="port" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['port'] ?? '587') ?>">
                        </div>

                        <div class="form-group">
                            <label for="username" class="form-label">SMTP Username</label>
                            <input type="text" id="username" name="username" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['username'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">SMTP Password</label>
                            <input type="password" id="password" name="password" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['password'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="encryption" class="form-label">Encryption</label>
                            <select id="encryption" name="encryption" class="form-control">
                                <option value="tls" <?= ($mailSettings['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= ($mailSettings['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="" <?= empty($mailSettings['encryption']) ? 'selected' : '' ?>>None
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Email Settings</h2>
                        </div>

                        <div class="form-group">
                            <label for="from_address" class="form-label">From Address</label>
                            <input type="email" id="from_address" name="from_address" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['from_address'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="from_name" class="form-label">From Name</label>
                            <input type="text" id="from_name" name="from_name" class="form-control"
                                value="<?= htmlspecialchars($mailSettings['from_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetMailForm()">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Security Settings Tab -->
            <div id="security-settings" class="tab-content">
                <form method="post" action="system_settings.php">
                    <input type="hidden" name="action" value="update_security_settings">

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Password Policy</h2>
                        </div>

                        <div class="form-group">
                            <label for="password_policy" class="form-label">Password Strength</label>
                            <select id="password_policy" name="password_policy" class="form-control">
                                <option value="low" <?= ($securitySettings['password_policy'] ?? 'medium') === 'low' ? 'selected' : '' ?>>Low (Minimum 6 characters)</option>
                                <option value="medium" <?= ($securitySettings['password_policy'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medium (Minimum 8 characters)</option>
                                <option value="high" <?= ($securitySettings['password_policy'] ?? 'medium') === 'high' ? 'selected' : '' ?>>High (Minimum 10 characters with complexity)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="min_password_length" class="form-label">Minimum Password Length</label>
                            <input type="number" id="min_password_length" name="min_password_length"
                                class="form-control"
                                value="<?= htmlspecialchars($securitySettings['min_password_length'] ?? '8') ?>" min="6"
                                max="32">
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="require_mixed_case" name="require_mixed_case"
                                class="form-check-input" <?= ($securitySettings['require_mixed_case'] ?? 0) ? 'checked' : '' ?>>
                            <label for="require_mixed_case" class="form-label">Require mixed case letters</label>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="require_numbers" name="require_numbers" class="form-check-input"
                                <?= ($securitySettings['require_numbers'] ?? 0) ? 'checked' : '' ?>>
                            <label for="require_numbers" class="form-label">Require numbers</label>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="require_special_chars" name="require_special_chars"
                                class="form-check-input" <?= ($securitySettings['require_special_chars'] ?? 0) ? 'checked' : '' ?>>
                            <label for="require_special_chars" class="form-label">Require special characters</label>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Login Security</h2>
                        </div>

                        <div class="form-group">
                            <label for="login_attempts" class="form-label">Max Login Attempts</label>
                            <input type="number" id="login_attempts" name="login_attempts" class="form-control"
                                value="<?= htmlspecialchars($securitySettings['login_attempts'] ?? '5') ?>" min="1"
                                max="10">
                        </div>

                        <div class="form-group">
                            <label for="lockout_time" class="form-label">Lockout Time (minutes)</label>
                            <input type="number" id="lockout_time" name="lockout_time" class="form-control"
                                value="<?= htmlspecialchars($securitySettings['lockout_time'] ?? '15') ?>" min="1"
                                max="1440">
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="two_factor_auth" name="two_factor_auth" class="form-check-input"
                                <?= ($securitySettings['two_factor_auth'] ?? 0) ? 'checked' : '' ?>>
                            <label for="two_factor_auth" class="form-label">Require Two-Factor Authentication</label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetSecurityForm()">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Backup Settings Tab -->
            <div id="backup-settings" class="tab-content">
                <form method="post" action="system_settings.php">
                    <input type="hidden" name="action" value="update_backup_settings">

                    <div class="settings-panel">
                        <div class="settings-panel-header">
                            <h2 class="settings-panel-title">Backup Configuration</h2>
                        </div>

                        <div class="form-group">
                            <label for="backup_frequency" class="form-label">Backup Frequency</label>
                            <select id="backup_frequency" name="backup_frequency" class="form-control" required>
                                <option value="daily" <?= ($backupSettings['backup_frequency'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="weekly" <?= ($backupSettings['backup_frequency'] ?? 'daily') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= ($backupSettings['backup_frequency'] ?? 'daily') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="backup_time" class="form-label">Backup Time</label>
                            <input type="time" id="backup_time" name="backup_time" class="form-control"
                                value="<?= htmlspecialchars($backupSettings['backup_time'] ?? '02:00') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="keep_backups" class="form-label">Keep Backups (days)</label>
                            <input type="number" id="keep_backups" name="keep_backups" class="form-control"
                                value="<?= htmlspecialchars($backupSettings['keep_backups'] ?? '7') ?>" min="1"
                                max="365">
                        </div>

                        <div class="form-group">
                            <label for="backup_type" class="form-label">Backup Type</label>
                            <select id="backup_type" name="backup_type" class="form-control" required>
                                <option value="full" <?= ($backupSettings['backup_type'] ?? 'full') === 'full' ? 'selected' : '' ?>>Full Backup</option>
                                <option value="incremental" <?= ($backupSettings['backup_type'] ?? 'full') === 'incremental' ? 'selected' : '' ?>>Incremental Backup</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="storage_location" class="form-label">Storage Location</label>
                            <select id="storage_location" name="storage_location" class="form-control" required>
                                <option value="local" <?= ($backupSettings['storage_location'] ?? 'local') === 'local' ? 'selected' : '' ?>>Local Server</option>
                                <option value="aws" <?= ($backupSettings['storage_location'] ?? 'local') === 'aws' ? 'selected' : '' ?>>AWS S3</option>
                                <option value="google" <?= ($backupSettings['storage_location'] ?? 'local') === 'google' ? 'selected' : '' ?>>Google Drive</option>
                                <option value="azure" <?= ($backupSettings['storage_location'] ?? 'local') === 'azure' ? 'selected' : '' ?>>Azure Blob Storage</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetBackupForm()">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-secondary" onclick="runBackupNow()">
                            <i class="fas fa-play"></i> Run Backup Now
                        </button>
                    </div>
                </form>
            </div>

            <!-- Maintenance Tab -->
            <div id="maintenance-settings" class="tab-content">
                <div class="settings-panel">
                    <div class="settings-panel-header">
                        <h2 class="settings-panel-title">System Maintenance</h2>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Clear Cache</label>
                        <p>Clear all system caches to free up memory and ensure fresh data.</p>
                        <form method="post" action="system_settings.php">
                            <input type="hidden" name="action" value="clear_cache">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-broom"></i> Clear Cache
                            </button>
                        </form>
                    </div>

                    <div class="form-group">
                        <label class="form-label">System Information</label>
                        <div style="background: rgba(15, 15, 26, 0.3); padding: 1rem; border-radius: var(--radius-md);">
                            <div><strong>PHP Version:</strong> <?= phpversion() ?></div>
                            <div><strong>Database Version:</strong> <?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?>
                            </div>
                            <div><strong>TeamSphere Version:</strong> 1.0.0</div>
                            <div><strong>Server OS:</strong> <?= php_uname() ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function openTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Deactivate all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Activate selected tab
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Form reset functions
        function resetSystemForm() {
            document.getElementById('app_name').value = 'TeamSphere';
            document.getElementById('timezone').value = 'UTC';
            document.getElementById('theme_color').value = '#6C4DF6';
            document.getElementById('maintenance_mode').checked = false;
            document.getElementById('logo_url').value = '';
            document.getElementById('favicon_url').value = '';
        }

        function resetMailForm() {
            document.getElementById('mailer').value = 'smtp';
            document.getElementById('host').value = '';
            document.getElementById('port').value = '587';
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
            document.getElementById('encryption').value = 'tls';
            document.getElementById('from_address').value = '';
            document.getElementById('from_name').value = '';
        }

        function resetSecurityForm() {
            document.getElementById('password_policy').value = 'medium';
            document.getElementById('min_password_length').value = '8';
            document.getElementById('require_mixed_case').checked = false;
            document.getElementById('require_numbers').checked = false;
            document.getElementById('require_special_chars').checked = false;
            document.getElementById('login_attempts').value = '5';
            document.getElementById('lockout_time').value = '15';
            document.getElementById('two_factor_auth').checked = false;
        }

        function resetBackupForm() {
            document.getElementById('backup_frequency').value = 'daily';
            document.getElementById('backup_time').value = '02:00';
            document.getElementById('keep_backups').value = '7';
            document.getElementById('backup_type').value = 'full';
            document.getElementById('storage_location').value = 'local';
        }

        // Run backup now
        function runBackupNow() {
            if (confirm('Are you sure you want to run a backup now? This might impact system performance.')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.action = 'system_settings.php';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'action';
                input.value = 'run_backup';

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close alert messages
        document.querySelectorAll('.alert-close').forEach(btn => {
            btn.addEventListener('click', function () {
                this.parentElement.style.display = 'none';
            });
        });
    </script>
</body>

</html>