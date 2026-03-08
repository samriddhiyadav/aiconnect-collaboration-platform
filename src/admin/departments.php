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
define('ROOT_PATH', realpath(dirname(__DIR__ . '/../../..')));
require_once ROOT_PATH . '/includes/db_connect.php';
require_once ROOT_PATH . '/includes/functions.php';

// Initialize variables
$success = '';
$error = '';
$departments = [];
$department = [];
$users = [];
$department_users = [];

try {
    $pdo = Database::getInstance();

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_department':
                handleCreateDepartment($pdo);
                break;
            case 'update_department':
                handleUpdateDepartment($pdo);
                break;
            case 'delete_department':
                handleDeleteDepartment($pdo);
                break;
            case 'update_department_users':
                handleUpdateDepartmentUsers($pdo);
                break;
        }
    }

    // Get all departments
    $stmt = $pdo->query("
        SELECT d.*, COUNT(ud.user_id) as user_count, 
               parent.name as parent_name
        FROM departments d
        LEFT JOIN user_departments ud ON d.department_id = ud.department_id
        LEFT JOIN departments parent ON d.parent_id = parent.department_id
        GROUP BY d.department_id
        ORDER BY d.parent_id, d.name
    ");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all users for assignment
    $stmt = $pdo->query("
        SELECT user_id, username, full_name, role, avatar 
        FROM users 
        WHERE status = 'active'
        ORDER BY full_name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If viewing a specific department, get its details and assigned users
    if (isset($_GET['id'])) {
        $department_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        
        $stmt = $pdo->prepare("
            SELECT d.*, parent.name as parent_name
            FROM departments d
            LEFT JOIN departments parent ON d.parent_id = parent.department_id
            WHERE d.department_id = ?
        ");
        $stmt->execute([$department_id]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($department) {
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.full_name, u.username, u.role, u.avatar, ud.is_primary
                FROM user_departments ud
                JOIN users u ON ud.user_id = u.user_id
                WHERE ud.department_id = ?
                ORDER BY u.full_name
            ");
            $stmt->execute([$department_id]);
            $department_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Log department management access
    log_activity($_SESSION['user_id'], 'department_management', 'Accessed department management');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "An error occurred while loading department data.";
}

// Handle create department
function handleCreateDepartment($pdo) {
    global $success, $error;

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_id = $_POST['parent_id'] ?? null;
    $color = $_POST['color'] ?? '#6C4DF6';

    if (empty($name)) {
        $error = 'Department name is required';
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO departments (name, description, parent_id, color)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $description, $parent_id, $color]);

        $department_id = $pdo->lastInsertId();

        $pdo->commit();

        log_activity($_SESSION['user_id'], 'department_create', "Created department $name ($department_id)");
        $success = "Department created successfully";
        header("Location: departments.php?id=$department_id");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Department creation failed: " . $e->getMessage());
        $error = 'Failed to create department';
    }
}

// Handle update department
function handleUpdateDepartment($pdo) {
    global $success, $error;

    $department_id = $_POST['department_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_id = $_POST['parent_id'] ?? null;
    $color = $_POST['color'] ?? '#6C4DF6';

    if (empty($name)) {
        $error = 'Department name is required';
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE departments 
            SET name = ?, description = ?, parent_id = ?, color = ?
            WHERE department_id = ?
        ");
        $stmt->execute([$name, $description, $parent_id, $color, $department_id]);

        $pdo->commit();

        log_activity($_SESSION['user_id'], 'department_update', "Updated department $name ($department_id)");
        $success = "Department updated successfully";
        header("Location: departments.php?id=$department_id");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Department update failed: " . $e->getMessage());
        $error = 'Failed to update department';
    }
}

// Handle delete department
function handleDeleteDepartment($pdo) {
    global $success, $error;

    $department_id = $_POST['department_id'] ?? 0;

    try {
        // Get department info before deletion
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE department_id = ?");
        $stmt->execute([$department_id]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$department) {
            $error = 'Department not found';
            return;
        }

        $pdo->beginTransaction();

        // First, remove all user associations
        $stmt = $pdo->prepare("DELETE FROM user_departments WHERE department_id = ?");
        $stmt->execute([$department_id]);

        // Then delete the department
        $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id = ?");
        $stmt->execute([$department_id]);

        $pdo->commit();

        log_activity($_SESSION['user_id'], 'department_delete', "Deleted department {$department['name']} ($department_id)");
        $success = "Department deleted successfully";
        header("Location: departments.php");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Department deletion failed: " . $e->getMessage());
        $error = 'Failed to delete department';
    }
}

// Handle update department users
function handleUpdateDepartmentUsers($pdo) {
    global $success, $error;

    $department_id = $_POST['department_id'] ?? 0;
    $user_ids = $_POST['user_ids'] ?? [];
    $primary_user = $_POST['primary_user'] ?? null;

    try {
        $pdo->beginTransaction();

        // Remove all current users from this department
        $stmt = $pdo->prepare("DELETE FROM user_departments WHERE department_id = ?");
        $stmt->execute([$department_id]);

        // Add selected users back
        if (!empty($user_ids)) {
            foreach ($user_ids as $user_id) {
                $is_primary = ($user_id == $primary_user) ? 1 : 0;
                $stmt = $pdo->prepare("
                    INSERT INTO user_departments (user_id, department_id, is_primary)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user_id, $department_id, $is_primary]);
            }
        }

        $pdo->commit();

        log_activity($_SESSION['user_id'], 'department_users_update', "Updated users for department $department_id");
        $success = "Department users updated successfully";
        header("Location: departments.php?id=$department_id");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Department users update failed: " . $e->getMessage());
        $error = 'Failed to update department users';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Department Management</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .department-management-container {
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1.5rem;
        }

        .department-list {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }

        .department-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition-fast);
            border-left: 3px solid transparent;
        }

        .department-item:hover {
            background: rgba(108, 77, 246, 0.1);
        }

        .department-item.active {
            background: rgba(108, 77, 246, 0.2);
            border-left-color: var(--cosmic-pink);
        }

        .department-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .department-meta {
            font-size: 0.8rem;
            opacity: 0.7;
            display: flex;
            justify-content: space-between;
        }

        .department-details {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
        }

        .department-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .department-title {
            font-size: 1.5rem;
            font-weight: 600;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .department-color {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-block;
            vertical-align: middle;
            margin-right: 0.5rem;
        }

        .department-description {
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .department-users {
            margin-top: 2rem;
        }

        .users-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .user-card {
            background: rgba(15, 15, 26, 0.4);
            border-radius: var(--radius-md);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition-fast);
            border: 1px solid transparent;
        }

        .user-card:hover {
            border-color: var(--cosmic-pink);
        }

        .user-card.primary {
            background: rgba(108, 77, 246, 0.2);
            border-color: var(--nebula-purple);
        }

        .user-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-role {
            font-size: 0.8rem;
            opacity: 0.7;
            text-transform: capitalize;
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

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--nebula-purple), var(--cosmic-pink));
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--neon-white);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-danger {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
        }

        .btn-danger:hover {
            background: rgba(244, 67, 54, 0.3);
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
            .department-management-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .management-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
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

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 4px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 15, 26, 0.4);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--stellar-blue);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--nebula-purple);
        }

        .modal-content {
            scrollbar-width: thin;
            scrollbar-color: var(--stellar-blue) rgba(15, 15, 26, 0.4);
        }
    </style>
</head>

<body>
    <div class="department-management-container">
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
                <a href="departments.php" class="nav-link active">
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
                <h1 class="page-title">Department Management</h1>
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
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Department List -->
                <div class="department-list">
                    <button class="btn btn-primary" style="width: 100%; margin-bottom: 1.5rem;" onclick="openModal('create-department-modal')">
                        <i class="fas fa-plus"></i> New Department
                    </button>

                    <?php foreach ($departments as $dept): ?>
                        <div class="department-item <?= isset($_GET['id']) && $_GET['id'] == $dept['department_id'] ? 'active' : '' ?>"
                            onclick="window.location.href='departments.php?id=<?= $dept['department_id'] ?>'">
                            <div class="department-name">
                                <span class="department-color" style="background-color: <?= $dept['color'] ?>"></span>
                                <?= htmlspecialchars($dept['name']) ?>
                            </div>
                            <div class="department-meta">
                                <span><?= $dept['user_count'] ?> members</span>
                                <?php if ($dept['parent_name']): ?>
                                    <span>Part of <?= htmlspecialchars($dept['parent_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Department Details -->
                <div class="department-details">
                    <?php if (isset($_GET['id']) && $department): ?>
                        <div class="department-header">
                            <h2 class="department-title">
                                <span class="department-color" style="background-color: <?= $department['color'] ?>"></span>
                                <?= htmlspecialchars($department['name']) ?>
                            </h2>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn btn-secondary" onclick="openModal('edit-department-modal')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-danger" onclick="confirmDeleteDepartment()">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>

                        <?php if ($department['description']): ?>
                            <div class="department-description">
                                <?= nl2br(htmlspecialchars($department['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($department['parent_name']): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <strong>Parent Department:</strong> <?= htmlspecialchars($department['parent_name']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="department-users">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h3 style="margin: 0;">Department Members</h3>
                                <button class="btn btn-secondary" onclick="openModal('manage-users-modal')">
                                    <i class="fas fa-user-edit"></i> Manage Members
                                </button>
                            </div>

                            <?php if (!empty($department_users)): ?>
                                <div class="users-list">
                                    <?php foreach ($department_users as $user): ?>
                                        <div class="user-card <?= $user['is_primary'] ? 'primary' : '' ?>">
                                            <div class="avatar"
                                                style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                                                <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
                                            </div>
                                            <?php if ($user['is_primary']): ?>
                                                <i class="fas fa-star" style="color: var(--cosmic-pink);"></i>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; opacity: 0.7;">
                                    No members assigned to this department
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem; opacity: 0.7;">
                            Select a department to view details
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Department Modal -->
    <div class="modal" id="create-department-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('create-department-modal')">&times;</button>
            <h2 class="modal-title">Create New Department</h2>

            <form method="post" action="departments.php">
                <input type="hidden" name="action" value="create_department">

                <div class="form-group">
                    <label for="create-name" class="form-label">Department Name</label>
                    <input type="text" id="create-name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="create-description" class="form-label">Description</label>
                    <textarea id="create-description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="create-parent" class="form-label">Parent Department (optional)</label>
                    <select id="create-parent" name="parent_id" class="form-control">
                        <option value="">None</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="create-color" class="form-label">Color</label>
                    <input type="color" id="create-color" name="color" class="form-control" style="height: 40px;" value="#6C4DF6">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('create-department-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Department</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <?php if (isset($_GET['id']) && $department): ?>
        <div class="modal" id="edit-department-modal">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('edit-department-modal')">&times;</button>
                <h2 class="modal-title">Edit Department</h2>

                <form method="post" action="departments.php">
                    <input type="hidden" name="action" value="update_department">
                    <input type="hidden" name="department_id" value="<?= $department['department_id'] ?>">

                    <div class="form-group">
                        <label for="edit-name" class="form-label">Department Name</label>
                        <input type="text" id="edit-name" name="name" class="form-control" value="<?= htmlspecialchars($department['name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-description" class="form-label">Description</label>
                        <textarea id="edit-description" name="description" class="form-control" rows="3"><?= htmlspecialchars($department['description']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit-parent" class="form-label">Parent Department (optional)</label>
                        <select id="edit-parent" name="parent_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($departments as $dept): ?>
                                <?php if ($dept['department_id'] != $department['department_id']): ?>
                                    <option value="<?= $dept['department_id'] ?>" <?= $department['parent_id'] == $dept['department_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-color" class="form-label">Color</label>
                        <input type="color" id="edit-color" name="color" class="form-control" style="height: 40px;" value="<?= $department['color'] ?>">
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('edit-department-modal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Department</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Department Confirmation -->
        <form method="post" action="departments.php" id="delete-department-form">
            <input type="hidden" name="action" value="delete_department">
            <input type="hidden" name="department_id" value="<?= $department['department_id'] ?>">
        </form>

        <!-- Manage Users Modal -->
        <div class="modal" id="manage-users-modal">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('manage-users-modal')">&times;</button>
                <h2 class="modal-title">Manage Department Members</h2>

                <form method="post" action="departments.php">
                    <input type="hidden" name="action" value="update_department_users">
                    <input type="hidden" name="department_id" value="<?= $department['department_id'] ?>">

                    <div class="form-group">
                        <label class="form-label">Select Members</label>
                        <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--quantum-foam); border-radius: var(--radius-md); padding: 1rem;">
                            <?php foreach ($users as $user): ?>
                                <div style="margin-bottom: 0.5rem;">
                                    <input type="checkbox" id="user-<?= $user['user_id'] ?>" name="user_ids[]" value="<?= $user['user_id'] ?>"
                                                                                <?= in_array($user['user_id'], array_column($department_users, 'user_id')) ? 'checked' : '' ?>>
                                    <label for="user-<?= $user['user_id'] ?>" style="margin-left: 0.5rem;">
                                        <?= htmlspecialchars($user['full_name']) ?> (@<?= htmlspecialchars($user['username']) ?>)
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="primary-user" class="form-label">Primary Contact</label>
                        <select id="primary-user" name="primary_user" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($department_users as $user): ?>
                                <option value="<?= $user['user_id'] ?>" <?= $user['is_primary'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('manage-users-modal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

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
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Confirm department deletion
        function confirmDeleteDepartment() {
            if (confirm('Are you sure you want to delete this department? All user assignments will be removed.')) {
                document.getElementById('delete-department-form').submit();
            }
        }

        // Update primary user dropdown when checkboxes change
        document.querySelectorAll('input[name="user_ids[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const primarySelect = document.getElementById('primary-user');
                const userId = this.value;
                const option = Array.from(primarySelect.options).find(opt => opt.value === userId);
                
                if (this.checked) {
                    if (!option) {
                        const newOption = document.createElement('option');
                        newOption.value = userId;
                        newOption.text = this.parentElement.textContent.trim();
                        primarySelect.appendChild(newOption);
                    }
                } else {
                    if (option) {
                        primarySelect.removeChild(option);
                        if (primarySelect.value === userId) {
                            primarySelect.value = '';
                        }
                    }
                }
            });
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
    </script>
</body>
</html>