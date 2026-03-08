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
require_once __DIR__ . '/../../includes/db_connect.php';

$receiver_id = $_POST['receiver_id'] ?? null;
$content = $_POST['content'] ?? null;

try {
    $pdo = Database::getInstance();
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Handle message submission if form was posted
if ($receiver_id && $content) {
    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, sent_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], $receiver_id, $content]);

        // Set success message
        $_SESSION['message_sent'] = true;
        $_SESSION['message_success'] = "Message sent successfully!";

        header("Location: users.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['message_error'] = "Failed to send message: " . $e->getMessage();
        header("Location: users.php");
        exit;
    }
}

// Function to log activities
function logActivity($pdo, $user_id, $action, $details)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, role, action, details) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $_SESSION['role'],
            $action,
            $details
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

try {
    $pdo = Database::getInstance();

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_user':
                handleCreateUser($pdo);
                break;
            case 'update_user':
                handleUpdateUser($pdo);
                break;
            case 'delete_user':
                handleDeleteUser($pdo);
                break;
            case 'bulk_action':
                handleBulkAction($pdo);
                break;
            case 'update_permissions':
                handleUpdatePermissions($pdo);
                break;
        }
    }

    // Get all users with their roles
    $stmt = $pdo->query("
        SELECT u.*, GROUP_CONCAT(DISTINCT rp.permission_id) as role_permissions
        FROM users u
        LEFT JOIN role_permissions rp ON u.role = rp.role
        GROUP BY u.user_id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all permissions
    $stmt = $pdo->query("SELECT * FROM permissions ORDER BY name");
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user-specific permissions
    $userPermissions = [];
    $stmt = $pdo->query("SELECT user_id, permission_id FROM user_permissions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userPermissions[$row['user_id']][] = $row['permission_id'];
    }

    // Log user management access
    logActivity($pdo, $_SESSION['user_id'], 'user_management', 'Accessed user management');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while loading user data.";
}

// Handle create user
function handleCreateUser($pdo)
{
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'employee';

    // Validate input
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $_SESSION['error'] = 'All fields are required';
        return;
    }

    if (strlen($password) < 8) {
        $_SESSION['error'] = 'Password must be at least 8 characters';
        return;
    }

    try {
        // Check if username or email exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'Username or email already exists';
            return;
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Create user
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, full_name, role, join_date, last_login) 
            VALUES (?, ?, ?, ?, ?, NOW(), NULL)
        ");
        $stmt->execute([$username, $email, $password_hash, $full_name, $role]);

        $user_id = $pdo->lastInsertId();

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], 'user_create', "Created user $username ($user_id)");

        $_SESSION['success'] = "User created successfully";
        header("Location: users.php");
        exit;

    } catch (PDOException $e) {
        error_log("User creation failed: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to create user';
    }
}

// Handle update user
function handleUpdateUser($pdo)
{
    $user_id = $_POST['user_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'employee';
    $status = $_POST['status'] ?? 'active';

    // Validate input
    if (empty($username) || empty($email) || empty($full_name)) {
        $_SESSION['error'] = 'All fields are required';
        return;
    }

    try {
        // Check if username or email exists for another user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
        $stmt->execute([$username, $email, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'Username or email already exists';
            return;
        }

        // Update user
        $stmt = $pdo->prepare("
            UPDATE users 
            SET username = ?, email = ?, full_name = ?, role = ?, status = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$username, $email, $full_name, $role, $status, $user_id]);

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], 'user_update', "Updated user $username ($user_id)");

        $_SESSION['success'] = "User updated successfully";
        header("Location: users.php");
        exit;

    } catch (PDOException $e) {
        error_log("User update failed: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to update user';
    }
}

// Handle delete user
function handleDeleteUser($pdo)
{
    $user_id = $_POST['user_id'] ?? 0;

    try {
        // Get user info before deletion
        $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            return;
        }

        // Delete user (cascade deletes will handle related records)
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], 'user_delete', "Deleted user {$user['username']} ($user_id)");

        $_SESSION['success'] = "User deleted successfully";
        header("Location: users.php");
        exit;

    } catch (PDOException $e) {
        error_log("User deletion failed: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to delete user';
    }
}

// Handle bulk actions
function handleBulkAction($pdo)
{
    $user_ids = $_POST['user_ids'] ?? [];
    $bulk_action = $_POST['bulk_action'] ?? '';

    if (empty($user_ids)) {
        $_SESSION['error'] = 'No users selected';
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));

        switch ($bulk_action) {
            case 'delete':
                // Get usernames for logging
                $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id IN ($placeholders)");
                $stmt->execute($user_ids);
                $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id IN ($placeholders)");
                $stmt->execute($user_ids);

                logActivity($pdo, $_SESSION['user_id'], 'bulk_delete', "Deleted users: " . implode(', ', $usernames));
                $_SESSION['success'] = count($user_ids) . " users deleted successfully";
                break;

            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE user_id IN ($placeholders)");
                $stmt->execute($user_ids);

                logActivity($pdo, $_SESSION['user_id'], 'bulk_activate', "Activated " . count($user_ids) . " users");
                $_SESSION['success'] = count($user_ids) . " users activated successfully";
                break;

            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE user_id IN ($placeholders)");
                $stmt->execute($user_ids);

                logActivity($pdo, $_SESSION['user_id'], 'bulk_deactivate', "Deactivated " . count($user_ids) . " users");
                $_SESSION['success'] = count($user_ids) . " users deactivated successfully";
                break;

            case 'change_role':
                $new_role = $_POST['new_role'] ?? 'employee';
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE user_id IN ($placeholders)");
                $stmt->execute(array_merge([$new_role], $user_ids));

                logActivity($pdo, $_SESSION['user_id'], 'bulk_role_change', "Changed role to $new_role for " . count($user_ids) . " users");
                $_SESSION['success'] = "Updated role for " . count($user_ids) . " users";
                break;

            default:
                $_SESSION['error'] = 'Invalid bulk action';
                return;
        }

        header("Location: users.php");
        exit;

    } catch (PDOException $e) {
        error_log("Bulk action failed: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to perform bulk action';
    }
}

// Handle permission updates
function handleUpdatePermissions($pdo)
{
    $user_id = $_POST['user_id'] ?? 0;
    $permissions = $_POST['permissions'] ?? [];

    try {
        // Begin transaction
        $pdo->beginTransaction();

        // Remove all current permissions
        $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Add new permissions
        if (!empty($permissions)) {
            $values = [];
            foreach ($permissions as $perm_id) {
                $values[] = "($user_id, $perm_id)";
            }
            $values_str = implode(',', $values);
            $pdo->exec("INSERT INTO user_permissions (user_id, permission_id) VALUES $values_str");
        }

        // Commit transaction
        $pdo->commit();

        // Get username for logging
        $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();

        logActivity($pdo, $_SESSION['user_id'], 'permissions_update', "Updated permissions for $username ($user_id)");
        $_SESSION['success'] = "Permissions updated successfully";
        header("Location: users.php");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Permission update failed: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to update permissions';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | User Management</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .user-management-container {
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

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .bulk-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .table-container {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            overflow-x: auto;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th,
        .users-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .users-table th {
            font-weight: 600;
            color: var(--cosmic-pink);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        .users-table tr:hover {
            background: rgba(108, 77, 246, 0.1);
        }

        .user-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
        }

        .status-inactive {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
        }

        .role-select {
            background: rgba(15, 15, 26, 0.5);
            border: 1px solid var(--quantum-foam);
            border-radius: var(--radius-md);
            color: var(--neon-white);
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--neon-white);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            transition: var(--transition-fast);
        }

        .action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--cosmic-pink);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 100;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: rgba(15, 15, 26, 0.95);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            border: 1px solid var(--nebula-purple);
            box-shadow: 0 0 30px rgba(108, 77, 246, 0.5);
            position: relative;
        }

        .close-modal {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: var(--neon-white);
            font-size: 1.5rem;
            cursor: pointer;
            opacity: 0.7;
            transition: var(--transition-fast);
        }

        .close-modal:hover {
            opacity: 1;
            color: var(--cosmic-pink);
        }

        .modal-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
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

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .permission-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Alert styles */
        .alert {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.2);
            border-left: 4px solid #F44336;
            color: #F44336;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            border-left: 4px solid #4CAF50;
            color: #4CAF50;
        }

        .alert i {
            font-size: 1.5rem;
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .user-management-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .action-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .bulk-actions {
                width: 100%;
                flex-wrap: wrap;
            }
        }

        /* Animation for floating planets */
        @keyframes float {
            0% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }

            100% {
                transform: translateY(0px);
            }
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(15, 15, 26, 0.95);
            color: var(--neon-white);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            border-left: 4px solid var(--cosmic-pink);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 1000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast i {
            font-size: 1.5rem;
            color: var(--cosmic-pink);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 15, 26, 0.3);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(108, 77, 246, 0.5);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(108, 77, 246, 0.7);
        }
    </style>
</head>

<body>
    <div class="user-management-container">
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
                <a href="users.php" class="nav-link active">
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
                <a href="system_settings.php" class="nav-link">
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
                <h1 class="page-title">User Management</h1>
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

            <!-- Action Bar -->
            <div class="action-bar">
                <button class="btn btn-primary" onclick="openModal('create-user-modal')">
                    <i class="fas fa-plus"></i> Create User
                </button>

                <form method="post" action="users.php" id="bulk-form">
                    <input type="hidden" name="action" value="bulk_action">
                    <select name="bulk_action" class="form-control" style="width: 200px;" required
                        id="bulk-action-select">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                        <option value="change_role">Change Role</option>
                    </select>

                    <div id="role-select-container" style="display: none;">
                        <select name="new_role" class="form-control" style="width: 150px;">
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-secondary" id="bulk-submit">Apply</button>
                </form>

                <div>
                    <input type="text" class="form-control" placeholder="Search users..." id="user-search">
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-container">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?= htmlspecialchars($_SESSION['error']);
                        unset($_SESSION['error']); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div><?= htmlspecialchars($_SESSION['success']);
                        unset($_SESSION['success']); ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="users.php" id="users-form">
                    <input type="hidden" name="action" value="bulk_action">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="select-all"></th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><input type="checkbox" name="user_ids[]" value="<?= $user['user_id'] ?>"
                                            class="user-checkbox"></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <div class="avatar"
                                                style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div><?= htmlspecialchars($user['full_name']) ?></div>
                                                <div style="font-size: 0.8rem; opacity: 0.7;">
                                                    @<?= htmlspecialchars($user['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge"><?= htmlspecialchars($user['role']) ?></span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $user['status'] ?>">
                                            <?= ucfirst($user['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($user['join_date'])) ?></td>
                                    <td><?= $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never' ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button type="button" class="action-btn"
                                                onclick="openEditModal(<?= $user['user_id'] ?>, '<?= htmlspecialchars($user['username']) ?>', '<?= htmlspecialchars($user['email']) ?>', '<?= htmlspecialchars($user['full_name']) ?>', '<?= $user['role'] ?>', '<?= $user['status'] ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="action-btn"
                                                onclick="openPermissionsModal(<?= $user['user_id'] ?>, '<?= htmlspecialchars($user['full_name']) ?>', <?= $user['role'] === 'admin' ? 'true' : 'false' ?>, '<?= $user['role_permissions'] ?>', <?= isset($userPermissions[$user['user_id']]) ? json_encode($userPermissions[$user['user_id']]) : '[]' ?>)">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <form method="post" action="users.php" style="display: inline;"
                                                onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                <button type="submit" class="action-btn">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <button type="button" class="action-btn"
                                                    onclick="openMessageModal(<?= $user['user_id'] ?>, '<?= htmlspecialchars(addslashes($user['full_name'])) ?>')"
                                                    title="Send Message">
                                                    <i class="fas fa-envelope"></i>
                                                    <?php if (isset($_SESSION['message_sent'])): ?>
                                                        <div class="alert alert-success">
                                                            <i class="fas fa-check-circle"></i>
                                                            <div>Message sent successfully!</div>
                                                        </div>
                                                        <?php unset($_SESSION['message_sent']); ?>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal" id="create-user-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('create-user-modal')">&times;</button>
            <h2 class="modal-title">Create New User</h2>

            <form method="post" action="users.php">
                <input type="hidden" name="action" value="create_user">

                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Role</label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="employee" selected>Employee</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('create-user-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="edit-user-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('edit-user-modal')">&times;</button>
            <h2 class="modal-title">Edit User</h2>

            <form method="post" action="users.php">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="edit-user-id" name="user_id">

                <div class="form-group">
                    <label for="edit-username" class="form-label">Username</label>
                    <input type="text" id="edit-username" name="username" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-email" class="form-label">Email</label>
                    <input type="email" id="edit-email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-full_name" class="form-label">Full Name</label>
                    <input type="text" id="edit-full_name" name="full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-role" class="form-label">Role</label>
                    <select id="edit-role" name="role" class="form-control" required>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="employee">Employee</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit-status" class="form-label">Status</label>
                    <select id="edit-status" name="status" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('edit-user-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div class="modal" id="permissions-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('permissions-modal')">&times;</button>
            <h2 class="modal-title">Manage Permissions for <span id="perm-username"></span></h2>

            <form method="post" action="users.php">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" id="perm-user-id" name="user_id">

                <div class="form-group">
                    <label class="form-label">Role Permissions</label>
                    <div id="role-permissions" style="margin-bottom: 1rem;"></div>

                    <label class="form-label">Additional User Permissions</label>
                    <div class="permissions-grid">
                        <?php foreach ($permissions as $perm): ?>
                            <div class="permission-item">
                                <input type="checkbox" id="perm-<?= $perm['permission_id'] ?>" name="permissions[]"
                                    value="<?= $perm['permission_id'] ?>">
                                <label
                                    for="perm-<?= $perm['permission_id'] ?>"><?= htmlspecialchars($perm['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('permissions-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Permissions</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-content"
            style="background: rgba(15, 15, 26, 0.95); border-radius: 8px; padding: 2rem; width: 90%; max-width: 500px; border: 1px solid var(--nebula-purple); max-height: 80vh; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="messageModalTitle" style="margin: 0;">Conversation with <span id="recipientName"></span></h2>
                <button onclick="closeMessageModal()"
                    style="background: none; border: none; color: var(--neon-white); font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>

            <?php if (isset($_SESSION['message_sent'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>Message sent successfully!</div>
                </div>
                <button onclick="closeMessageModal()" class="btn btn-primary"
                    style="width: 100%; padding: 0.75rem; margin-bottom: 1rem;">Close</button>
                <?php unset($_SESSION['message_sent']); ?>
            <?php else: ?>
                <?php if (isset($_SESSION['message_error'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?= htmlspecialchars($_SESSION['message_error']) ?></div>
                    </div>
                    <?php unset($_SESSION['message_error']); ?>
                <?php endif; ?>

                <!-- Conversation history -->
                <div id="conversationHistory"
                    style="flex: 1; overflow-y: auto; margin-bottom: 1.5rem; padding-right: 0.5rem;">
                    <!-- Messages will be loaded here via JavaScript -->
                </div>

                <!-- Message form -->
                <form method="POST" action="users.php" onsubmit="return sendMessage(event)" style="margin-top: auto;">
                    <input type="hidden" name="send_message" value="1">
                    <input type="hidden" id="receiverId" name="receiver_id">
                    <div style="margin-bottom: 1.5rem;">
                        <textarea id="messageContent" name="content" rows="3"
                            style="width: 100%; padding: 0.75rem; background: rgba(224, 224, 255, 0.05); border: 1px solid rgba(224, 224, 255, 0.1); border-radius: 8px; color: var(--neon-white);"
                            placeholder="Type your message here..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem;">Send
                        Message</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside content
        window.onclick = function (event) {
            // Handle modal clicks
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            // Handle message modal specifically
            const modal = document.getElementById('messageModal');
            if (event.target === modal) {
                closeMessageModal();
            }
        };

        // Bulk action role select toggle
        document.querySelector('select[name="bulk_action"]').addEventListener('change', function () {
            const roleContainer = document.getElementById('role-select-container');
            roleContainer.style.display = this.value === 'change_role' ? 'block' : 'none';
        });

        // Select all checkbox
        document.getElementById('select-all').addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('input[name="user_ids[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Open edit modal with user data
        function openEditModal(userId, username, email, fullName, role, status) {
            document.getElementById('edit-user-id').value = userId;
            document.getElementById('edit-username').value = username;
            document.getElementById('edit-email').value = email;
            document.getElementById('edit-full_name').value = fullName;
            document.getElementById('edit-role').value = role;
            document.getElementById('edit-status').value = status;
            openModal('edit-user-modal');
        }

        // Open permissions modal
        function openPermissionsModal(userId, fullName, isAdmin, rolePerms, userPerms) {
            document.getElementById('perm-user-id').value = userId;
            document.getElementById('perm-username').textContent = fullName;

            // Clear previous role permissions
            const rolePermsContainer = document.getElementById('role-permissions');
            rolePermsContainer.innerHTML = '';

            // Add role permissions info
            if (isAdmin) {
                rolePermsContainer.innerHTML = '<div class="alert alert-info">This user is an Admin and has all permissions.</div>';
            } else {
                const rolePermsList = rolePerms ? rolePerms.split(',').map(Number) : [];
                if (rolePermsList.length > 0) {
                    rolePermsContainer.innerHTML = '<div class="alert alert-info">This user has the following permissions from their role:</div>';
                    const permList = document.createElement('ul');
                    permList.style.marginLeft = '1.5rem';
                    permList.style.marginTop = '0.5rem';

                    <?php
                    $permsById = [];
                    foreach ($permissions as $perm) {
                        $permsById[$perm['permission_id']] = $perm['name'];
                    }
                    ?>

                    rolePermsList.forEach(permId => {
                        if (<?= json_encode($permsById) ?>[permId]) {
                            const li = document.createElement('li');
                            li.textContent = <?= json_encode($permsById) ?>[permId];
                            permList.appendChild(li);
                        }
                    });

                    rolePermsContainer.appendChild(permList);
                } else {
                    rolePermsContainer.innerHTML = '<div class="alert alert-warning">This role has no permissions assigned.</div>';
                }
            }

            // Set user permissions checkboxes
            document.querySelectorAll('#permissions-modal input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });

            if (userPerms && userPerms.length > 0) {
                userPerms.forEach(permId => {
                    const checkbox = document.getElementById(`perm-${permId}`);
                    if (checkbox) checkbox.checked = true;
                });
            }

            openModal('permissions-modal');
        }

        // User search functionality
        document.getElementById('user-search').addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.users-table tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Bulk action form validation
        // Replace the existing bulk action form validation with this:
        document.getElementById('bulk-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const selectedAction = document.getElementById('bulk-action-select').value;
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            const userIds = Array.from(checkedBoxes).map(cb => cb.value);

            if (selectedAction === '') {
                alert('Please select a bulk action');
                return;
            }

            if (userIds.length === 0) {
                alert('Please select at least one user');
                return;
            }

            if (!confirm(`Are you sure you want to ${selectedAction} ${userIds.length} user(s)?`)) {
                return;
            }

            // Create a hidden form to submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'users.php';

            // Add action input
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_action';
            form.appendChild(actionInput);

            // Add bulk action input
            const bulkActionInput = document.createElement('input');
            bulkActionInput.type = 'hidden';
            bulkActionInput.name = 'bulk_action';
            bulkActionInput.value = selectedAction;
            form.appendChild(bulkActionInput);

            // Add user IDs
            userIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            // Add role if changing roles
            if (selectedAction === 'change_role') {
                const roleInput = document.createElement('input');
                roleInput.type = 'hidden';
                roleInput.name = 'new_role';
                roleInput.value = document.querySelector('select[name="new_role"]').value;
                form.appendChild(roleInput);
            }

            document.body.appendChild(form);
            form.submit();
        });

        // Show success/error messages for a few seconds then fade out
        const alerts = document.querySelectorAll('.alert');
        if (alerts.length > 0) {
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        }

        function openMessageModal(userId, userName) {
            document.getElementById('receiverId').value = userId;
            document.getElementById('recipientName').textContent = userName;
            document.getElementById('messageModalTitle').textContent = `Conversation with ${userName}`;
            document.getElementById('messageModal').style.display = 'flex';
            document.getElementById('messageContent').value = '';

            // Load conversation history
            loadConversation(userId);
        }

        // Function to load conversation history
        function loadConversation(receiverId) {
            const conversationHistory = document.getElementById('conversationHistory');
            conversationHistory.innerHTML = '<div style="text-align: center; padding: 1rem;">Loading messages...</div>';

            fetch(`get_messages.php?receiver_id=${receiverId}`, {
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin'
            })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            throw new Error(`Invalid response: ${text.substring(0, 100)}`);
                        });
                    }
                    return response.json();
                })
                .then(messages => {
                    if (messages.error) {
                        throw new Error(messages.error);
                    }

                    if (messages.length === 0) {
                        conversationHistory.innerHTML = '<div style="text-align: center; padding: 1rem; color: var(--neon-white); opacity: 0.7;">No messages yet. Start the conversation!</div>';
                        return;
                    }

                    let html = '';
                    messages.forEach(message => {
                        const isCurrentUser = message.sender_id == <?= $_SESSION['user_id'] ?>;
                        const messageTime = new Date(message.sent_at).toLocaleString();

                        html += `
            <div style="margin-bottom: 1rem; display: flex; flex-direction: column; align-items: ${isCurrentUser ? 'flex-end' : 'flex-start'}">
                <div style="max-width: 80%; background: ${isCurrentUser ? 'rgba(108, 77, 246, 0.2)' : 'rgba(255, 255, 255, 0.1)'}; 
                    border-radius: 12px; padding: 0.75rem; border: 1px solid ${isCurrentUser ? 'var(--nebula-purple)' : 'var(--quantum-foam)'}; 
                    word-wrap: break-word;">
                    <div style="font-size: 0.85rem; margin-bottom: 0.5rem; color: ${isCurrentUser ? 'var(--cosmic-pink)' : 'var(--stellar-blue)'}">
                        ${isCurrentUser ? 'You' : message.sender_name}
                    </div>
                    <div>${message.content}</div>
                    <div style="font-size: 0.7rem; text-align: right; margin-top: 0.5rem; opacity: 0.7;">
                        ${messageTime}
                    </div>
                </div>
            </div>
            `;
                    });

                    conversationHistory.innerHTML = html;
                    conversationHistory.scrollTop = conversationHistory.scrollHeight;
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    conversationHistory.innerHTML = `
            <div style="text-align: center; padding: 1rem; color: #F44336;">
                Error loading messages: ${error.message}
            </div>
        `;
                });
        }
        function closeMessageModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        function sendMessage(event) {
            event.preventDefault();

            const content = document.getElementById('messageContent').value.trim();
            if (!content) {
                showToast('Please enter a message', true);
                return false;
            }

            const form = event.target;
            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                }
            })
                .then(response => {
                    if (response.redirected) {
                        // If the server redirected (which it does on success), show toast and reload
                        showToast('Message sent successfully!');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500); // Reload after 1.5 seconds
                    } else {
                        return response.json().then(data => {
                            if (data.success) {
                                showToast('Message sent successfully!');
                                document.getElementById('messageContent').value = '';
                                loadConversation(document.getElementById('receiverId').value);
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                throw new Error(data.error || 'Failed to send message');
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast(error.message || 'Failed to send message', true);
                });

            return false; // Prevent default form submission
        }

        function showToast(message, isError = false) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toast-message');
            const toastIcon = toast.querySelector('i');

            toastMessage.textContent = message;

            if (isError) {
                toast.style.borderLeftColor = '#F44336';
                toastIcon.className = 'fas fa-exclamation-circle';
                toastIcon.style.color = '#F44336';
            } else {
                toast.style.borderLeftColor = 'var(--cosmic-pink)';
                toastIcon.className = 'fas fa-check-circle';
                toastIcon.style.color = 'var(--cosmic-pink)';
            }

            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    </script>

</body>

</html>