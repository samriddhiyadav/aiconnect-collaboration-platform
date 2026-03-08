<?php
// Manager Team - Complete Standalone Version
// File: src/manager/team.php

// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/auth.php");
    exit();
}

// Verify manager role
if ($_SESSION['role'] !== 'manager') {
    header("Location: ../employee/dashboard.php");
    exit();
}

// Database connection
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "teamsphere";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get department info
$dept_stmt = $pdo->prepare(
    "SELECT d.* FROM departments d
    JOIN user_departments ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ? AND ud.is_primary = 1"
);
$dept_stmt->execute([$user_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Get active tab from URL or default to 'members'
$active_tab = $_GET['tab'] ?? 'members';

// Get team members
$team_members_stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.email, u.phone, u.job_title, u.role, u.avatar, 
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.user_id AND status != 'completed') as pending_tasks,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.user_id AND status = 'completed') as completed_tasks,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.user_id AND deadline < NOW() AND status != 'completed') as overdue_tasks
    FROM users u
    JOIN user_departments ud ON u.user_id = ud.user_id
    WHERE ud.department_id = ? AND u.user_id != ?
    ORDER BY u.full_name ASC"
);
$team_members_stmt->execute([$department['department_id'], $user_id]);
$team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get team tasks based on active tab
$team_tasks = [];
if ($active_tab === 'tasks' || $active_tab === 'completed' || $active_tab === 'overdue') {
    $task_status_condition = '';
    if ($active_tab === 'tasks') {
        $task_status_condition = "AND t.status != 'completed'";
    } elseif ($active_tab === 'completed') {
        $task_status_condition = "AND t.status = 'completed'";
    } elseif ($active_tab === 'overdue') {
        $task_status_condition = "AND t.deadline < NOW() AND t.status != 'completed'";
    }

    $team_tasks_stmt = $pdo->prepare(
        "SELECT t.task_id, t.title, t.description, t.status, t.priority, t.deadline, 
        t.created_at, t.completed_at, u.full_name as assignee, u.user_id as assignee_id
        FROM tasks t
        JOIN user_departments ud ON t.assigned_to = ud.user_id
        JOIN users u ON t.assigned_to = u.user_id
        WHERE ud.department_id = ? AND t.assigned_to != ? $task_status_condition
        ORDER BY t.deadline ASC"
    );
    $team_tasks_stmt->execute([$department['department_id'], $user_id]);
    $team_tasks = $team_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get team count
$team_count = count($team_members);

// Get unread notifications count
$notif_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
$notif_count_stmt->execute([$user_id]);
$unread_notif_count = $notif_count_stmt->fetchColumn();

// Get pending tasks count
$tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_stmt->execute([$user_id]);
$pending_tasks = $tasks_stmt->fetchColumn();

// Handle team member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // In the POST handler section for assign_task:
    if (isset($_POST['assign_task'])) {
        $assignee_id = $_POST['assignee_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $priority = $_POST['priority'];
        $deadline = $_POST['deadline'];

        try {
            // First verify the assignee is valid and in the same department
            $verify_stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM user_departments ud
     WHERE ud.user_id = ? AND ud.department_id = ?"
            );
            $verify_stmt->execute([$assignee_id, $department['department_id']]);
            $is_valid_assignee = $verify_stmt->fetchColumn();

            if (!$is_valid_assignee) {
                throw new Exception("Invalid team member selected");
            }

            $stmt = $pdo->prepare(
                "INSERT INTO tasks (title, description, assigned_to, created_by, status, priority, deadline, created_at, department_id)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW(), ?)"
            );
            $stmt->execute([
                $title,
                $description,
                $assignee_id,
                $user_id,
                $priority,
                $deadline,
                $department['department_id']
            ]);

            // Create notification for the assignee
            $notification_title = "New Task Assigned";
            $notification_message = "You have been assigned a new task: " . $title;
            $pdo->prepare(
                "INSERT INTO notifications (user_id, title, message, created_at)
            VALUES (?, ?, ?, NOW())"
            )->execute([$assignee_id, $notification_title, $notification_message]);

            $success = "Task assigned successfully";
        } catch (Exception $e) {
            $error = "Failed to assign task: " . $e->getMessage();
        }
    }

    if (isset($_POST['send_message'])) {
        // Send message to team member
        $receiver_id = $_POST['receiver_id'];
        $message_content = $_POST['message_content'];

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO messages (sender_id, receiver_id, content, sent_at, is_read)
                VALUES (?, ?, ?, NOW(), FALSE)"
            );
            $stmt->execute([$user_id, $receiver_id, $message_content]);

            $success = "Message sent successfully";
        } catch (PDOException $e) {
            $error = "Failed to send message: " . $e->getMessage();
        }
    }

    if (isset($_POST['update_task_status'])) {
        // Update task status
        $task_id = $_POST['task_id'];
        $new_status = $_POST['new_status'];

        try {
            $update_data = ['status' => $new_status];
            $update_sql = "UPDATE tasks SET status = :status";

            if ($new_status === 'completed') {
                $update_sql .= ", completed_at = NOW()";
            }

            $update_sql .= " WHERE task_id = :task_id";

            $stmt = $pdo->prepare($update_sql);
            $stmt->bindParam(':status', $new_status);
            $stmt->bindParam(':task_id', $task_id);
            $stmt->execute();

            $success = "Task status updated successfully";
        } catch (PDOException $e) {
            $error = "Failed to update task status: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | My Team</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background: rgba(15, 15, 26, 0.8);
            border-right: 1px solid rgba(224, 224, 255, 0.1);
            padding: 1.5rem;
        }

        .main-content {
            padding: 2rem;
            background: rgba(15, 15, 26, 0.5);
        }

        .widget {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .widget:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            border-color: var(--nebula-purple);
        }

        .widget-content {
            flex: 1;
            overflow-y: auto;
            padding-right: 8px;
            margin-bottom: 1rem;
        }

        .widget-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid rgba(224, 224, 255, 0.1);
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(108, 77, 246, 0.2);
        }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(108, 77, 246, 0.3), rgba(74, 144, 226, 0.3));
            border-left: 3px solid var(--cosmic-pink);
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        .task-badge {
            background: var(--cosmic-pink);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid rgba(224, 224, 255, 0.1);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            position: relative;
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s ease;
        }

        .tab:hover {
            color: white;
        }

        .tab.active {
            color: white;
            font-weight: 500;
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--cosmic-pink);
        }

        .tab-badge {
            margin-left: 0.5rem;
            background: rgba(224, 224, 255, 0.2);
            color: white;
            border-radius: 50px;
            padding: 0.15rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .team-member-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(224, 224, 255, 0.05);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .team-member-card:hover {
            background: rgba(108, 77, 246, 0.1);
            transform: translateY(-2px);
        }

        .team-member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.25rem;
        }

        .team-member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .team-member-info {
            flex: 1;
        }

        .team-member-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .team-member-role {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        .team-member-contact {
            font-size: 0.8rem;
            opacity: 0.7;
            display: flex;
            gap: 1rem;
        }

        .team-member-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .stat-item {
            font-size: 0.8rem;
        }

        .stat-value {
            font-weight: 600;
            color: var(--cosmic-pink);
        }

        .stat-label {
            font-size: 0.7rem;
            opacity: 0.7;
        }

        .team-member-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            background: rgba(224, 224, 255, 0.1);
            color: white;
        }

        .task-item {
            padding: 1rem;
            border-radius: 8px;
            background: rgba(224, 224, 255, 0.05);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            border-left: 3px solid var(--nebula-purple);
        }

        .task-item:hover {
            background: rgba(108, 77, 246, 0.1);
            transform: translateY(-2px);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .task-title {
            font-weight: 600;
            margin: 0;
        }

        .task-priority {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-critical {
            background: rgba(255, 77, 121, 0.2);
            color: var(--cosmic-pink);
        }

        .priority-high {
            background: rgba(255, 107, 157, 0.2);
            color: #FF6B9D;
        }

        .priority-medium {
            background: rgba(108, 77, 246, 0.2);
            color: var(--nebula-purple);
        }

        .priority-low {
            background: rgba(74, 144, 226, 0.2);
            color: var(--stellar-blue);
        }

        .task-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            opacity: 0.8;
            margin-bottom: 0.5rem;
        }

        .task-assignee {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .task-assignee-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .task-deadline {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .task-status {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(74, 144, 226, 0.2);
            color: var(--stellar-blue);
        }

        .status-in_progress {
            background: rgba(255, 193, 7, 0.2);
            color: #FFC107;
        }

        .status-completed {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
        }

        .task-description {
            font-size: 0.9rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: none;
            font-family: inherit;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(15, 15, 26, 0.95);
            border-radius: 12px;
            padding: 2rem;
            width: 600px;
            max-width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid var(--cosmic-pink);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(224, 224, 255, 0.1);
        }

        .modal-title {
            font-size: 1.5rem;
            margin: 0;
            color: var(--cosmic-pink);
        }

        .close-modal {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close-modal:hover {
            color: white;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            background: rgba(224, 224, 255, 0.05);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 8px;
            color: white;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--nebula-purple);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
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

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .team-member-card {
                flex-direction: column;
                text-align: center;
            }

            .team-member-actions {
                margin-top: 1rem;
                justify-content: center;
            }
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

        /* Add this to the existing style section */
        .form-group select option {
            background: rgba(15, 15, 26, 0.95);
            color: white;
            padding: 0.5rem;
        }

        /* Improve select dropdown appearance */
        .form-group select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3e%3cpath d='M7 10l5 5 5-5z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        /* Style for datetime input */
        input[type="datetime-local"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="stars"></div>
    <div class="dashboard-grid">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="logo" style="margin-bottom: 2rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="logo-icon">
                        <i class="fas fa-user-astronaut" style="font-size: 2rem; color: var(--nebula-purple);"></i>
                    </div>
                    <span class="logo-text"
                        style="font-size: 1.5rem; font-weight: 700; background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue)); -webkit-background-clip: text; background-clip: text; color: transparent;">TeamSphere</span>
                </div>
            </div>

            <div
                style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; padding: 0.5rem; border-radius: 8px; background: rgba(224, 224, 255, 0.05);">
                <div class="avatar">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?= htmlspecialchars($user['full_name']) ?></div>
                    <div style="font-size: 0.8rem; opacity: 0.7;"><?= htmlspecialchars($user['job_title']) ?></div>
                    <div style="font-size: 0.7rem; color: var(--cosmic-pink);">Manager</div>
                </div>
            </div>

            <nav>
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="tasks.php" class="nav-link">
                    <i class="fas fa-tasks"></i>
                    <span>Tasks</span>
                    <?php if ($pending_tasks > 0): ?>
                        <span class="task-badge"><?= $pending_tasks ?></span>
                    <?php endif; ?>
                </a>
                <a href="team.php" class="nav-link active">
                    <i class="fas fa-users"></i>
                    <span>My Team</span>
                    <span class="task-badge"><?= $team_count ?></span>
                </a>
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="../auth/auth.php?action=logout" class="nav-link" style="margin-top: 2rem;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h1 style="margin: 0;">My Team</h1>
                <div style="font-size: 0.9rem; opacity: 0.8;">
                    Department: <?= htmlspecialchars($department['name']) ?>
                </div>
            </div>

            <!-- Display success/error messages -->
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <div class="tabs">
                <div class="tab <?= $active_tab === 'members' ? 'active' : '' ?>"
                    onclick="window.location.href='team.php?tab=members'">
                    Team Members
                    <span class="tab-badge"><?= $team_count ?></span>
                </div>
                <div class="tab <?= $active_tab === 'tasks' ? 'active' : '' ?>"
                    onclick="window.location.href='team.php?tab=tasks'">
                    Pending Tasks
                    <span class="tab-badge">
                        <?= count(array_filter($team_members, fn($member) => $member['pending_tasks'] > 0)) ?>
                    </span>
                </div>
                <div class="tab <?= $active_tab === 'completed' ? 'active' : '' ?>"
                    onclick="window.location.href='team.php?tab=completed'">
                    Completed Tasks
                    <span class="tab-badge">
                        <?= count(array_filter($team_members, fn($member) => $member['completed_tasks'] > 0)) ?>
                    </span>
                </div>
                <div class="tab <?= $active_tab === 'overdue' ? 'active' : '' ?>"
                    onclick="window.location.href='team.php?tab=overdue'">
                    Overdue Tasks
                    <span class="tab-badge">
                        <?= count(array_filter($team_members, fn($member) => $member['overdue_tasks'] > 0)) ?>
                    </span>
                </div>
            </div>

            <!-- Team Members Tab Content -->
            <?php if ($active_tab === 'members'): ?>
                <div class="widget">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 style="margin: 0;">Team Members</h2>
                        <button class="btn btn-primary" id="assignTaskBtn">
                            <i class="fas fa-plus"></i> Assign Task
                        </button>
                    </div>

                    <div class="widget-content">
                        <?php if (count($team_members) > 0): ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($team_members as $member): ?>
                                    <div class="team-member-card">
                                        <div class="team-member-avatar">
                                            <div class="avatar">
                                                <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                                            </div>
                                        </div>
                                        <div class="team-member-info">
                                            <div class="team-member-name"><?= htmlspecialchars($member['full_name']) ?></div>
                                            <div class="team-member-role"><?= ucfirst($member['role']) ?></div>
                                            <div class="team-member-contact">
                                                <span><i class="fas fa-envelope"></i>
                                                    <?= htmlspecialchars($member['email']) ?></span>
                                                <span><i class="fas fa-phone"></i>
                                                    <?= htmlspecialchars($member['phone'] ?: 'N/A') ?></span>
                                            </div>
                                            <div class="team-member-stats">
                                                <div class="stat-item">
                                                    <span class="stat-value"><?= $member['pending_tasks'] ?></span>
                                                    <span class="stat-label">Pending</span>
                                                </div>
                                                <div class="stat-item">
                                                    <span class="stat-value"><?= $member['completed_tasks'] ?></span>
                                                    <span class="stat-label">Completed</span>
                                                </div>
                                                <div class="stat-item">
                                                    <span class="stat-value"><?= $member['overdue_tasks'] ?></span>
                                                    <span class="stat-label">Overdue</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="team-member-actions">
                                            <button class="action-btn" title="Send Message"
                                                onclick="openMessageModal(<?= $member['user_id'] ?>, '<?= htmlspecialchars($member['full_name']) ?>')">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                                No team members found in your department.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tasks Tab Content -->
            <?php if (in_array($active_tab, ['tasks', 'completed', 'overdue'])): ?>
                <div class="widget">
                    <h2 style="margin-bottom: 1.5rem;">
                        <?php
                        switch ($active_tab) {
                            case 'tasks':
                                echo 'Pending Tasks';
                                break;
                            case 'completed':
                                echo 'Completed Tasks';
                                break;
                            case 'overdue':
                                echo 'Overdue Tasks';
                                break;
                        }
                        ?>
                    </h2>

                    <div class="widget-content">
                        <?php if (count($team_tasks) > 0): ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($team_tasks as $task):
                                    // Calculate days remaining if not completed
                                    $days_remaining = '';
                                    $is_overdue = false;

                                    if ($task['status'] !== 'completed') {
                                        $now = new DateTime();
                                        $deadline = new DateTime($task['deadline']);
                                        $interval = $now->diff($deadline);
                                        $days_remaining = $interval->format('%a');

                                        if ($deadline < $now) {
                                            $is_overdue = true;
                                            $days_remaining = 'Overdue by ' . $days_remaining . ' days';
                                        } else {
                                            $days_remaining = $days_remaining . ' days remaining';
                                        }
                                    }
                                    ?>
                                    <div class="task-item">
                                        <div class="task-header">
                                            <h3 class="task-title"><?= htmlspecialchars($task['title']) ?></h3>
                                            <span class="task-priority priority-<?= $task['priority'] ?>">
                                                <?= htmlspecialchars($task['priority']) ?>
                                            </span>
                                        </div>
                                        <div class="task-meta">
                                            <div class="task-assignee">
                                                <div class="task-assignee-avatar">
                                                    <?= strtoupper(substr($task['assignee'], 0, 1)) ?>
                                                </div>
                                                <span><?= htmlspecialchars($task['assignee']) ?></span>
                                            </div>
                                            <div class="task-deadline">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span>
                                                    <?= date('M j, Y', strtotime($task['deadline'])) ?>
                                                    <?php if ($days_remaining): ?>
                                                        (<?= $days_remaining ?>)
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="task-status status-<?= $task['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="task-description">
                                                <?= nl2br(htmlspecialchars($task['description'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-actions">
                                            <?php if ($task['status'] !== 'completed'): ?>
                                                <button class="btn btn-sm btn-primary"
                                                    onclick="updateTaskStatus(<?= $task['task_id'] ?>, 'completed')">
                                                    <i class="fas fa-check"></i> Mark Complete
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($task['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-secondary"
                                                    onclick="updateTaskStatus(<?= $task['task_id'] ?>, 'in_progress')">
                                                    <i class="fas fa-spinner"></i> Start Progress
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                                No <?= $active_tab ?> tasks found for your team.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Assign Task Modal -->
    <div class="modal" id="assignTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Assign New Task</h2>
                <button class="close-modal" id="closeAssignTaskModal">&times;</button>
            </div>
            <form method="POST" id="assignTaskForm">
                <div class="form-group">
                    <label for="assignee_select">Assign To</label>
                    <select id="assignee_select" name="assignee_id" required>
                        <option value="">Select Team Member</option>
                        <?php foreach ($team_members as $member): ?>
                            <option value="<?= $member['user_id'] ?>">
                                <?= htmlspecialchars($member['full_name']) ?> (<?= ucfirst($member['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="title">Task Title</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required></textarea>
                </div>
                <div class="form-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="deadline">Deadline</label>
                    <input type="datetime-local" id="deadline" name="deadline" required>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelAssignTask">Cancel</button>
                    <button type="submit" name="assign_task" class="btn btn-primary">Assign Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Send Message Modal -->
    <div class="modal" id="messageModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Send Message</h2>
                <button class="close-modal" id="closeMessageModal">&times;</button>
            </div>
            <form method="POST" id="messageForm">
                <input type="hidden" name="receiver_id" id="receiverId">
                <div class="form-group">
                    <label for="message_content">Message</label>
                    <textarea id="message_content" name="message_content" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelMessage">Cancel</button>
                    <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        const assignTaskModal = document.getElementById('assignTaskModal');
        const messageModal = document.getElementById('messageModal');
        const assignTaskBtn = document.getElementById('assignTaskBtn');
        const closeAssignTaskModal = document.getElementById('closeAssignTaskModal');
        const closeMessageModal = document.getElementById('closeMessageModal');
        const cancelAssignTask = document.getElementById('cancelAssignTask');
        const cancelMessage = document.getElementById('cancelMessage');

        // Open assign task modal from button
        assignTaskBtn?.addEventListener('click', function () {
            assignTaskModal.style.display = 'flex';
        });

        // Open assign task modal for specific user
        function openAssignTaskModal(userId, userName) {
            document.getElementById('assigneeId').value = userId;
            document.querySelector('#assignTaskModal .modal-title').textContent = `Assign Task to ${userName}`;
            assignTaskModal.style.display = 'flex';
        }

        // Open message modal
        function openMessageModal(userId, userName) {
            document.getElementById('receiverId').value = userId;
            document.querySelector('#messageModal .modal-title').textContent = `Message to ${userName}`;
            messageModal.style.display = 'flex';
        }

        // Close modals
        function closeAllModals() {
            assignTaskModal.style.display = 'none';
            messageModal.style.display = 'none';
        }

        closeAssignTaskModal?.addEventListener('click', closeAllModals);
        closeMessageModal?.addEventListener('click', closeAllModals);
        cancelAssignTask?.addEventListener('click', closeAllModals);
        cancelMessage?.addEventListener('click', closeAllModals);

        // Close when clicking outside modal content
        window.addEventListener('click', function (event) {
            if (event.target === assignTaskModal || event.target === messageModal) {
                closeAllModals();
            }
        });

        // Set default deadline to tomorrow
        document.addEventListener('DOMContentLoaded', function () {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            const formattedDate = tomorrow.toISOString().slice(0, 16);
            document.getElementById('deadline').value = formattedDate;
        });

        // Update task status
        function updateTaskStatus(taskId, newStatus) {
            if (confirm('Are you sure you want to update this task status?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const taskIdInput = document.createElement('input');
                taskIdInput.type = 'hidden';
                taskIdInput.name = 'task_id';
                taskIdInput.value = taskId;
                form.appendChild(taskIdInput);

                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'new_status';
                statusInput.value = newStatus;
                form.appendChild(statusInput);

                const updateInput = document.createElement('input');
                updateInput.type = 'hidden';
                updateInput.name = 'update_task_status';
                updateInput.value = '1';
                form.appendChild(updateInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Form validation for assign task
        document.getElementById('assignTaskForm')?.addEventListener('submit', function (e) {
            const deadline = new Date(document.getElementById('deadline').value);
            const now = new Date();

            if (deadline <= now) {
                e.preventDefault();
                alert('Deadline must be in the future');
                return false;
            }

            return true;
        });

        // Form validation for message
        document.getElementById('messageForm')?.addEventListener('submit', function (e) {
            const message = document.getElementById('message_content').value.trim();

            if (message.length < 5) {
                e.preventDefault();
                alert('Message must be at least 5 characters long');
                return false;
            }

            return true;
        });
    </script>
</body>

</html>