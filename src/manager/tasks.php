<?php
// Manager Tasks - Complete Standalone Version
// File: src/manager/tasks.php

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

// Get active tab from URL or default to 'my_tasks'
$active_tab = $_GET['tab'] ?? 'my_tasks';

// Get filter values
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Initialize variables
$tasks = [];
$team_tasks = [];
$error = '';
$success = '';

// Get team members count
$team_count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM user_departments 
    WHERE department_id = ? AND user_id != ?"
);
$team_count_stmt->execute([$department['department_id'], $user_id]);
$team_count = $team_count_stmt->fetchColumn();

// Get unread notifications count
$notif_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
$notif_count_stmt->execute([$user_id]);
$unread_notif_count = $notif_count_stmt->fetchColumn();

// Get pending tasks count
$tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_stmt->execute([$user_id]);
$pending_tasks = $tasks_stmt->fetchColumn();

// Handle task actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_task_status'])) {
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

            // Create notification if task was completed
            if ($new_status === 'completed') {
                $task_stmt = $pdo->prepare("SELECT title, assigned_to, created_by FROM tasks WHERE task_id = ?");
                $task_stmt->execute([$task_id]);
                $task = $task_stmt->fetch(PDO::FETCH_ASSOC);

                if ($task) {
                    $notification_title = "Task Completed";
                    $notification_message = "Task '{$task['title']}' has been marked as completed";

                    // Notify task creator
                    if ($task['created_by'] != $user_id) {
                        $pdo->prepare(
                            "INSERT INTO notifications (user_id, title, message, created_at) 
                            VALUES (?, ?, ?, NOW())"
                        )->execute([$task['created_by'], $notification_title, $notification_message]);
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Failed to update task status: " . $e->getMessage();
        }
    }

    if (isset($_POST['add_task'])) {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $priority = $_POST['priority'];
        $deadline = $_POST['deadline'];

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO tasks (title, description, assigned_to, created_by, status, priority, deadline, created_at, department_id)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW(), ?)"
            );
            $stmt->execute([
                $title,
                $description,
                $user_id,
                $user_id,
                $priority,
                $deadline,
                $department['department_id']
            ]);

            $success = "Task added successfully";
        } catch (PDOException $e) {
            $error = "Failed to add task: " . $e->getMessage();
        }
    }

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

    if (isset($_POST['delete_task'])) {
        $task_id = $_POST['task_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE task_id = ? AND (assigned_to = ? OR created_by = ?)");
            $stmt->execute([$task_id, $user_id, $user_id]);

            if ($stmt->rowCount() > 0) {
                $success = "Task deleted successfully";
            } else {
                $error = "Task not found or you don't have permission to delete it";
            }
        } catch (PDOException $e) {
            $error = "Failed to delete task: " . $e->getMessage();
        }
    }
}

// Get tasks based on active tab and filters
if ($active_tab === 'my_tasks') {
    $sql = "SELECT t.*, u.full_name as assignee_name 
            FROM tasks t
            JOIN users u ON t.assigned_to = u.user_id
            WHERE t.assigned_to = ?";

    $params = [$user_id];

    // Apply status filter
    if ($status_filter !== 'all') {
        $sql .= " AND t.status = ?";
        $params[] = $status_filter;
    }

    // Apply priority filter
    if ($priority_filter !== 'all') {
        $sql .= " AND t.priority = ?";
        $params[] = $priority_filter;
    }

    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $sql .= " ORDER BY 
                CASE 
                    WHEN t.status = 'completed' THEN 3
                    WHEN t.deadline < NOW() AND t.status != 'completed' THEN 0
                    ELSE 1
                END,
                t.deadline ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($active_tab === 'team_tasks') {
    $sql = "SELECT t.*, u.full_name as assignee_name 
            FROM tasks t
            JOIN users u ON t.assigned_to = u.user_id
            JOIN user_departments ud ON t.assigned_to = ud.user_id
            WHERE ud.department_id = ? AND t.assigned_to != ?";

    $params = [$department['department_id'], $user_id];

    // Apply status filter
    if ($status_filter !== 'all') {
        $sql .= " AND t.status = ?";
        $params[] = $status_filter;
    }

    // Apply priority filter
    if ($priority_filter !== 'all') {
        $sql .= " AND t.priority = ?";
        $params[] = $priority_filter;
    }

    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $sql .= " ORDER BY 
                CASE 
                    WHEN t.status = 'completed' THEN 3
                    WHEN t.deadline < NOW() AND t.status != 'completed' THEN 0
                    ELSE 1
                END,
                t.deadline ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $team_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get team members for task assignment
$team_members_stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name 
     FROM users u
     JOIN user_departments ud ON u.user_id = ud.user_id
     WHERE ud.department_id = ? AND u.user_id != ?
     ORDER BY u.full_name ASC"
);
$team_members_stmt->execute([$department['department_id'], $user_id]);
$team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get task details if viewing a single task
$task_details = null;
if (isset($_GET['task_id'])) {
    $task_id = $_GET['task_id'];
    $stmt = $pdo->prepare(
        "SELECT t.*, u1.full_name as assignee_name, u2.full_name as creator_name 
         FROM tasks t
         JOIN users u1 ON t.assigned_to = u1.user_id
         JOIN users u2 ON t.created_by = u2.user_id
         WHERE t.task_id = ? AND (t.assigned_to = ? OR t.created_by = ? OR 
               (t.department_id = ? AND ? IN (SELECT user_id FROM user_departments WHERE department_id = t.department_id AND is_primary = 1)))"
    );
    $stmt->execute([$task_id, $user_id, $user_id, $department['department_id'], $user_id]);
    $task_details = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Tasks</title>
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

        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .filter-select {
            padding: 0.5rem;
            background: rgba(224, 224, 255, 0.05);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 6px;
            color: white;
            font-family: inherit;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--nebula-purple);
        }

        .search-input {
            padding: 0.5rem 1rem;
            background: rgba(224, 224, 255, 0.05);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 6px;
            color: white;
            font-family: inherit;
            min-width: 250px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--nebula-purple);
        }

        .search-button {
            padding: 0.5rem 1rem;
            background: var(--nebula-purple);
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .search-button:hover {
            background: var(--cosmic-pink);
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
            font-size: 1.1rem;
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
            flex-wrap: wrap;
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
            line-height: 1.6;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            flex-wrap: wrap;
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
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .btn-danger {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
        }

        .btn-success {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .task-detail-view {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .task-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(224, 224, 255, 0.1);
        }

        .task-detail-title {
            font-size: 1.5rem;
            margin: 0;
            color: var(--cosmic-pink);
        }

        .task-detail-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .task-detail-body {
            line-height: 1.6;
        }

        .task-detail-description {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(224, 224, 255, 0.05);
            border-radius: 8px;
        }

        .task-detail-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .no-tasks {
            padding: 2rem;
            text-align: center;
            opacity: 0.7;
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

            .filters {
                flex-direction: column;
                gap: 0.5rem;
            }

            .filter-group {
                width: 100%;
            }

            .filter-select,
            .search-input {
                width: 100%;
            }
        }

        /* Add this to the existing style section */
        .form-group select option {
            background: rgba(15, 15, 26, 0.95);
            color: white;
            padding: 0.5rem;
        }

        .filter-group select option {
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
                <a href="tasks.php" class="nav-link active">
                    <i class="fas fa-tasks"></i>
                    <span>Tasks</span>
                    <?php if ($pending_tasks > 0): ?>
                        <span class="task-badge"><?= $pending_tasks ?></span>
                    <?php endif; ?>
                </a>
                <a href="team.php" class="nav-link">
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
                <h1 style="margin: 0;">Tasks Management</h1>
                <div style="font-size: 0.9rem; opacity: 0.8;">
                    Department: <?= htmlspecialchars($department['name']) ?>
                </div>
            </div>

            <!-- Display success/error messages -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <!-- Task Detail View -->
            <?php if ($task_details): ?>
                <div class="task-detail-view">
                    <div class="task-detail-header">
                        <h2 class="task-detail-title"><?= htmlspecialchars($task_details['title']) ?></h2>
                        <div>
                            <span class="task-priority priority-<?= $task_details['priority'] ?>">
                                <?= ucfirst($task_details['priority']) ?>
                            </span>
                            <span class="task-status status-<?= $task_details['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $task_details['status'])) ?>
                            </span>
                        </div>
                    </div>

                    <div class="task-detail-meta">
                        <div>
                            <strong>Assigned To:</strong> <?= htmlspecialchars($task_details['assignee_name']) ?>
                        </div>
                        <div>
                            <strong>Created By:</strong> <?= htmlspecialchars($task_details['creator_name']) ?>
                        </div>
                        <div>
                            <strong>Created At:</strong> <?= date('M j, Y g:i A', strtotime($task_details['created_at'])) ?>
                        </div>
                        <div>
                            <strong>Deadline:</strong>
                            <?= $task_details['deadline'] ? date('M j, Y g:i A', strtotime($task_details['deadline'])) : 'No deadline' ?>
                        </div>
                        <?php if ($task_details['status'] === 'completed' && $task_details['completed_at']): ?>
                            <div>
                                <strong>Completed At:</strong>
                                <?= date('M j, Y g:i A', strtotime($task_details['completed_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="task-detail-body">
                        <h3 style="margin-top: 0; margin-bottom: 0.5rem;">Description</h3>
                        <div class="task-detail-description">
                            <?= $task_details['description'] ? nl2br(htmlspecialchars($task_details['description'])) : 'No description provided' ?>
                        </div>
                    </div>

                    <div class="task-detail-actions">
                        <?php if ($task_details['status'] !== 'completed'): ?>
                            <button class="btn btn-success"
                                onclick="updateTaskStatus(<?= $task_details['task_id'] ?>, 'completed')">
                                <i class="fas fa-check"></i> Mark Complete
                            </button>
                        <?php endif; ?>
                        <?php if ($task_details['status'] === 'pending'): ?>
                            <button class="btn btn-secondary"
                                onclick="updateTaskStatus(<?= $task_details['task_id'] ?>, 'in_progress')">
                                <i class="fas fa-spinner"></i> Start Progress
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-danger" onclick="deleteTask(<?= $task_details['task_id'] ?>)">
                            <i class="fas fa-trash"></i> Delete Task
                        </button>
                        <a href="tasks.php?tab=<?= $active_tab ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Tabs Navigation -->
                <div class="tabs">
                    <div class="tab <?= $active_tab === 'my_tasks' ? 'active' : '' ?>"
                        onclick="window.location.href='tasks.php?tab=my_tasks'">
                        My Tasks
                        <span class="tab-badge">
                            <?= count(array_filter($tasks, fn($task) => $task['status'] !== 'completed')) ?>
                        </span>
                    </div>
                    <div class="tab <?= $active_tab === 'team_tasks' ? 'active' : '' ?>"
                        onclick="window.location.href='tasks.php?tab=team_tasks'">
                        Team Tasks
                        <span class="tab-badge">
                            <?= count(array_filter($team_tasks, fn($task) => $task['status'] !== 'completed')) ?>
                        </span>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" class="filters">
                    <input type="hidden" name="tab" value="<?= $active_tab ?>">

                    <div class="filter-group">
                        <span class="filter-label">Status:</span>
                        <select name="status" class="filter-select " onchange="this.form.submit()">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress
                            </option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed
                            </option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Priority:</span>
                        <select name="priority" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?= $priority_filter === 'all' ? 'selected' : '' ?>>All Priorities</option>
                            <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="critical" <?= $priority_filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                        </select>
                    </div>

                    <div class="filter-group" style="flex: 1;">
                        <input type="text" name="search" class="search-input" placeholder="Search tasks..."
                            value="<?= htmlspecialchars($search_query) ?>">
                        <button type="submit" class="search-button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>

                <!-- Action Buttons -->
                <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-bottom: 1.5rem;">
                    <?php if ($active_tab === 'my_tasks'): ?>
                        <button class="btn btn-primary" id="addTaskBtn">
                            <i class="fas fa-plus"></i> Add Task
                        </button>
                    <?php else: ?>
                        <button class="btn btn-primary" id="assignTaskBtn">
                            <i class="fas fa-plus"></i> Assign Task
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Tasks List -->
                <div class="widget">
                    <div class="widget-content">
                        <?php
                        $current_tasks = $active_tab === 'my_tasks' ? $tasks : $team_tasks;
                        if (count($current_tasks) > 0): ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($current_tasks as $task):
                                    // Calculate days remaining if not completed
                                    $days_remaining = '';
                                    $is_overdue = false;

                                    if ($task['status'] !== 'completed' && $task['deadline']) {
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
                                            <h3 class="task-title">
                                                <a href="tasks.php?tab=<?= $active_tab ?>&task_id=<?= $task['task_id'] ?>">
                                                    <?= htmlspecialchars($task['title']) ?>
                                                </a>
                                            </h3>
                                            <span class="task-priority priority-<?= $task['priority'] ?>">
                                                <?= ucfirst($task['priority']) ?>
                                            </span>
                                        </div>
                                        <div class="task-meta">
                                            <div class="task-assignee">
                                                <div class="task-assignee-avatar">
                                                    <?= strtoupper(substr($task['assignee_name'], 0, 1)) ?>
                                                </div>
                                                <span><?= htmlspecialchars($task['assignee_name']) ?></span>
                                            </div>
                                            <?php if ($task['deadline']): ?>
                                                <div class="task-deadline">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span>
                                                        <?= date('M j, Y', strtotime($task['deadline'])) ?>
                                                        <?php if ($days_remaining): ?>
                                                            (<?= $days_remaining ?>)
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="task-status status-<?= $task['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="task-description">
                                                <?= nl2br(htmlspecialchars(substr($task['description'], 0, 150))) ?>...
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-actions">
                                            <?php if ($task['status'] !== 'completed'): ?>
                                                <button class="btn btn-sm btn-success"
                                                    onclick="updateTaskStatus(<?= $task['task_id'] ?>, 'completed')">
                                                    <i class="fas fa-check"></i> Complete
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($task['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-secondary"
                                                    onclick="updateTaskStatus(<?= $task['task_id'] ?>, 'in_progress')">
                                                    <i class="fas fa-spinner"></i> Start
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="deleteTask(<?= $task['task_id'] ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                            <a href="tasks.php?tab=<?= $active_tab ?>&task_id=<?= $task['task_id'] ?>"
                                                class="btn btn-sm btn-secondary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-tasks">
                                No tasks found matching your criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div class="modal" id="addTaskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add New Task</h2>
                <button class="close-modal" id="closeAddTaskModal">&times;</button>
            </div>
            <form method="POST" id="addTaskForm">
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
                    <button type="button" class="btn btn-secondary" id="cancelAddTask">Cancel</button>
                    <button type="submit" name="add_task" class="btn btn-primary">Add Task</button>
                </div>
            </form>
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
                                <?= htmlspecialchars($member['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assign_title">Task Title</label>
                    <input type="text" id="assign_title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="assign_description">Description</label>
                    <textarea id="assign_description" name="description" required></textarea>
                </div>
                <div class="form-group">
                    <label for="assign_priority">Priority</label>
                    <select id="assign_priority" name="priority" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assign_deadline">Deadline</label>
                    <input type="datetime-local" id="assign_deadline" name="deadline" required>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelAssignTask">Cancel</button>
                    <button type="submit" name="assign_task" class="btn btn-primary">Assign Task</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        const addTaskModal = document.getElementById('addTaskModal');
        const assignTaskModal = document.getElementById('assignTaskModal');
        const addTaskBtn = document.getElementById('addTaskBtn');
        const assignTaskBtn = document.getElementById('assignTaskBtn');
        const closeAddTaskModal = document.getElementById('closeAddTaskModal');
        const closeAssignTaskModal = document.getElementById('closeAssignTaskModal');
        const cancelAddTask = document.getElementById('cancelAddTask');
        const cancelAssignTask = document.getElementById('cancelAssignTask');

        // Open modals from buttons
        addTaskBtn?.addEventListener('click', function () {
            addTaskModal.style.display = 'flex';
        });

        assignTaskBtn?.addEventListener('click', function () {
            assignTaskModal.style.display = 'flex';
        });

        // Close modals
        function closeAllModals() {
            addTaskModal.style.display = 'none';
            assignTaskModal.style.display = 'none';
        }

        closeAddTaskModal?.addEventListener('click', closeAllModals);
        closeAssignTaskModal?.addEventListener('click', closeAllModals);
        cancelAddTask?.addEventListener('click', closeAllModals);
        cancelAssignTask?.addEventListener('click', closeAllModals);

        // Close when clicking outside modal content
        window.addEventListener('click', function (event) {
            if (event.target === addTaskModal || event.target === assignTaskModal) {
                closeAllModals();
            }
        });

        // Set default deadline to tomorrow
        document.addEventListener('DOMContentLoaded', function () {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            const formattedDate = tomorrow.toISOString().slice(0, 16);

            document.getElementById('deadline').value = formattedDate;
            document.getElementById('assign_deadline').value = formattedDate;
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

        // Delete task
        function deleteTask(taskId) {
            if (confirm('Are you sure you want to delete this task? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const taskIdInput = document.createElement('input');
                taskIdInput.type = 'hidden';
                taskIdInput.name = 'task_id';
                taskIdInput.value = taskId;
                form.appendChild(taskIdInput);

                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_task';
                deleteInput.value = '1';
                form.appendChild(deleteInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Form validation for add task
        document.getElementById('addTaskForm')?.addEventListener('submit', function (e) {
            const deadline = new Date(document.getElementById('deadline').value);
            const now = new Date();

            if (deadline <= now) {
                e.preventDefault();
                alert('Deadline must be in the future');
                return false;
            }

            return true;
        });

        // Form validation for assign task
        document.getElementById('assignTaskForm')?.addEventListener('submit', function (e) {
            const deadline = new Date(document.getElementById('assign_deadline').value);
            const now = new Date();

            if (deadline <= now) {
                e.preventDefault();
                alert('Deadline must be in the future');
                return false;
            }

            return true;
        });
    </script>
</body>

</html>