<?php
// Employee Department View - Complete Standalone Version
// File: src/employee/department.php

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

// Get pending tasks count for badge
$tasks_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_count_stmt->execute([$user_id]);
$pending_tasks = $tasks_count_stmt->fetchColumn();

// Get primary department info
$dept_stmt = $pdo->prepare(
    "SELECT d.* FROM departments d
    JOIN user_departments ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ? AND ud.is_primary = 1"
);
$dept_stmt->execute([$user_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Get department members if department exists
$department_members = [];
if ($department) {
    $members_stmt = $pdo->prepare(
        "SELECT u.user_id, u.full_name, u.job_title, u.avatar, u.email 
        FROM users u
        JOIN user_departments ud ON u.user_id = ud.user_id
        WHERE ud.department_id = ? AND u.status = 'active'
        ORDER BY 
            CASE WHEN u.user_id = ? THEN 0 ELSE 1 END,
            u.full_name"
    );
    $members_stmt->execute([$department['department_id'], $user_id]);
    $department_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get department tasks
    $tasks_stmt = $pdo->prepare(
        "SELECT t.*, u.full_name as assigned_name 
        FROM tasks t
        JOIN users u ON t.assigned_to = u.user_id
        WHERE t.department_id = ?
        ORDER BY 
            CASE t.status
                WHEN 'pending' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            CASE t.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END,
            t.deadline ASC"
    );
    $tasks_stmt->execute([$department['department_id']]);
    $department_tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get department documents
    $docs_stmt = $pdo->prepare(
        "SELECT d.*, u.full_name as uploaded_by_name 
        FROM documents d
        JOIN users u ON d.uploaded_by = u.user_id
        WHERE d.department_id = ?
        ORDER BY d.uploaded_at DESC
        LIMIT 5"
    );
    $docs_stmt->execute([$department['department_id']]);
    $department_docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get child departments if current department exists
$child_departments = [];
if ($department) {
    $child_stmt = $pdo->prepare(
        "SELECT * FROM departments 
        WHERE parent_id = ?
        ORDER BY name"
    );
    $child_stmt->execute([$department['department_id']]);
    $child_departments = $child_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle message submission if form was posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
    $content = trim(filter_input(INPUT_POST, 'content', FILTER_SANITIZE_STRING));

    if ($receiver_id && $content) {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, sent_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $receiver_id, $content]);
            $message_sent = true;
        } catch (PDOException $e) {
            $message_error = "Failed to send message: " . $e->getMessage();
        }
    } else {
        $message_error = "Invalid message data";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | My Department</title>
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

        .department-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .department-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background:
                <?= $department ? $department['color'] : 'var(--nebula-purple)' ?>
            ;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 0 20px
                <?= $department ? $department['color'] : 'var(--nebula-purple)' ?>
                50;
        }

        .department-info h1 {
            margin: 0 0 0.5rem 0;
            background: none;
            color: var(--neon-white);
        }

        .department-info p {
            margin: 0;
            opacity: 0.8;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .section-title i {
            font-size: 1.5rem;
            color: var(--nebula-purple);
        }

        .member-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(15, 15, 26, 0.7);
            margin-bottom: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
        }

        .member-card:hover {
            border-color: var(--nebula-purple);
            transform: translateY(-3px);
        }

        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            flex-shrink: 0;
        }

        .member-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .member-info {
            flex: 1;
        }

        .member-info h3 {
            margin: 0 0 0.25rem 0;
        }

        .member-info p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.9rem;
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
        }

        .task-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 3px solid;
            transition: all 0.3s ease;
        }

        .task-card.priority-critical {
            border-left-color: #ff4d4d;
        }

        .task-card.priority-high {
            border-left-color: #ff9e4d;
        }

        .task-card.priority-medium {
            border-left-color: var(--galaxy-gold);
        }

        .task-card.priority-low {
            border-left-color: #4dff4d;
        }

        .task-card h3 {
            margin: 0 0 0.5rem 0;
        }

        .task-card p {
            margin: 0;
            opacity: 0.9;
        }

        .task-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .doc-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(15, 15, 26, 0.7);
            margin-bottom: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .doc-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            background: rgba(108, 77, 246, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--nebula-purple);
            flex-shrink: 0;
        }

        .doc-info {
            flex: 1;
        }

        .doc-info h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
        }

        .doc-info p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.8rem;
        }

        .empty-state {
            padding: 2rem;
            text-align: center;
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--nebula-purple);
        }

        .child-department {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(15, 15, 26, 0.7);
            margin-bottom: 1rem;
            border-left: 3px solid;
        }

        .child-department-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: currentColor;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }

        .child-department-info {
            flex: 1;
        }

        .child-department-info h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
        }

        .child-department-info p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.8rem;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .department-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .department-icon {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
        }

        /* Quick Links Styling */
        .quick-links {
            margin-bottom: 2rem;
        }

        .quick-links div {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -webkit-overflow-scrolling: touch;
            /* Smooth scrolling on iOS */
        }

        /* Button Outline Style (for quick links) */
        .btn-outline {
            background: linear-gradient(135deg, rgba(108, 77, 246, 0.3), rgba(74, 144, 226, 0.3));
            border-left: 3px solid var(--cosmic-pink);
            color: var(--neon-white);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-outline:hover {
            background: rgba(224, 224, 255, 0.05);
            border-color: var(--nebula-purple);
            color: var(--neon-white);
        }

        /* Message Button Hover Effect */
        .member-actions .btn-secondary:hover {
            background: var(--nebula-purple);
            transform: scale(1.05);
        }

        /* Primary Member Badge */
        .member-info span {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-left: 0.5rem;
            font-weight: normal;
        }

        /* Scrollbar Styling for Quick Links (optional) */
        .quick-links::-webkit-scrollbar {
            height: 4px;
        }

        .quick-links::-webkit-scrollbar-track {
            background: rgba(224, 224, 255, 0.05);
        }

        .quick-links::-webkit-scrollbar-thumb {
            background: var(--nebula-purple);
            border-radius: 2px;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .quick-links {
                margin-bottom: 1.5rem;
            }

            .btn-outline {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }

            .member-actions .btn-secondary {
                padding: 0.4rem;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(15, 15, 26, 0.95);
            border-radius: 8px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            border: 1px solid var(--nebula-purple);
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                <div class="avatar"
                    style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?= htmlspecialchars($user['full_name']) ?></div>
                    <div style="font-size: 0.8rem; opacity: 0.7;"><?= htmlspecialchars($user['job_title']) ?></div>
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
                        <span class="task-badge"
                            style="margin-left: auto; background: var(--cosmic-pink); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;"><?= $pending_tasks ?></span>
                    <?php endif; ?>
                </a>
                <a href="department.php" class="nav-link active">
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
            <?php if ($department): ?>
                <div class="department-header">
                    <div class="department-icon"
                        style="background: <?= $department['color'] ?>; box-shadow: 0 0 20px <?= $department['color'] ?>50;">
                        <?= strtoupper(substr($department['name'], 0, 1)) ?>
                    </div>
                    <div class="department-info">
                        <h1><?= htmlspecialchars($department['name']) ?></h1>
                        <p><?= htmlspecialchars($department['description']) ?></p>
                    </div>
                </div>

                <!-- Add this right after the department-header div -->
                <div class="quick-links" style="margin-bottom: 2rem;">
                    <div style="display: flex; gap: 1rem; overflow-x: auto; padding-bottom: 0.5rem;">
                        <a href="#team-members" class="btn btn-outline" style="white-space: nowrap;">
                            <i class="fas fa-users"></i> Team Members
                        </a>
                        <a href="#department-tasks" class="btn btn-outline" style="white-space: nowrap;">
                            <i class="fas fa-tasks"></i> Department Tasks
                        </a>
                        <a href="#department-documents" class="btn btn-outline" style="white-space: nowrap;">
                            <i class="fas fa-folder"></i> Documents
                        </a>
                        <?php if (count($child_departments) > 0): ?>
                            <a href="#child-departments" class="btn btn-outline" style="white-space: nowrap;">
                                <i class="fas fa-sitemap"></i> Child Departments
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Team Members Section -->
                <div style="margin-bottom: 3rem;">
                    <div class="section-title" id="team-members">
                        <i class="fas fa-users"></i>
                        <h2>Team Members</h2>
                    </div>

                    <?php if (count($department_members) > 0): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
                            <?php foreach ($department_members as $member): ?>
                                <div class="member-card">
                                    <div class="member-avatar">
                                        <?php if ($member['avatar'] && $member['avatar'] !== 'default-star.png'): ?>
                                            <img src="../../../assets/images/planets/<?= htmlspecialchars($member['avatar']) ?>"
                                                alt="<?= htmlspecialchars($member['full_name']) ?>">
                                        <?php else: ?>
                                            <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="member-info">
                                        <h3><?= htmlspecialchars($member['full_name']) ?></h3>
                                        <p><?= htmlspecialchars($member['job_title']) ?></p>
                                        <p style="font-size: 0.8rem;"><?= htmlspecialchars($member['email']) ?></p>
                                    </div>
                                    <div class="member-actions">
                                        <?php if ($member['user_id'] !== $user_id): ?>
                                            <button class="btn btn-secondary" style="padding: 0.5rem;" title="Message"
                                                onclick="openMessageModal(<?= $member['user_id'] ?>, '<?= htmlspecialchars(addslashes($member['full_name'])) ?>')">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        <?php else: ?>
                                            <span
                                                style="font-size: 0.8rem; padding: 0.1rem; opacity: 0.7; color:var(--cosmic-pink)">(You)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-friends"></i>
                            <h3>No Team Members</h3>
                            <p>There are no members in this department</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Department Tasks Section -->
                <div style="margin-bottom: 3rem;">
                    <div class="section-title">
                        <i class="fas fa-tasks"></i>
                        <h2 id="department-tasks">Department Tasks</h2>
                        <a href="tasks.php" class="btn btn-secondary" style="margin-left: auto; padding: 0.5rem 1rem;">
                            View All Tasks <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (count($department_tasks) > 0): ?>
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach (array_slice($department_tasks, 0, 5) as $task): ?>
                                <div class="task-card priority-<?= $task['priority'] ?>">
                                    <h3><?= htmlspecialchars($task['title']) ?></h3>
                                    <p><?= htmlspecialchars(substr($task['description'], 0, 100)) ?><?= strlen($task['description']) > 100 ? '...' : '' ?>
                                    </p>
                                    <div class="task-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($task['assigned_name']) ?></span>
                                        <?php if ($task['deadline']): ?>
                                            <span><i class="fas fa-calendar-alt"></i>
                                                <?= date('M j, Y', strtotime($task['deadline'])) ?></span>
                                        <?php endif; ?>
                                        <span><i class="fas fa-bolt"></i> <?= ucfirst($task['priority']) ?></span>
                                        <span style="margin-left: auto; font-weight: 600; color: 
                                            <?= $task['status'] === 'pending' ? '#FFC107' :
                                                ($task['status'] === 'in_progress' ? '#2196F3' : '#4CAF50') ?>">
                                            <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <h3>No Department Tasks</h3>
                            <p>There are no tasks assigned to this department</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Department Documents Section -->
                <div style="margin-bottom: 3rem;">
                    <div class="section-title">
                        <i class="fas fa-folder"></i>
                        <h2 id="department-documents">Department Documents</h2>
                        <a href="documents.php" class="btn btn-secondary" style="margin-left: auto; padding: 0.5rem 1rem;">
                            View All Documents <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (count($department_docs) > 0): ?>
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach ($department_docs as $doc): ?>
                                <div class="doc-card">
                                    <div class="doc-icon">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="doc-info">
                                        <h3><?= htmlspecialchars($doc['name']) ?></h3>
                                        <p>Uploaded by <?= htmlspecialchars($doc['uploaded_by_name']) ?> on
                                            <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?>
                                        </p>
                                        <p><?= round($doc['file_size'] / 1024, 1) ?> KB • <?= strtoupper($doc['file_type']) ?></p>
                                    </div>
                                    <a href="../../../uploads/nebula_documents/<?= htmlspecialchars(basename($doc['file_path'])) ?>"
                                        class="btn btn-secondary" style="padding: 0.5rem;" download title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Department Documents</h3>
                            <p>There are no documents uploaded to this department</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Child Departments Section -->
                <?php if (count($child_departments) > 0): ?>
                    <div style="margin-bottom: 3rem;">
                        <div class="section-title">
                            <i class="fas fa-sitemap"></i>
                            <h2 id="child-departments">Child Departments</h2>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
                            <?php foreach ($child_departments as $child): ?>
                                <div class="child-department" style="border-left-color: <?= $child['color'] ?>;">
                                    <div class="child-department-icon" style="background: <?= $child['color'] ?>;">
                                        <?= strtoupper(substr($child['name'], 0, 1)) ?>
                                    </div>
                                    <div class="child-department-info">
                                        <h3><?= htmlspecialchars($child['name']) ?></h3>
                                        <p><?= htmlspecialchars(substr($child['description'], 0, 50)) ?><?= strlen($child['description']) > 50 ? '...' : '' ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state" style="margin-top: 3rem;">
                    <i class="fas fa-users"></i>
                    <h1>No Department Assigned</h1>
                    <p>You are not currently assigned to any department. Please contact your administrator.</p>
                    <a href="dashboard.php" class="btn btn-primary" style="margin-top: 1rem;">
                        Back to Dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-content"
            style="background: rgba(15, 15, 26, 0.95); border-radius: 8px; padding: 2rem; width: 90%; max-width: 500px; border: 1px solid var(--nebula-purple);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="messageModalTitle" style="margin: 0;">Send Message</h2>
                <button onclick="closeMessageModal()"
                    style="background: none; border: none; color: var(--neon-white); font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>

            <?php if (isset($message_sent) && $message_sent): ?>
                <div
                    style="padding: 1rem; background: rgba(71, 184, 129, 0.2); border: 1px solid #47B881; border-radius: 8px; margin-bottom: 1rem;">
                    Message sent successfully!
                </div>
                <button onclick="closeMessageModal()" class="btn btn-primary"
                    style="width: 100%; padding: 0.75rem;">Close</button>
            <?php else: ?>
                <?php if (isset($message_error)): ?>
                    <div
                        style="padding: 1rem; background: rgba(255, 107, 107, 0.2); border: 1px solid #FF6B6B; border-radius: 8px; margin-bottom: 1rem;">
                        <?= htmlspecialchars($message_error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" onsubmit="return sendMessage(event)">
                    <input type="hidden" name="send_message" value="1">
                    <input type="hidden" id="receiverId" name="receiver_id">
                    <div style="margin-bottom: 1.5rem;">
                        <textarea id="messageContent" name="content" rows="5"
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
        });
        // Message Modal Functions
        function openMessageModal(userId, userName) {
            document.getElementById('receiverId').value = userId;
            document.getElementById('messageModalTitle').textContent = `Message to ${userName}`;
            document.getElementById('messageModal').style.display = 'flex';
            // Reset form state when opening
            document.getElementById('messageContent').value = '';
            // Remove any existing success/error messages
            const messages = document.querySelectorAll('.modal-content > div');
            messages.forEach(msg => {
                if (!msg.contains(document.querySelector('#messageModalTitle'))) {
                    msg.remove();
                }
            });
        }

        function closeMessageModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        function sendMessage(event) {
            // Basic validation before submitting
            const content = document.getElementById('messageContent').value.trim();
            if (!content) {
                alert('Please enter a message');
                return false;
            }
            return true; // Allow form submission
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modal = document.getElementById('messageModal');
            if (event.target === modal) {
                closeMessageModal();
            }
        };
    </script>
</body>

</html>