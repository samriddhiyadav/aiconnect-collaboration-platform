<?php
// Manager Reports - Complete Standalone Version
// File: src/manager/reports.php

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

// Initialize variables
$error = '';
$success = '';
$reportData = [];
$chartData = [];

// Handle report generation if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_type'])) {
    $reportType = $_POST['report_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';

    switch ($reportType) {
        case 'team_performance':
            $reportData = generateTeamPerformanceReport($pdo, $department['department_id'], $startDate, $endDate);
            $chartData = prepareTeamPerformanceChartData($reportData);
            break;

        case 'task_status':
            $reportData = generateTaskStatusReport($pdo, $department['department_id'], $startDate, $endDate);
            $chartData = prepareTaskStatusChartData($reportData);
            break;

        case 'team_activity':
            $reportData = generateTeamActivityReport($pdo, $department['department_id'], $startDate, $endDate);
            $chartData = prepareTeamActivityChartData($reportData);
            break;

        default:
            $error = "Invalid report type selected";
            break;
    }

    if (!empty($reportData)) {
        $success = "Report generated successfully";
    }
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $upload_dir = "../../uploads/nebula_documents/";
    $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    $file_name = $_FILES['document']['name'];
    $file_tmp = $_FILES['document']['tmp_name'];
    $file_type = $_FILES['document']['type'];
    $file_size = $_FILES['document']['size'];
    $file_error = $_FILES['document']['error'];

    // Validate file
    if ($file_error !== UPLOAD_ERR_OK) {
        $error = "File upload error: " . $file_error;
    } elseif (!in_array($file_type, $allowed_types)) {
        $error = "Only PDF, Word, and Excel files are allowed";
    } elseif ($file_size > 10 * 1024 * 1024) { // 10MB limit
        $error = "File size must be less than 10MB";
    } else {
        // Generate unique filename
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid('doc_', true) . '.' . $file_ext;
        $destination = $upload_dir . $unique_name;

        if (move_uploaded_file($file_tmp, $destination)) {
            // Insert document record
            $insert_stmt = $pdo->prepare(
                "INSERT INTO documents (name, file_path, uploaded_by, department_id, file_size, file_type)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $insert_stmt->execute([
                $file_name,
                $unique_name,
                $_SESSION['user_id'],
                $department['department_id'],
                $file_size,
                $file_type
            ]);

            $success = "Document uploaded successfully!";
            header("Location: reports.php");
            exit();
        } else {
            $error = "Failed to move uploaded file";
        }
    }
}

// Handle document download
if (isset($_GET['download'])) {
    $doc_id = $_GET['download'];
    $doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ? AND (department_id = ? OR uploaded_by = ?)");
    $doc_stmt->execute([$doc_id, $department['department_id'], $_SESSION['user_id']]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

    if ($document) {
        $file_path = "../../uploads/nebula_documents/" . $document['file_path'];

        if (file_exists($file_path)) {
            // Update download count
            $update_stmt = $pdo->prepare("UPDATE documents SET downloads = downloads + 1 WHERE document_id = ?");
            $update_stmt->execute([$doc_id]);

            // Send file to browser
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $document['file_type']);
            header('Content-Disposition: attachment; filename="' . basename($document['name']) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
            exit();
        }
    }

    // If file doesn't exist or not authorized
    $error = "Document not found or access denied";
    header("Location: reports.php");
    exit();
}

// Get department documents
$documents_stmt = $pdo->prepare(
    "SELECT d.*, u.full_name as uploaded_by_name 
     FROM documents d
     JOIN users u ON d.uploaded_by = u.user_id
     WHERE d.department_id = ?
     ORDER BY d.uploaded_at DESC"
);
$documents_stmt->execute([$department['department_id']]);
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Report generation functions
function generateTeamPerformanceReport($pdo, $department_id, $startDate, $endDate)
{
    $params = [':dept_id' => $department_id];
    $sql = "
        SELECT 
            u.user_id,
            u.full_name,
            COUNT(t.task_id) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.deadline < NOW() AND t.status != 'completed' THEN 1 ELSE 0 END) AS overdue_tasks,
            AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at)) AS avg_completion_hours
        FROM users u
        JOIN user_departments ud ON u.user_id = ud.user_id
        LEFT JOIN tasks t ON u.user_id = t.assigned_to
        WHERE ud.department_id = :dept_id
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " AND t.created_at BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $sql .= " GROUP BY u.user_id ORDER BY completed_tasks DESC, overdue_tasks ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateTaskStatusReport($pdo, $department_id, $startDate, $endDate)
{
    $params = [':dept_id' => $department_id];
    $sql = "
        SELECT 
            t.status,
            COUNT(*) as count,
            AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at)) as avg_completion_time,
            SUM(CASE WHEN t.deadline < NOW() AND t.status != 'completed' THEN 1 ELSE 0 END) as overdue_count
        FROM tasks t
        JOIN user_departments ud ON t.assigned_to = ud.user_id
        WHERE ud.department_id = :dept_id
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " AND t.created_at BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $sql .= " GROUP BY t.status ORDER BY count DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateTeamActivityReport($pdo, $department_id, $startDate, $endDate)
{
    $params = [':dept_id' => $department_id];
    $sql = "
        SELECT 
            DATE(a.timestamp) AS activity_date,
            COUNT(DISTINCT a.user_id) AS active_users,
            COUNT(a.log_id) AS total_actions,
            SUM(CASE WHEN a.action LIKE 'task%' THEN 1 ELSE 0 END) AS task_actions,
            SUM(CASE WHEN a.action LIKE 'message%' THEN 1 ELSE 0 END) AS message_actions
        FROM activity_log a
        JOIN user_departments ud ON a.user_id = ud.user_id
        WHERE ud.department_id = :dept_id
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " AND a.timestamp BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    } else {
        // Default to last 30 days if no date range provided
        $sql .= " AND a.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ";
    }

    $sql .= " GROUP BY DATE(a.timestamp) ORDER BY activity_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Chart data preparation functions
function prepareTeamPerformanceChartData($reportData)
{
    $labels = [];
    $completed = [];
    $inProgress = [];
    $pending = [];
    $overdue = [];

    foreach ($reportData as $row) {
        $labels[] = $row['full_name'];
        $completed[] = $row['completed_tasks'];
        $inProgress[] = $row['in_progress_tasks'];
        $pending[] = $row['pending_tasks'];
        $overdue[] = $row['overdue_tasks'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completed',
                'data' => $completed,
                'backgroundColor' => '#47B881'
            ],
            [
                'label' => 'In Progress',
                'data' => $inProgress,
                'backgroundColor' => '#FFC154'
            ],
            [
                'label' => 'Pending',
                'data' => $pending,
                'backgroundColor' => '#4A90E2'
            ],
            [
                'label' => 'Overdue',
                'data' => $overdue,
                'backgroundColor' => '#FF6B9D'
            ]
        ]
    ];
}

function prepareTaskStatusChartData($reportData)
{
    $labels = [];
    $values = [];
    $colors = ['#47B881', '#FFC154', '#4A90E2', '#FF6B9D', '#6C4DF6'];

    foreach ($reportData as $index => $row) {
        $labels[] = ucfirst(str_replace('_', ' ', $row['status']));
        $values[] = $row['count'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'data' => $values,
                'backgroundColor' => array_slice($colors, 0, count($values))
            ]
        ]
    ];
}

function prepareTeamActivityChartData($reportData)
{
    $labels = [];
    $activeUsers = [];
    $totalActions = [];

    foreach ($reportData as $row) {
        $labels[] = date('M j', strtotime($row['activity_date']));
        $activeUsers[] = $row['active_users'];
        $totalActions[] = $row['total_actions'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Active Users',
                'data' => $activeUsers,
                'borderColor' => '#6C4DF6',
                'backgroundColor' => 'rgba(108, 77, 246, 0.1)',
                'fill' => true,
                'tension' => 0.4
            ],
            [
                'label' => 'Total Actions',
                'data' => $totalActions,
                'borderColor' => '#4A90E2',
                'backgroundColor' => 'rgba(74, 144, 226, 0.1)',
                'fill' => true,
                'tension' => 0.4
            ]
        ]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Manager Reports</title>
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
            height: 300px;
            width: 100%;
        }

        .report-form {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .form-col {
            flex: 1;
            min-width: 200px;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(15, 15, 26, 0.5);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 8px;
            color: white;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--nebula-purple);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(224, 224, 255, 0.1);
        }

        .data-table th {
            font-weight: 600;
            color: var(--cosmic-pink);
            text-transform: uppercase;
            font-size: 0.8rem;
        }

        .data-table tr:hover {
            background: rgba(108, 77, 246, 0.1);
        }

        .status-badge {
            display: inline-block;
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

        .status-overdue {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
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

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
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

        .document-card {
            background: rgba(15, 15, 26, 0.4);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .document-card:hover {
            background: rgba(108, 77, 246, 0.1);
        }

        .document-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
            color: var(--nebula-purple);
        }

        .document-actions {
            display: flex;
            gap: 0.5rem;
        }

        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(15, 15, 26, 0.5);
            border: 1px dashed rgba(224, 224, 255, 0.1);
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .file-input-label:hover {
            background: rgba(108, 77, 246, 0.1);
            border-color: var(--cosmic-pink);
        }

        .file-input-text {
            flex: 1;
        }

        .file-input-name {
            opacity: 0.7;
            font-style: italic;
        }

        .file-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
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
                </a>
                <a href="team.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>My Team</span>
                </a>
                <a href="reports.php" class="nav-link active">
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
                <h1 style="margin: 0;">Team Reports</h1>
                <div style="font-size: 0.9rem; opacity: 0.8; color: var(--cosmic-pink);">
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

            <!-- Report Form -->
            <div class="widget">
                <form method="post" action="reports.php">
                    <div class="form-row">
                        <div class="form-col">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select id="report_type" name="report_type" class="form-control" required>
                                <option value="">Select a report type</option>
                                <option value="team_performance" <?= ($_POST['report_type'] ?? '') === 'team_performance' ? 'selected' : '' ?>>Team Performance</option>
                                <option value="task_status" <?= ($_POST['report_type'] ?? '') === 'task_status' ? 'selected' : '' ?>>Task Status</option>
                                <option value="team_activity" <?= ($_POST['report_type'] ?? '') === 'team_activity' ? 'selected' : '' ?>>Team Activity</option>
                            </select>
                        </div>

                        <div class="form-col">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
                        </div>

                        <div class="form-col">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control"
                                value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>

            <!-- Report Results -->
            <?php if (!empty($reportData)): ?>
                <div class="widget">
                    <h2 style="margin-bottom: 1.5rem;">
                        <?php
                        switch ($_POST['report_type']) {
                            case 'team_performance':
                                echo 'Team Performance Report';
                                break;
                            case 'task_status':
                                echo 'Task Status Report';
                                break;
                            case 'team_activity':
                                echo 'Team Activity Report';
                                break;
                            default:
                                echo 'Report Results';
                        }
                        ?>
                    </h2>

                    <!-- Chart Container -->
                    <div class="chart-container">
                        <canvas id="reportChart"></canvas>
                    </div>

                    <!-- Data Table -->
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php
                                    // Generate table headers based on report type
                                    if (!empty($reportData)) {
                                        $firstRow = $reportData[0];
                                        foreach ($firstRow as $key => $value) {
                                            echo '<th>' . ucwords(str_replace('_', ' ', $key)) . '</th>';
                                        }
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $key => $value): ?>
                                            <td>
                                                <?php
                                                // Format specific values
                                                if ($key === 'status') {
                                                    echo '<span class="status-badge status-' . str_replace(' ', '_', strtolower($value)) . '">' . ucfirst(str_replace('_', ' ', $value)) . '</span>';
                                                } elseif ($key === 'avg_completion_time' || $key === 'avg_completion_hours') {
                                                    echo $value ? round($value, 2) . ' hours' : 'N/A';
                                                } else {
                                                    echo htmlspecialchars($value) ?: '0';
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

             <!-- Documents Section -->
            <div class="widget">
                <h2 style="margin-bottom: 1.5rem;">
                    <i class="fas fa-folder-open"></i> Department Documents
                </h2>

                <!-- Upload Form -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-top: 0; margin-bottom: 1rem; font-size: 1rem; color: var(--cosmic-pink);">
                        Upload New Document
                    </h3>

                    <form method="post" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-col">
                                <div class="file-input-container">
                                    <label for="document" class="file-input-label">
                                        <i class="fas fa-file-import"></i>
                                        <span class="file-input-text">Choose a file (PDF, Word, Excel)</span>
                                        <span class="file-input-name" id="file-name-display">No file selected</span>
                                    </label>
                                    <input type="file" class="file-input" id="document" name="document"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <small style="opacity: 0.7;">Maximum file size: 10MB</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Upload Document
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Documents List -->
                <?php if (count($documents) > 0): ?>
                    <div>
                        <?php foreach ($documents as $doc): ?>
                            <div class="document-card">
                                <div style="display: flex; align-items: center;">
                                    <?php
                                    $icon = 'fa-file';
                                    if (strpos($doc['file_type'], 'pdf') !== false) {
                                        $icon = 'fa-file-pdf';
                                    } elseif (strpos($doc['file_type'], 'word') !== false || strpos($doc['file_type'], 'document') !== false) {
                                        $icon = 'fa-file-word';
                                    } elseif (strpos($doc['file_type'], 'excel') !== false || strpos($doc['file_type'], 'spreadsheet') !== false) {
                                        $icon = 'fa-file-excel';
                                    }
                                    ?>
                                    <i class="fas <?= $icon ?> document-icon"></i>
                                    <div style="flex: 1;">
                                        <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem;">
                                            <?= htmlspecialchars($doc['name']) ?>
                                        </h3>
                                        <div style="display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.8rem; opacity: 0.8;">
                                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                <i class="fas fa-user"></i>
                                                <span><?= htmlspecialchars($doc['uploaded_by_name']) ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                <i class="fas fa-download"></i>
                                                <span><?= $doc['downloads'] ?> downloads</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                <i class="fas fa-file"></i>
                                                <span><?= round($doc['file_size'] / 1024) ?> KB</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="document-actions">
                                    <a href="reports.php?download=<?= $doc['document_id'] ?>" class="btn btn-primary" style="padding: 0.5rem;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; opacity: 0.7;">
                        <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                        <h3>No documents found</h3>
                        <p>There are no documents available for your department</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize chart when report data is available
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (!empty($chartData)): ?>
                const ctx = document.getElementById('reportChart').getContext('2d');

                // Determine chart type based on report type
                const reportType = '<?= $_POST['report_type'] ?? '' ?>';
                let chartType = 'bar';

                if (reportType === 'task_status') {
                    chartType = 'doughnut';
                } else if (reportType === 'team_activity') {
                    chartType = 'line';
                }

                // Create the chart
                new Chart(ctx, {
                    type: chartType,
                    data: <?= json_encode($chartData) ?>,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: 'rgba(255, 255, 255, 0.8)'
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
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(224, 224, 255, 0.1)'
                                },
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.7)'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(224, 224, 255, 0.1)'
                                },
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.7)'
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>

            // File name display
            document.getElementById('document').addEventListener('change', function (e) {
                const fileName = e.target.files[0] ? e.target.files[0].name : 'No file selected';
                document.getElementById('file-name-display').textContent = fileName;
            });
        });
    </script>
</body>

</html>