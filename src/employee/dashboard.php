<?php
// Employee Dashboard - Complete Standalone Version
// File: src/employee/dashboard.php

// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/auth.php");
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

// Get tasks count
$tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_stmt->execute([$user_id]);
$pending_tasks = $tasks_stmt->fetchColumn();

// Get completed tasks count
$completed_tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed'");
$completed_tasks_stmt->execute([$user_id]);
$completed_tasks = $completed_tasks_stmt->fetchColumn();

// Get overdue tasks count
$overdue_tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND deadline < NOW() AND status != 'completed'");
$overdue_tasks_stmt->execute([$user_id]);
$overdue_tasks = $overdue_tasks_stmt->fetchColumn();

// Get task status breakdown for chart
$task_chart_stmt = $pdo->prepare(
    "SELECT status, COUNT(*) as count 
    FROM tasks 
    WHERE assigned_to = ?
    GROUP BY status"
);
$task_chart_stmt->execute([$user_id]);
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
    WHERE (a.is_global = 1 OR ud.user_id = ?)
    ORDER BY a.created_at DESC LIMIT 3"
);
$announcements_stmt->execute([$user_id]);
$announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department info
$dept_stmt = $pdo->prepare(
    "SELECT d.* FROM departments d
    JOIN user_departments ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ? AND ud.is_primary = 1"
);
$dept_stmt->execute([$user_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activity
$activity_stmt = $pdo->prepare(
    "SELECT * FROM activity_log 
    WHERE user_id = ? 
    ORDER BY timestamp DESC LIMIT 5"
);
$activity_stmt->execute([$user_id]);
$activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming events
$events_stmt = $pdo->prepare(
    "SELECT e.* FROM events e
    JOIN event_attendees ea ON e.event_id = ea.event_id
    WHERE ea.user_id = ? AND e.start_time > NOW()
    ORDER BY e.start_time ASC LIMIT 3"
);
$events_stmt->execute([$user_id]);
$upcoming_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming deadlines (tasks due in the next 14 days)
$deadlines_stmt = $pdo->prepare(
    "SELECT task_id, title, deadline, status, priority 
    FROM tasks 
    WHERE assigned_to = ? 
    AND deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
    AND status != 'completed'
    ORDER BY deadline ASC
    LIMIT 5"
);
$deadlines_stmt->execute([$user_id]);
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
?>

<?php
// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_notifications_read']) && $_POST['mark_notifications_read'] == 'true') {
        $mark_read = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $mark_read->execute([$user_id]);
        exit;
    }

    if (isset($_POST['mark_message_read']) && isset($_POST['message_id'])) {
        $mark_read = $pdo->prepare("UPDATE messages SET is_read = TRUE WHERE message_id = ? AND receiver_id = ?");
        $mark_read->execute([$_POST['message_id'], $user_id]);
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

    if (isset($_POST['send_reply']) && isset($_POST['message_id']) && isset($_POST['content'])) {
        // Get original message to determine if it's a group or direct message
        $stmt = $pdo->prepare(
            "SELECT sender_id, receiver_id, group_id FROM messages WHERE message_id = ?"
        );
        $stmt->execute([$_POST['message_id']]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($original) {
            $insert = $pdo->prepare(
                "INSERT INTO messages (sender_id, receiver_id, group_id, content, is_group_message, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())"
            );

            if ($original['group_id']) {
                // Reply to group
                $insert->execute([
                    $user_id,
                    null,
                    $original['group_id'],
                    $_POST['content'],
                    true
                ]);
            } else {
                // Reply directly to sender
                $insert->execute([
                    $user_id,
                    $original['sender_id'],
                    null,
                    $_POST['content'],
                    false
                ]);
            }

            echo json_encode(['status' => 'success']);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_notifications_read']) && $_POST['mark_notifications_read'] == 'true') {
        $mark_read = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $mark_read->execute([$user_id]);
        exit;
    }

    if (isset($_POST['mark_notification_read']) && isset($_POST['notification_id'])) {
        $mark_read = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE notification_id = ? AND user_id = ?");
        $mark_read->execute([$_POST['notification_id'], $user_id]);
        exit;
    }

    if (isset($_POST['mark_message_read']) && isset($_POST['message_id'])) {
        $mark_read = $pdo->prepare("UPDATE messages SET is_read = TRUE WHERE message_id = ? AND (receiver_id = ? OR group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))");
        $mark_read->execute([$_POST['message_id'], $user_id, $user_id]);
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

    if (isset($_POST['get_message']) && isset($_POST['message_id'])) {
        $stmt = $pdo->prepare(
            "SELECT m.*, u.full_name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.message_id = ? AND (m.receiver_id = ? OR (m.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?)))"
        );
        $stmt->execute([$_POST['message_id'], $user_id, $user_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($message) {
            header('Content-Type: application/json');
            echo json_encode($message);
            exit;
        }
    }

    if (isset($_POST['get_notification']) && isset($_POST['notification_id'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM notifications WHERE notification_id = ? AND user_id = ?"
        );
        $stmt->execute([$_POST['notification_id'], $user_id]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($notification) {
            header('Content-Type: application/json');
            echo json_encode($notification);
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
    <title>TeamSphere | Employee Dashboard</title>
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
        }

        .widget:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            border-color: var(--nebula-purple);
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
            text-align: center;
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

        .stat-card {
            padding: 1rem;
            text-align: center;
            border-radius: 8px;
            background: rgba(15, 15, 26, 0.5);
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .stat-icon {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--cosmic-pink);
        }

        .stat-label {
            font-size: 0.8rem;
            opacity: 0.8;
            margin-bottom: 0.5rem;
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
                <a href="department.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>My Department</span>
                </a>
                <a href="documents.php" class="nav-link">
                    <i class="fas fa-folder"></i>
                    <span>Documents</span>
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
                                                <button class="message-action" title="Mark as read"><i
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

                    <div class="notification-dropdown">
                        <button class="btn btn-secondary" style="padding: 0.5rem 1rem;" id="helpBtn">
                            <i class="fas fa-question-circle"></i>
                        </button>
                        <div class="dropdown-content" id="helpDropdown">
                            <h3 style="margin-top: 0; margin-bottom: 1rem;">Quick Help</h3>
                            <div style="display: grid; gap: 0.5rem;">
                                <a href="#"
                                    style="padding: 0.5rem; border-radius: 6px; transition: background 0.2s; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-book"></i>
                                    <span>Documentation</span>
                                </a>
                                <a href="#"
                                    style="padding: 0.5rem; border-radius: 6px; transition: background 0.2s; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-video"></i>
                                    <span>Tutorials</span>
                                </a>
                                <a href="#"
                                    style="padding: 0.5rem; border-radius: 6px; transition: background 0.2s; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-headset"></i>
                                    <span>Contact Support</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                    <div class="stat-value"><?= $pending_tasks ?></div>
                    <div class="stat-label">Pending Tasks</div>
                    <a href="tasks.php" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?= $completed_tasks ?></div>
                    <div class="stat-label">Completed</div>
                    <a href="tasks.php?status=completed" class="btn btn-secondary"
                        style="padding: 0.5rem 1rem;">View</a>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?= $overdue_tasks ?></div>
                    <div class="stat-label">Overdue</div>
                    <a href="tasks.php?status=overdue" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                    <div class="stat-value"><?= $unread_messages_count ?></div>
                    <div class="stat-label">Messages</div>
                    <a href="#" id="viewAllMessages" class="btn btn-secondary" style="padding: 0.5rem 1rem;">View</a>
                </div>
            </div>

            <!-- Previous code remains the same until the Calendar and Task Chart Section -->

            <!-- Upcoming Deadlines and Task Chart Section -->
            <div class="two-column-section">
                <!-- Upcoming Deadlines Widget -->
                <div class="widget">
                    <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-clock" style="color: var(--cosmic-pink);"></i>
                        <span>Upcoming Deadlines</span>
                    </h2>

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
                                        Due: <?= date('M j, Y g:i A', strtotime($task['deadline'])) ?>
                                    </div>
                                    <div style="margin-top: 0.5rem;">
                                        <a href="tasks.php?task_id=<?= $task['task_id'] ?>" class="btn btn-small" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            View Task
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="tasks.php" class="btn btn-secondary" style="padding: 0.5rem 1rem; width: 100%; text-align: center;">
                                View All Tasks
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center; opacity: 0.7;">
                            No upcoming deadlines in the next 14 days
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Task Summary Chart -->
                <?php if (count($task_data) > 0): ?>
                    <div class="widget">
                        <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-chart-pie" style="color: var(--galaxy-gold);"></i>
                            <span>Your Tasks Overview</span>
                        </h2>
                        <div class="chart-container">
                            <canvas id="taskChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Announcements and Activity Section -->
            <div class="two-column-section">
                <!-- Announcements Section -->
                <div class="widget">
                    <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-bullhorn" style="color: var(--galaxy-gold);"></i>
                        <span>Recent Announcements</span>
                    </h2>

                    <?php if (count($announcements) > 0): ?>
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach ($announcements as $announcement): ?>
                                <div
                                    style="padding: 1rem; border-radius: 8px; background: rgba(224, 224, 255, 0.05); border-left: 3px solid var(--nebula-purple); transition: all 0.2s ease;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <h3 style="margin: 0; font-size: medium; color: var(--cosmic-pink);"><?= htmlspecialchars($announcement['title']) ?></h3>
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

                <!-- Recent Activity Section -->
                <div class="widget">
                    <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-history" style="color: var(--stellar-blue);"></i>
                        <span>Your Recent Activity</span>
                    </h2>

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
                                        <div style="font-weight: 500;"><?= htmlspecialchars($activity['action']) ?></div>
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

    <!-- Rest of the JavaScript code remains the same -->
    <script>
        // Dynamic stars background
        document.addEventListener('DOMContentLoaded', function () {
            const starsContainer = document.querySelector('.stars');
            const starsCount = 100;

            for (let i = 0; i < starsCount; i++) {
                const star = document.createElement('div');
                star.style.position = 'absolute';
                star.style.width = `${Math.random() * 3}px`;
                star.style.height = star.style.width;
                star.style.backgroundColor = 'white';
                star.style.borderRadius = '50%';
                star.style.top = `${Math.random() * 100}%`;
                star.style.left = `${Math.random() * 100}%`;
                star.style.opacity = Math.random();
                star.style.animation = `twinkle ${2 + Math.random() * 3}s infinite alternate`;
                starsContainer.appendChild(star);
            }

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
            const helpBtn = document.getElementById('helpBtn');
            const helpDropdown = document.getElementById('helpDropdown');
            const markAllRead = document.getElementById('markAllRead');

            // Toggle dropdowns
            notificationsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notificationsDropdown.classList.toggle('show');
                messagesDropdown.classList.remove('show');
                helpDropdown.classList.remove('show');
            });

            messagesBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                messagesDropdown.classList.toggle('show');
                notificationsDropdown.classList.remove('show');
                helpDropdown.classList.remove('show');
            });

            helpBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                helpDropdown.classList.toggle('show');
                notificationsDropdown.classList.remove('show');
                messagesDropdown.classList.remove('show');
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
            document.querySelectorAll('.message-item').forEach(item => {
                item.addEventListener('click', function () {
                    const messageId = this.getAttribute('data-message-id');
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `mark_message_read=true&message_id=${messageId}`
                    });
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function () {
                notificationsDropdown.classList.remove('show');
                messagesDropdown.classList.remove('show');
                helpDropdown.classList.remove('show');
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
        });

        // Message Modal
        function showMessageModal(message) {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'messageModal';
            modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Message from ${message.sender_name}</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-meta">
                <span><i class="fas fa-clock"></i> ${new Date(message.sent_at).toLocaleString()}</span>
                <span><i class="fas fa-envelope"></i> ${message.is_group_message ? 'Group Message' : 'Direct Message'}</span>
            </div>
            <div class="modal-body">
                ${message.content}
            </div>
            <div class="modal-footer" style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                <div style="flex: 1;">
                    <textarea id="replyText" style="width: 100%; padding: 0.5rem; background: rgba(255,255,255,0.1); 
                        color: white; border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;" 
                        placeholder="Type your reply here..."></textarea>
                </div>
                <button class="btn btn-primary" id="sendReplyBtn" data-message-id="${message.message_id}">
                    <i class="fas fa-paper-plane"></i> Send Reply
                </button>
                <button class="btn btn-secondary" id="markMessageReadBtn" data-message-id="${message.message_id}">
                    <i class="fas fa-check"></i> Mark as Read
                </button>
            </div>
        </div>
    `;

            document.body.appendChild(modal);
            modal.style.display = 'flex';

            // Close modal
            modal.querySelector('.close-modal').addEventListener('click', () => {
                modal.remove();
            });

            // Mark as read
            modal.querySelector('#markMessageReadBtn').addEventListener('click', function () {
                const messageId = this.getAttribute('data-message-id');
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `mark_message_read=true&message_id=${messageId}`
                }).then(() => {
                    modal.remove();
                    location.reload();
                });
            });

            // Send reply
            modal.querySelector('#sendReplyBtn').addEventListener('click', function () {
                const messageId = this.getAttribute('data-message-id');
                const content = modal.querySelector('#replyText').value;
                if (content.trim()) {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `send_reply=true&message_id=${messageId}&content=${encodeURIComponent(content)}`
                    }).then(() => {
                        modal.remove();
                        location.reload();
                    });
                }
            });

            // Close when clicking outside
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Notification Modal
        function showNotificationModal(notification) {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'notificationModal';
            modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">${notification.title}</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-meta">
                <span><i class="fas fa-clock"></i> ${new Date(notification.created_at).toLocaleString()}</span>
                <span><i class="fas fa-bell"></i> ${notification.type}</span>
            </div>
            <div class="modal-body">
                ${notification.message}
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" id="markNotificationRead" data-notification-id="${notification.notification_id}">
                    <i class="fas fa-check"></i> Mark as Read
                </button>
            </div>
        </div>
    `;

            document.body.appendChild(modal);
            modal.style.display = 'flex';

            // Close modal
            modal.querySelector('.close-modal').addEventListener('click', () => {
                modal.remove();
            });

            // Mark as read
            modal.querySelector('#markNotificationRead').addEventListener('click', function () {
                const notificationId = this.getAttribute('data-notification-id');
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `mark_notification_read=true&notification_id=${notificationId}`
                }).then(() => {
                    location.reload();
                });
            });

            // Close when clicking outside
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Update the message click handler
        document.querySelectorAll('.message-item').forEach(item => {
            item.addEventListener('click', function () {
                const messageId = this.getAttribute('data-message-id');
                // In a real implementation, you would fetch the full message details
                const message = {
                    message_id: messageId,
                    sender_name: this.querySelector('.message-sender').textContent.trim(),
                    content: this.querySelector('.message-text').textContent.trim(),
                    sent_at: this.querySelector('.message-time').textContent.trim(),
                    is_group_message: this.getAttribute('data-is-group') === 'true'
                };
                showMessageModal(message);
            });
        });

        // Update the notification click handler
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function () {
                const notification = {
                    notification_id: this.getAttribute('data-notification-id'),
                    title: this.querySelector('.notification-title').textContent.trim(),
                    message: this.querySelector('.notification-message').textContent.trim(),
                    created_at: this.querySelector('.notification-time').textContent.trim(),
                    type: this.getAttribute('data-type')
                };
                showNotificationModal(notification);
            });
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
            showAllNotifications();
        });

        // View All Messages button in dropdown
        document.getElementById('viewAllMessagesBtn')?.addEventListener('click', function (e) {
            e.preventDefault();
            showAllMessages();
        });

        // View All Notifications modal
        function showAllNotifications() {
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
                <div class="modal-footer" style="display: flex; justify-content: space-between; margin-top: 1rem;">
                    <button class="btn btn-secondary" id="markAllNotificationsRead">
                        <i class="fas fa-check"></i> Mark All as Read
                    </button>
                    <button class="btn btn-primary" id="closeAllNotifications">
                        Close
                    </button>
                </div>
            </div>
        `;

                    document.body.appendChild(modal);
                    modal.style.display = 'flex';

                    // Close modal
                    modal.querySelector('.close-modal').addEventListener('click', () => {
                        modal.remove();
                    });

                    modal.querySelector('#closeAllNotifications').addEventListener('click', () => {
                        modal.remove();
                    });

                    // Mark all as read
                    modal.querySelector('#markAllNotificationsRead').addEventListener('click', () => {
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

                    // Notification click handler
                    modal.querySelectorAll('.notification-item').forEach(item => {
                        item.addEventListener('click', function () {
                            const notificationId = this.getAttribute('data-notification-id');
                            fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `get_notification=true&notification_id=${notificationId}`
                            })
                                .then(response => response.json())
                                .then(notification => {
                                    showNotificationModal(notification);
                                });
                        });
                    });
                });
        }

        // View All Messages modal
        function showAllMessages() {
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
                                 data-message-id="${msg.message_id}" 
                                 data-is-group="${msg.is_group_message}">
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
                                <div class="message-actions">
                                    <button class="message-action reply-btn" title="Reply">
                                        <i class="fas fa-reply"></i>
                                    </button>
                                    <button class="message-action mark-read-btn" title="Mark as read">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="modal-footer" style="margin-top: 1rem;">
                    <button class="btn btn-primary" id="closeAllMessages">
                        Close
                    </button>
                </div>
            </div>
        `;

                    document.body.appendChild(modal);
                    modal.style.display = 'flex';

                    // Close modal
                    modal.querySelector('.close-modal').addEventListener('click', () => {
                        modal.remove();
                    });

                    modal.querySelector('#closeAllMessages').addEventListener('click', () => {
                        modal.remove();
                    });

                    // Message click handler
                    modal.querySelectorAll('.message-item').forEach(item => {
                        item.addEventListener('click', function (e) {
                            // Don't open message if clicking on action buttons
                            if (e.target.closest('.message-actions')) return;

                            const messageId = this.getAttribute('data-message-id');
                            fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `get_message=true&message_id=${messageId}`
                            })
                                .then(response => response.json())
                                .then(message => {
                                    showMessageModal(message);
                                });
                        });
                    });

                    // Reply button handler
                    modal.querySelectorAll('.reply-btn').forEach(btn => {
                        btn.addEventListener('click', function (e) {
                            e.stopPropagation();
                            const messageItem = this.closest('.message-item');
                            const messageId = messageItem.getAttribute('data-message-id');
                            const senderName = messageItem.querySelector('.message-sender').textContent;

                            // Remove any existing reply forms
                            document.querySelectorAll('.reply-form-container').forEach(el => el.remove());

                            // Create reply form
                            const replyForm = document.createElement('div');
                            replyForm.className = 'reply-form-container';
                            replyForm.innerHTML = `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(15, 15, 26, 0.3); border-radius: 8px;">
                        <h4>Reply to ${senderName}</h4>
                        <textarea id="replyContent" style="width: 100%; min-height: 100px; margin-bottom: 1rem; 
                            padding: 0.5rem; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.1); 
                            border-radius: 4px;" placeholder="Type your reply here..."></textarea>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-primary" id="sendReplyBtn">Send</button>
                            <button class="btn btn-secondary" id="cancelReplyBtn">Cancel</button>
                        </div>
                    </div>
                `;

                            messageItem.after(replyForm);

                            // Focus on textarea
                            replyForm.querySelector('#replyContent').focus();

                            // Send reply
                            replyForm.querySelector('#sendReplyBtn').addEventListener('click', function () {
                                const content = replyForm.querySelector('#replyContent').value;
                                if (content.trim()) {
                                    fetch('', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                        },
                                        body: `send_reply=true&message_id=${messageId}&content=${encodeURIComponent(content)}`
                                    }).then(() => {
                                        location.reload();
                                    });
                                }
                            });

                            // Cancel reply
                            replyForm.querySelector('#cancelReplyBtn').addEventListener('click', function () {
                                replyForm.remove();
                            });
                        });
                    });

                    // Mark as read button handler
                    modal.querySelectorAll('.mark-read-btn').forEach(btn => {
                        btn.addEventListener('click', function (e) {
                            e.stopPropagation();
                            const messageId = this.closest('.message-item').getAttribute('data-message-id');
                            fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `mark_message_read=true&message_id=${messageId}`
                            }).then(() => {
                                location.reload();
                            });
                        });
                    });
                });
        }
    </script>
</body>

</html>