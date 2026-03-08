<?php
// Manager Dashboard - Complete Standalone Version
// File: src/manager/dashboard.php

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

// Get recent notifications
$notif_stmt = $pdo->prepare(
    "SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC LIMIT 5"
);
$notif_stmt->execute([$user_id]);
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tasks count (personal)
$tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_stmt->execute([$user_id]);
$pending_tasks = $tasks_stmt->fetchColumn();

// Get team tasks count
$team_tasks_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tasks t
    JOIN user_departments ud ON t.assigned_to = ud.user_id
    WHERE ud.department_id = ? AND t.status != 'completed' AND t.assigned_to != ?"
);
$team_tasks_stmt->execute([$department['department_id'], $user_id]);
$team_pending_tasks = $team_tasks_stmt->fetchColumn();

// Get completed tasks count (personal)
$completed_tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed'");
$completed_tasks_stmt->execute([$user_id]);
$completed_tasks = $completed_tasks_stmt->fetchColumn();

// Get team completed tasks count
$team_completed_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tasks t
    JOIN user_departments ud ON t.assigned_to = ud.user_id
    WHERE ud.department_id = ? AND t.status = 'completed' AND t.assigned_to != ?"
);
$team_completed_stmt->execute([$department['department_id'], $user_id]);
$team_completed_tasks = $team_completed_stmt->fetchColumn();

// Get overdue tasks count (personal)
$overdue_tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND deadline < NOW() AND status != 'completed'");
$overdue_tasks_stmt->execute([$user_id]);
$overdue_tasks = $overdue_tasks_stmt->fetchColumn();

// Get team overdue tasks count
$team_overdue_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tasks t
    JOIN user_departments ud ON t.assigned_to = ud.user_id
    WHERE ud.department_id = ? AND t.deadline < NOW() AND t.status != 'completed' AND t.assigned_to != ?"
);
$team_overdue_stmt->execute([$department['department_id'], $user_id]);
$team_overdue_tasks = $team_overdue_stmt->fetchColumn();

// Get task status breakdown for chart (team)
$task_chart_stmt = $pdo->prepare(
    "SELECT status, COUNT(*) as count 
    FROM tasks t
    JOIN user_departments ud ON t.assigned_to = ud.user_id
    WHERE ud.department_id = ?
    GROUP BY status"
);
$task_chart_stmt->execute([$department['department_id']]);
$task_data = $task_chart_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for chart
$chart_labels = [];
$chart_values = [];
$chart_colors = ['#6C4DF6', '#4A90E2', '#FF4D79', '#FFC107', '#4CAF50'];
foreach ($task_data as $index => $data) {
    $chart_labels[] = ucfirst(str_replace('_', ' ', $data['status']));
    $chart_values[] = $data['count'];
}

// Get recent announcements
$announcements_stmt = $pdo->prepare(
    "SELECT a.*, u.full_name as author_name FROM announcements a 
    LEFT JOIN users u ON a.created_by = u.user_id
    LEFT JOIN user_departments ud ON a.department_id = ud.department_id
    WHERE (a.is_global = 1 OR ud.department_id = ?)
    ORDER BY a.created_at DESC LIMIT 3"
);
$announcements_stmt->execute([$department['department_id']]);
$announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity (team)
$activity_stmt = $pdo->prepare(
    "SELECT a.*, u.full_name FROM activity_log a
    JOIN users u ON a.user_id = u.user_id
    JOIN user_departments ud ON u.user_id = ud.user_id
    WHERE ud.department_id = ?
    ORDER BY a.timestamp DESC LIMIT 5"
);
$activity_stmt->execute([$department['department_id']]);
$activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming events (team)
$events_stmt = $pdo->prepare(
    "SELECT e.* FROM events e
    JOIN event_attendees ea ON e.event_id = ea.event_id
    JOIN user_departments ud ON ea.user_id = ud.user_id
    WHERE ud.department_id = ? AND e.start_time > NOW()
    GROUP BY e.event_id
    ORDER BY e.start_time ASC LIMIT 3"
);
$events_stmt->execute([$department['department_id']]);
$upcoming_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming deadlines (team tasks due in the next 14 days)
$deadlines_stmt = $pdo->prepare(
    "SELECT t.task_id, t.title, t.deadline, t.status, t.priority, u.full_name as assignee 
    FROM tasks t
    JOIN user_departments ud ON t.assigned_to = ud.user_id
    JOIN users u ON t.assigned_to = u.user_id
    WHERE ud.department_id = ? 
    AND t.deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
    AND t.status != 'completed'
    ORDER BY t.deadline ASC
    LIMIT 5"
);
$deadlines_stmt->execute([$department['department_id']]);
$upcoming_deadlines = $deadlines_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread messages count
$messages_count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM messages 
    WHERE (receiver_id = ? OR (group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))) 
    AND is_read = FALSE AND sender_id != ?"
);
$messages_count_stmt->execute([$user_id, $user_id, $user_id]);
$unread_messages_count = $messages_count_stmt->fetchColumn();

// Get recent messages
$messages_stmt = $pdo->prepare(
    "SELECT m.*, u.full_name as sender_name, u.avatar as sender_avatar 
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.receiver_id = ? OR (m.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))) 
    AND m.sender_id != ?
    ORDER BY m.sent_at DESC LIMIT 5"
);
$messages_stmt->execute([$user_id, $user_id, $user_id]);
$messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_notifications_read']) && $_POST['mark_notifications_read'] == 'true') {
        $mark_read = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $mark_read->execute([$user_id]);
        exit;
    }

    // Add this to the existing POST handlers in dashboard.php
    if (isset($_POST['get_unread_count'])) {
        $count_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM messages 
        WHERE (receiver_id = ? OR (group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))) 
        AND is_read = FALSE AND sender_id != ?"
        );
        $count_stmt->execute([$user_id, $user_id, $user_id]);
        $unread_count = $count_stmt->fetchColumn();

        header('Content-Type: application/json');
        echo json_encode(['unread_messages' => $unread_count]);
        exit;
    }

    // This should already exist in your POST handlers, but verify it looks like this:
    if (isset($_POST['mark_message_read']) && isset($_POST['message_id'])) {
        $mark_read = $pdo->prepare("UPDATE messages SET is_read = TRUE WHERE message_id = ? AND (receiver_id = ? OR (group_id IN (SELECT group_id FROM group_members WHERE user_id = ?)))");
        $mark_read->execute([$_POST['message_id'], $user_id, $user_id]);

        // Return success response
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    if (isset($_POST['get_announcement']) && isset($_POST['announcement_id'])) {
        $stmt = $pdo->prepare(
            "SELECT a.*, u.full_name as author_name, d.name as department_name 
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.user_id
            LEFT JOIN departments d ON a.department_id = d.department_id
            WHERE a.announcement_id = ?"
        );
        $stmt->execute([$_POST['announcement_id']]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($announcement) {
            header('Content-Type: application/json');
            echo json_encode($announcement);
            exit;
        }
    }

    if (isset($_POST['get_all_notifications'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC"
        );
        $stmt->execute([$user_id]);
        $all_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($all_notifications);
        exit;
    }

    if (isset($_POST['get_all_messages'])) {
        $stmt = $pdo->prepare(
            "SELECT m.*, u.full_name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE (m.receiver_id = ? OR (m.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))) 
            AND m.sender_id != ?
            ORDER BY m.sent_at DESC"
        );
        $stmt->execute([$user_id, $user_id, $user_id]);
        $all_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($all_messages);
        exit;
    }

    if (isset($_POST['send_reply'])) {
        // Handle message replies
        $original_stmt = $pdo->prepare(
            "SELECT sender_id, receiver_id, group_id FROM messages WHERE message_id = ?"
        );
        $original_stmt->execute([$_POST['message_id']]);
        $original = $original_stmt->fetch(PDO::FETCH_ASSOC);

        if ($original) {
            $insert = $pdo->prepare(
                "INSERT INTO messages (sender_id, receiver_id, group_id, content, is_group_message, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $insert->execute([
                $user_id,
                $original['group_id'] ? null : $original['sender_id'],
                $original['group_id'],
                $_POST['content'],
                (bool) $original['group_id']
            ]);
            echo json_encode(['status' => 'success']);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Manager Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js for data visualization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            height: 400px;
            /* Fixed height for all widgets */
            display: flex;
            flex-direction: column;
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
            /* Prevents content from touching scrollbar */
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

        .two-column-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .three-column-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        .notification-dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: rgba(15, 15, 26, 0.9);
            min-width: 300px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 10;
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .dropdown-content.show {
            display: block;
        }

        .dropdown-content .notification-item {
            padding: 0.75rem;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            transition: background 0.2s ease;
        }

        .dropdown-content .notification-item.unread {
            background: rgba(108, 77, 246, 0.15);
            border-left: 2px solid var(--nebula-purple);
        }

        .dropdown-content .notification-item.read {
            background: rgba(224, 224, 255, 0.05);
        }

        .dropdown-content .notification-item:hover {
            background: rgba(108, 77, 246, 0.25);
            border-color: var(--cosmic-pink);
        }

        .dropdown-content h3 {
            color: rgba(255, 255, 255, 0.9);
            margin-top: 0;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dropdown-content .notification-title {
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
        }

        .dropdown-content .notification-message {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .dropdown-content .notification-time {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.6);
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

        .modal-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.42);
        }

        .modal-body {
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.9);
        }

        .stats-grid {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 10px;
            /* Space for scrollbar */
            scrollbar-width: thin;
            /* For Firefox */
        }

        .stats-grid::-webkit-scrollbar {
            height: 8px;
        }

        .stats-grid::-webkit-scrollbar-track {
            background: rgba(15, 15, 26, 0.3);
        }

        .stats-grid::-webkit-scrollbar-thumb {
            background: rgba(108, 77, 246, 0.5);
            border-radius: 4px;
        }

        .stats-grid::-webkit-scrollbar-thumb:hover {
            background: rgba(108, 77, 246, 0.7);
        }

        .stat-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            flex-direction: column;
            min-width: 180px;
            /* Minimum width for each card */
            flex-shrink: 0;
            /* Prevent cards from shrinking */
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .stat-card.warning .stat-value {
            background: linear-gradient(135deg, #FF4D79, #FF9500);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .stat-card.success .stat-value {
            background: linear-gradient(135deg, #47B881, #4CAF50);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .deadline-item {
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            background: rgba(224, 224, 255, 0.05);
            border-radius: 8px;
            transition: all 0.2s ease;
            border-left: 3px solid var(--nebula-purple);
        }

        .deadline-item:hover {
            background: rgba(108, 77, 246, 0.1);
            transform: translateX(5px);
        }

        .deadline-item.critical {
            border-left-color: var(--cosmic-pink);
        }

        .deadline-item.high {
            border-left-color: #FF6B9D;
        }

        .deadline-item.medium {
            border-left-color: var(--nebula-purple);
        }

        .deadline-item.low {
            border-left-color: var(--stellar-blue);
        }

        .deadline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .deadline-title {
            font-weight: 500;
            font-size: medium;
            margin: 0;
        }

        .deadline-meta {
            display: flex;
            gap: 0.5rem;
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .deadline-days {
            font-weight: 600;
            color: var(--cosmic-pink);
        }

        .deadline-priority {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
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

        .message-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: background 0.2s;
        }

        .message-item.unread {
            background: rgba(74, 144, 226, 0.15);
        }

        .message-item:hover {
            background: rgba(108, 77, 246, 0.15);
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .message-content {
            flex: 1;
        }

        .message-sender {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .message-text {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.6;
        }

        .message-actions {
            display: flex;
            gap: 0.5rem;
        }

        .message-action {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            font-size: 0.8rem;
            transition: color 0.2s;
        }

        .message-action:hover {
            color: white;
        }

        .team-member {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: rgba(224, 224, 255, 0.05);
            transition: all 0.2s;
        }

        .team-member:hover {
            background: rgba(108, 77, 246, 0.1);
        }

        .team-member-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .team-member-info {
            flex: 1;
        }

        .team-member-name {
            font-weight: 500;
            margin-bottom: 0.1rem;
        }

        .team-member-role {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        .team-member-tasks {
            font-size: 0.8rem;
            color: var(--cosmic-pink);
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .two-column-section,
            .three-column-section {
                grid-template-columns: 1fr;
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

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .form-group textarea {
            background: rgba(15, 15, 26, 0.7);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 6px;
            padding: 0.75rem;
            color: white;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: var(--nebula-purple);
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
                <a href="#" class="nav-link active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="tasks.php" class="nav-link">
                    <i class="fas fa-tasks"></i>
                    <span>Tasks</span>
                    <?php if ($pending_tasks > 0): ?>
                        <span class="task-badge" style="margin-left: auto;"><?= $pending_tasks ?></span>
                    <?php endif; ?>
                </a>
                <a href="team.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>My Team</span>
                    <span class="task-badge" style="margin-left: auto;"><?= $team_count ?></span>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h1 style="margin: 0;">Welcome Back, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>!</h1>
                <div style="display: flex; gap: 1rem;">
                    <div class="notification-dropdown">
                        <button class="btn btn-secondary" style="padding: 0.5rem 1rem;" id="notificationsBtn">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_notif_count > 0): ?>
                                <span class="task-badge"
                                    style="position: absolute; top: -5px; right: -5px;"><?= $unread_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-content" id="notificationsDropdown">
                            <h3>Notifications
                                <span style="font-size: 0.8rem; font-weight: normal;">
                                    <?php if ($unread_notif_count > 0): ?>
                                        <a href="#" id="markAllRead" style="color: var(--nebula-purple);">Mark all as
                                            read</a>
                                    <?php endif; ?>
                                </span>
                            </h3>
                            <?php if (count($notifications) > 0): ?>
                                <div style="display: grid; gap: 0.5rem;">
                                    <?php foreach ($notifications as $notif): ?>
                                        <div class="notification-item <?= $notif['is_read'] ? 'read' : 'unread' ?>">
                                            <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                            <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                                            <div class="notification-time">
                                                <?= date('M j, g:i A', strtotime($notif['created_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="margin-top: 0.5rem;">
                                    <a href="#" id="viewAllNotifications"
                                        style="font-size: 0.8rem; color: var(--nebula-purple);">View all notifications</a>
                                </div>
                            <?php else: ?>
                                <div style="padding: 1rem; text-align: center; opacity: 0.7;">
                                    No new notifications
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="notification-dropdown">
                        <button class="btn btn-secondary" style="padding: 0.5rem 1rem;" id="messagesBtn">
                            <i class="fas fa-envelope"></i>
                            <?php if ($unread_messages_count > 0): ?>
                                <span class="task-badge"
                                    style="position: absolute; top: -5px; right: -5px;"><?= $unread_messages_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-content" id="messagesDropdown">
                            <h3>Messages</h3>
                            <?php if (count($messages) > 0): ?>
                                <div style="display: grid; gap: 0.5rem; max-height: 300px; overflow-y: auto;">
                                    <?php foreach ($messages as $message): ?>
                                        <div class="message-item <?= $message['is_read'] ? '' : 'unread' ?>"
                                            data-message-id="<?= $message['message_id'] ?>">
                                            <div class="message-avatar">
                                                <?= strtoupper(substr($message['sender_name'], 0, 1)) ?>
                                            </div>
                                            <div class="message-content">
                                                <div class="message-sender"><?= htmlspecialchars($message['sender_name']) ?>
                                                </div>
                                                <div class="message-text">
                                                    <?= htmlspecialchars(substr($message['content'], 0, 50)) ?>...
                                                </div>
                                                <div class="message-time">
                                                    <?= date('M j, g:i A', strtotime($message['sent_at'])) ?>
                                                </div>
                                            </div>
                                            <div class="message-actions">
                                                <button class="message-action" title="Reply"><i
                                                        class="fas fa-reply"></i></button>
                                                <button class="message-action mark-read-btn" title="Mark as read"><i
                                                        class="fas fa-check"></i></button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="margin-top: 0.5rem;">
                                    <a href="#" id="viewAllMessagesBtn"
                                        style="font-size: 0.8rem; color: var(--nebula-purple);">View all messages</a>
                                </div>
                            <?php else: ?>
                                <div style="padding: 1rem; text-align: center; opacity: 0.7;">
                                    No new messages
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <!-- Personal Tasks -->
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                    <div class="stat-value"><?= $pending_tasks ?></div>
                    <div class="stat-label">Your Pending Tasks</div>
                    <a href="tasks.php" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <!-- Team Tasks -->
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?= $team_pending_tasks ?></div>
                    <div class="stat-label">Team Pending Tasks</div>
                    <a href="team.php?tab=tasks" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <!-- Team Members -->
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
                    <div class="stat-value"><?= $team_count ?></div>
                    <div class="stat-label">Team Members</div>
                    <a href="team.php" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <!-- Team Completed -->
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?= $team_completed_tasks ?></div>
                    <div class="stat-label">Team Completed</div>
                    <a href="team.php?tab=completed" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <!-- Team Overdue -->
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?= $team_overdue_tasks ?></div>
                    <div class="stat-label">Team Overdue</div>
                    <a href="team.php?tab=overdue" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>
            </div>

            <!-- Upcoming Deadlines and Task Chart Section -->
            <div class="two-column-section">
                <!-- Upcoming Deadlines Widget -->
                <div class="widget">
                    <h2
                        style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: x-large;">
                        <i class="fas fa-clock" style="color: var(--cosmic-pink);"></i>
                        <span>Team Upcoming Deadlines</span>
                    </h2>

                    <div class="widget-content">
                        <?php if (count($upcoming_deadlines) > 0): ?>
                            <div style="display: grid; gap: 0.75rem;">
                                <?php foreach ($upcoming_deadlines as $task):
                                    // Calculate days remaining
                                    $now = new DateTime();
                                    $deadline = new DateTime($task['deadline']);
                                    $interval = $now->diff($deadline);
                                    $days_remaining = $interval->format('%a');

                                    // Determine priority class
                                    $priority_class = '';
                                    switch ($task['priority']) {
                                        case 'critical':
                                            $priority_class = 'critical';
                                            break;
                                        case 'high':
                                            $priority_class = 'high';
                                            break;
                                        case 'medium':
                                            $priority_class = 'medium';
                                            break;
                                        case 'low':
                                            $priority_class = 'low';
                                            break;
                                    }
                                    ?>
                                    <div class="deadline-item <?= $priority_class ?>" data-task-id="<?= $task['task_id'] ?>">
                                        <div class="deadline-header">
                                            <h3 class="deadline-title"><?= htmlspecialchars($task['title']) ?></h3>
                                            <div class="deadline-meta">
                                                <span class="deadline-days">
                                                    <?= $days_remaining == 0 ? 'Due today' : ($days_remaining == 1 ? '1 day left' : $days_remaining . ' days left') ?>
                                                </span>
                                                <span class="deadline-priority priority-<?= $task['priority'] ?>">
                                                    <?= htmlspecialchars($task['priority']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.85rem; opacity: 0.8;">
                                            Assignee: <?= htmlspecialchars($task['assignee']) ?>
                                        </div>
                                        <div style="font-size: 0.85rem; opacity: 0.8; margin-top: 0.25rem;">
                                            Due: <?= date('M j, Y g:i A', strtotime($task['deadline'])) ?>
                                        </div>
                                        <div style="margin-top: 0.5rem;">
                                            <a href="tasks.php?task_id=<?= $task['task_id'] ?>" class="btn btn-small"
                                                style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                                View Task
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-top: 1rem;">
                            <a href="team.php?tab=tasks" class="btn btn-secondary"
                                style="padding: 0.5rem 1rem; width: 100%; text-align: center;">
                                View All Team Tasks
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                            No upcoming deadlines in the next 14 days
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Team Task Summary Chart -->
                <?php if (count($task_data) > 0): ?>
                    <div class="widget">
                        <h2
                            style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: x-large;">
                            <i class="fas fa-chart-pie" style="color: var(--galaxy-gold);"></i>
                            <span>Team Tasks Overview</span>
                        </h2>
                        <div class="chart-container">
                            <canvas id="taskChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Team Members and Recent Activity Section -->
            <div class="two-column-section">
                <!-- Team Members Widget -->
                <div class="widget">
                    <h2
                        style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: x-large;">
                        <i class="fas fa-users" style="color: var(--stellar-blue);"></i>
                        <span>Team Members</span>
                    </h2>

                    <div class="widget-content">

                        <?php
                        // Get team members
                        $team_members_stmt = $pdo->prepare(
                            "SELECT u.user_id, u.full_name, u.role, u.avatar, 
                        (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.user_id AND status != 'completed') as task_count
                        FROM users u
                        JOIN user_departments ud ON u.user_id = ud.user_id
                        WHERE ud.department_id = ? AND u.user_id != ?
                        ORDER BY u.full_name ASC
                        LIMIT 5"
                        );
                        $team_members_stmt->execute([$department['department_id'], $user_id]);
                        $team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (count($team_members) > 0): ?>
                            <div style="display: grid; gap: 0.5rem;">
                                <?php foreach ($team_members as $member): ?>
                                    <div class="team-member">
                                        <div class="team-member-avatar">
                                            <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                                        </div>
                                        <div class="team-member-info">
                                            <div class="team-member-name"><?= htmlspecialchars($member['full_name']) ?></div>
                                            <div class="team-member-role"><?= ucfirst($member['role']) ?></div>
                                        </div>
                                        <div class="team-member-tasks">
                                            <?= $member['task_count'] ?> tasks
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 1rem;">
                                <a href="team.php" class="btn btn-secondary"
                                    style="padding: 0.5rem 1rem; width: 100%; text-align: center;">
                                    View All Team Members
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                                No team members found
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Team Activity Section -->
                <div class="widget">
                    <h2
                        style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: x-large;">
                        <i class="fas fa-history" style="color: var(--stellar-blue);"></i>
                        <span>Team Activity</span>
                    </h2>

                    <div class="widget-content">

                        <?php if (count($activities) > 0): ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($activities as $activity): ?>
                                    <div
                                        style="display: flex; gap: 1rem; padding: 0.75rem; border-radius: 8px; background: rgba(224, 224, 255, 0.03); transition: all 0.2s ease;">
                                        <div
                                            style="width: 40px; height: 40px; border-radius: 50%; background: rgba(108, 77, 246, 0.1); display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-<?=
                                                strpos($activity['action'], 'task') !== false ? 'tasks' :
                                                (strpos($activity['action'], 'login') !== false ? 'sign-in-alt' : 'user')
                                                ?>"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500;"><?= htmlspecialchars($activity['full_name']) ?></div>
                                            <div style="font-size: 0.9rem;"><?= htmlspecialchars($activity['action']) ?></div>
                                            <div style="font-size: 0.8rem; opacity: 0.7;">
                                                <?= date('M j, Y g:i A', strtotime($activity['timestamp'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                                No recent activity
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Announcements Section -->
            <div class="widget">
                <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: x-large;">
                    <i class="fas fa-bullhorn" style="color: var(--galaxy-gold);"></i>
                    <span>Recent Announcements</span>
                </h2>

                <div class="widget-content">

                    <?php if (count($announcements) > 0): ?>
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach ($announcements as $announcement): ?>
                                <div
                                    style="padding: 1rem; border-radius: 8px; background: rgba(224, 224, 255, 0.05); border-left: 3px solid var(--nebula-purple); transition: all 0.2s ease;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <h3 style="margin: 0; font-size: medium; color: var(--cosmic-pink);">
                                            <?= htmlspecialchars($announcement['title']) ?>
                                        </h3>
                                        <div style="font-size: 0.8rem; opacity: 0.7;">
                                            <?= date('M j, Y', strtotime($announcement['created_at'])) ?>
                                        </div>
                                    </div>
                                    <p style="margin: 0; opacity: 0.9;">
                                        <?= htmlspecialchars(substr($announcement['content'], 0, 100)) ?>...
                                    </p>
                                    <a href="#" class="read-more" data-announcement-id="<?= $announcement['announcement_id'] ?>"
                                        style="display: inline-block; margin-top: 0.5rem; font-size: 0.8rem; color: var(--nebula-purple);">Read
                                        more</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                            No recent announcements
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Announcement Modal -->
    <div class="modal" id="announcementModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalAnnouncementTitle"></h2>
                <button class="close-modal" id="closeModal">&times;</button>
            </div>
            <div class="modal-meta">
                <span id="modalAnnouncementAuthor"></span>
                <span id="modalAnnouncementDate"></span>
                <span id="modalAnnouncementDepartment"></span>
            </div>
            <div class="modal-body" id="modalAnnouncementContent"></div>
        </div>
    </div>

    <!-- Reply Message Modal -->
    <div class="modal" id="replyMessageModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Reply Message</h2>
                <button class="close-modal" id="closeReplyModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="replyForm">
                    <input type="hidden" id="replyMessageId">
                    <div class="form-group">
                        <label for="replyContent">Your Message</label>
                        <textarea id="replyContent" rows="5" style="width: 100%;" required></textarea>
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem;">Send Reply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize task chart if data exists
            <?php if (count($task_data) > 0): ?>
                const ctx = document.getElementById('taskChart').getContext('2d');
                const taskChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($chart_labels) ?>,
                        datasets: [{
                            data: <?= json_encode($chart_values) ?>,
                            backgroundColor: <?= json_encode($chart_colors) ?>,
                            borderWidth: 0,
                            hoverOffset: 10
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    color: 'rgba(255, 255, 255, 0.8)',
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 15, 26, 0.9)',
                                titleColor: 'white',
                                bodyColor: 'rgba(255, 255, 255, 0.8)',
                                borderColor: 'rgba(224, 224, 255, 0.1)',
                                borderWidth: 1
                            }
                        },
                        cutout: '70%'
                    }
                });
            <?php endif; ?>

            // Dropdown functionality
            const notificationsBtn = document.getElementById('notificationsBtn');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            const messagesBtn = document.getElementById('messagesBtn');
            const messagesDropdown = document.getElementById('messagesDropdown');
            const markAllRead = document.getElementById('markAllRead');

            // Toggle dropdowns
            notificationsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notificationsDropdown.classList.toggle('show');
                messagesDropdown.classList.remove('show');
            });

            messagesBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                messagesDropdown.classList.toggle('show');
                notificationsDropdown.classList.remove('show');
            });

            // Mark all notifications as read
            if (markAllRead) {
                markAllRead.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'mark_notifications_read=true'
                    }).then(() => {
                        location.reload();
                    });
                });
            }

            // Mark message as read
            // Mark message as read when clicking the message content (but not on action buttons)
            document.querySelectorAll('.message-item .message-content').forEach(content => {
                content.addEventListener('click', function () {
                    const messageItem = this.closest('.message-item');
                    if (messageItem.classList.contains('unread')) {
                        const messageId = messageItem.getAttribute('data-message-id');

                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `mark_message_read=true&message_id=${messageId}`
                        }).then(() => {
                            messageItem.classList.remove('unread');

                            // Update the unread messages count in the UI
                            const unreadCountBadge = document.querySelector('#messagesBtn .task-badge');
                            if (unreadCountBadge) {
                                const currentCount = parseInt(unreadCountBadge.textContent);
                                if (currentCount > 1) {
                                    unreadCountBadge.textContent = currentCount - 1;
                                } else {
                                    unreadCountBadge.remove();
                                }
                            }
                        });
                    }
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function () {
                notificationsDropdown.classList.remove('show');
                messagesDropdown.classList.remove('show');
            });

            // Announcement modal functionality
            const modal = document.getElementById('announcementModal');
            const closeModal = document.getElementById('closeModal');
            const readMoreLinks = document.querySelectorAll('.read-more');

            readMoreLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const announcementId = this.getAttribute('data-announcement-id');

                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `get_announcement=true&announcement_id=${announcementId}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('modalAnnouncementTitle').textContent = data.title;
                            document.getElementById('modalAnnouncementAuthor').textContent = `By: ${data.author_name}`;
                            document.getElementById('modalAnnouncementDate').textContent = `On: ${new Date(data.created_at).toLocaleDateString()}`;
                            document.getElementById('modalAnnouncementDepartment').textContent = data.department_name ? `Department: ${data.department_name}` : 'Global Announcement';
                            document.getElementById('modalAnnouncementContent').textContent = data.content;
                            modal.style.display = 'flex';
                        });
                });
            });

            closeModal.addEventListener('click', function () {
                modal.style.display = 'none';
            });

            window.addEventListener('click', function (e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });

            // View All Messages button
            document.getElementById('viewAllMessages')?.addEventListener('click', function (e) {
                e.preventDefault();
                // In a real implementation, this would redirect to a messages page
                alert('This would redirect to a full messages page');
            });

            // View All Notifications button
            document.getElementById('viewAllNotifications')?.addEventListener('click', function (e) {
                e.preventDefault();
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'get_all_notifications=true'
                })
                    .then(response => response.json())
                    .then(notifications => {
                        const modal = document.createElement('div');
                        modal.className = 'modal';
                        modal.id = 'allNotificationsModal';
                        modal.innerHTML = `
                            <div class="modal-content" style="max-width: 800px;">
                                <div class="modal-header">
                                    <h2 class="modal-title">All Notifications</h2>
                                    <button class="close-modal">&times;</button>
                                </div>
                                <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                                    <div style="display: grid; gap: 0.5rem;">
                                        ${notifications.map(notif => `
                                            <div class="notification-item ${notif.is_read ? 'read' : 'unread'}" data-notification-id="${notif.notification_id}">
                                                <div class="notification-title">${notif.title}</div>
                                                <div class="notification-message">${notif.message}</div>
                                                <div class="notification-time">
                                                    ${new Date(notif.created_at).toLocaleString()}
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modal);
                        modal.style.display = 'flex';

                        // Close modal
                        modal.querySelector('.close-modal').addEventListener('click', () => {
                            modal.remove();
                        });

                        // Close when clicking outside
                        modal.addEventListener('click', (e) => {
                            if (e.target === modal) {
                                modal.remove();
                            }
                        });
                    });
            });

            // View All Messages button in dropdown
            document.getElementById('viewAllMessagesBtn')?.addEventListener('click', function (e) {
                e.preventDefault();
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'get_all_messages=true'
                })
                    .then(response => response.json())
                    .then(messages => {
                        const modal = document.createElement('div');
                        modal.className = 'modal';
                        modal.id = 'allMessagesModal';
                        modal.innerHTML = `
                            <div class="modal-content" style="max-width: 800px;">
                                <div class="modal-header">
                                    <h2 class="modal-title">All Messages</h2>
                                    <button class="close-modal">&times;</button>
                                </div>
                                <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                                    <div style="display: grid; gap: 0.5rem;">
                                        ${messages.map(msg => `
                                            <div class="message-item ${msg.is_read ? '' : 'unread'}" 
                                                data-message-id="${msg.message_id}">
                                                <div class="message-avatar">
                                                    ${msg.sender_name ? msg.sender_name.charAt(0).toUpperCase() : ''}
                                                </div>
                                                <div class="message-content">
                                                    <div class="message-sender">${msg.sender_name || 'System'}</div>
                                                    <div class="message-text">
                                                        ${msg.content.substring(0, 100)}${msg.content.length > 100 ? '...' : ''}
                                                    </div>
                                                    <div class="message-time">
                                                        ${new Date(msg.sent_at).toLocaleString()}
                                                    </div>
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modal);
                        modal.style.display = 'flex';

                        // Close modal
                        modal.querySelector('.close-modal').addEventListener('click', () => {
                            modal.remove();
                        });

                        // Close when clicking outside
                        modal.addEventListener('click', (e) => {
                            if (e.target === modal) {
                                modal.remove();
                            }
                        });
                    });
            });
        });

        document.querySelectorAll('.message-action[title="Reply"]').forEach(button => {
            button.addEventListener('click', function (e) {
                e.stopPropagation(); // Prevent the message item click event from firing

                const messageItem = this.closest('.message-item');
                const messageId = messageItem.getAttribute('data-message-id');
                const senderName = messageItem.querySelector('.message-sender').textContent;

                // Set up the reply modal
                document.getElementById('replyMessageId').value = messageId;
                document.getElementById('replyMessageModal').querySelector('.modal-title').textContent = `Reply to ${senderName}`;
                document.getElementById('replyContent').value = '';

                // Show the modal
                document.getElementById('replyMessageModal').style.display = 'flex';
            });
        });

        // Close reply modal
        document.getElementById('closeReplyModal').addEventListener('click', function () {
            document.getElementById('replyMessageModal').style.display = 'none';
        });

        // Handle reply form submission
        document.getElementById('replyForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const messageId = document.getElementById('replyMessageId').value;
            const content = document.getElementById('replyContent').value;

            if (!content.trim()) {
                alert('Please enter a message');
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `send_reply=true&message_id=${messageId}&content=${encodeURIComponent(content)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('Reply sent successfully');
                        document.getElementById('replyMessageModal').style.display = 'none';
                        // You might want to refresh the messages here
                    } else {
                        alert('Failed to send reply');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while sending the reply');
                });
        });

        // Mark message as read when clicking the mark-read button
        // Replace the existing mark as read code with this improved version
        document.querySelectorAll('.mark-read-btn').forEach(button => {
            button.addEventListener('click', function (e) {
                e.stopPropagation(); // Prevent the message item click event from firing

                const messageItem = this.closest('.message-item');
                if (!messageItem.classList.contains('unread')) return;

                const messageId = messageItem.getAttribute('data-message-id');

                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `mark_message_read=true&message_id=${messageId}`
                }).then(response => {
                    if (response.ok) {
                        // Update UI immediately
                        messageItem.classList.remove('unread');

                        // Update the unread messages count in the UI
                        const unreadCountBadge = document.querySelector('#messagesBtn .task-badge');
                        if (unreadCountBadge) {
                            const currentCount = parseInt(unreadCountBadge.textContent);
                            if (currentCount > 1) {
                                unreadCountBadge.textContent = currentCount - 1;
                            } else {
                                unreadCountBadge.remove();
                            }
                        }

                        // Update the unread count in the database
                        updateUnreadCount();
                    }
                }).catch(error => {
                    console.error('Error marking message as read:', error);
                });
            });
        });

        // Function to update unread count from server
        function updateUnreadCount() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'get_unread_count=true'
            })
                .then(response => response.json())
                .then(data => {
                    const unreadBadge = document.querySelector('#messagesBtn .task-badge');
                    if (data.unread_messages > 0) {
                        if (unreadBadge) {
                            unreadBadge.textContent = data.unread_messages;
                        } else {
                            // Create badge if it doesn't exist
                            const badge = document.createElement('span');
                            badge.className = 'task-badge';
                            badge.style.position = 'absolute';
                            badge.style.top = '-5px';
                            badge.style.right = '-5px';
                            badge.textContent = data.unread_messages;
                            document.getElementById('messagesBtn').appendChild(badge);
                        }
                    } else if (unreadBadge) {
                        unreadBadge.remove();
                    }
                });
        }
    </script>
</body>

</html>