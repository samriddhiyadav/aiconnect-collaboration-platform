<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/auth.php');
    exit;
}

// Verify admin role
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard/dashboard.php');
    exit;
}

// Define root path and require database connection
define('ROOT_PATH', realpath(dirname(__DIR__) . '/..'));
require_once ROOT_PATH . '/includes/db_connect.php';
require_once ROOT_PATH . '/includes/functions.php';

// Initialize variables
$error = '';
$success = '';
$reportData = [];
$chartData = [];

try {
    $pdo = Database::getInstance();

    // Handle report generation if form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $reportType = $_POST['report_type'] ?? '';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $departmentId = $_POST['department_id'] ?? '';

        switch ($reportType) {
            case 'user_activity':
                $reportData = generateUserActivityReport($pdo, $startDate, $endDate);
                $chartData = prepareUserActivityChartData($reportData);
                break;

            case 'task_performance':
                $reportData = generateTaskPerformanceReport($pdo, $startDate, $endDate, $departmentId);
                $chartData = prepareTaskPerformanceChartData($reportData);
                break;

            case 'department_metrics':
                $reportData = generateDepartmentMetricsReport($pdo, $startDate, $endDate);
                $chartData = prepareDepartmentMetricsChartData($reportData);
                break;

            case 'system_usage':
                $reportData = generateSystemUsageReport($pdo, $startDate, $endDate);
                $chartData = prepareSystemUsageChartData($reportData);
                break;

            default:
                $error = "Invalid report type selected";
                break;
        }

        if (!empty($reportData)) {
            $success = "Report generated successfully";
        }
    }

    // Get all departments for filters
    $departments = $pdo->query("SELECT department_id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Log report access
    log_activity($_SESSION['user_id'], 'report_access', 'Accessed reports dashboard');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "An error occurred while generating the report.";
}

// Report generation functions
function generateUserActivityReport($pdo, $startDate, $endDate)
{
    $params = [];
    $sql = "
        SELECT 
            u.user_id,
            u.username,
            u.full_name,
            u.role,
            COUNT(DISTINCT a.log_id) AS total_actions,
            COUNT(DISTINCT CASE WHEN a.action LIKE 'task%' THEN a.log_id END) AS task_actions,
            COUNT(DISTINCT CASE WHEN a.action LIKE 'message%' THEN a.log_id END) AS message_actions,
            COUNT(DISTINCT CASE WHEN a.action LIKE 'event%' THEN a.log_id END) AS event_actions,
            MAX(a.timestamp) AS last_activity,
            SUM(CASE WHEN a.action = 'login' THEN 1 ELSE 0 END) AS login_count
        FROM users u
        LEFT JOIN activity_log a ON u.user_id = a.user_id
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " WHERE a.timestamp BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $sql .= " GROUP BY u.user_id ORDER BY total_actions DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateTaskPerformanceReport($pdo, $startDate, $endDate, $departmentId)
{
    $params = [];
    $sql = "
        SELECT 
            u.user_id,
            u.full_name,
            d.name AS department,
            COUNT(t.task_id) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.deadline < NOW() AND t.status != 'completed' THEN 1 ELSE 0 END) AS overdue_tasks,
            AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at)) AS avg_completion_hours
        FROM users u
        LEFT JOIN tasks t ON u.user_id = t.assigned_to
        LEFT JOIN user_departments ud ON u.user_id = ud.user_id AND ud.is_primary = 1
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE t.task_id IS NOT NULL
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " AND t.created_at BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    // Add department filter if provided
    if (!empty($departmentId)) {
        $sql .= " AND ud.department_id = :department_id ";
        $params[':department_id'] = $departmentId;
    }

    $sql .= " GROUP BY u.user_id ORDER BY completed_tasks DESC, overdue_tasks ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateDepartmentMetricsReport($pdo, $startDate, $endDate)
{
    $params = [];
    $sql = "
        SELECT 
            d.department_id,
            d.name,
            d.color,
            COUNT(DISTINCT ud.user_id) AS member_count,
            COUNT(DISTINCT t.task_id) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.deadline < NOW() AND t.status != 'completed' THEN 1 ELSE 0 END) AS overdue_tasks,
            AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at)) AS avg_completion_hours
        FROM departments d
        LEFT JOIN user_departments ud ON d.department_id = ud.department_id
        LEFT JOIN tasks t ON d.department_id = t.department_id
        WHERE 1=1
    ";

    // Add date filters if provided
    if (!empty($startDate) && !empty($endDate)) {
        $sql .= " AND t.created_at BETWEEN :start_date AND :end_date ";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $sql .= " GROUP BY d.department_id ORDER BY completed_tasks DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateSystemUsageReport($pdo, $startDate, $endDate)
{
    $params = [];
    $sql = "
        SELECT 
            DATE(a.timestamp) AS activity_date,
            COUNT(DISTINCT a.user_id) AS active_users,
            COUNT(a.log_id) AS total_actions,
            SUM(CASE WHEN a.action LIKE 'task%' THEN 1 ELSE 0 END) AS task_actions,
            SUM(CASE WHEN a.action LIKE 'message%' THEN 1 ELSE 0 END) AS message_actions,
            SUM(CASE WHEN a.action LIKE 'event%' THEN 1 ELSE 0 END) AS event_actions
        FROM activity_log a
        WHERE 1=1
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
function prepareUserActivityChartData($reportData)
{
    $labels = [];
    $totalActions = [];
    $taskActions = [];
    $messageActions = [];
    $eventActions = [];

    foreach ($reportData as $row) {
        $labels[] = $row['full_name'];
        $totalActions[] = $row['total_actions'];
        $taskActions[] = $row['task_actions'];
        $messageActions[] = $row['message_actions'];
        $eventActions[] = $row['event_actions'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Total Actions',
                'data' => $totalActions,
                'backgroundColor' => '#6C4DF6'
            ],
            [
                'label' => 'Task Actions',
                'data' => $taskActions,
                'backgroundColor' => '#4A90E2'
            ],
            [
                'label' => 'Message Actions',
                'data' => $messageActions,
                'backgroundColor' => '#FF6B9D'
            ],
            [
                'label' => 'Event Actions',
                'data' => $eventActions,
                'backgroundColor' => '#47B881'
            ]
        ]
    ];
}

function prepareTaskPerformanceChartData($reportData)
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

function prepareDepartmentMetricsChartData($reportData)
{
    $labels = [];
    $completed = [];
    $inProgress = [];
    $pending = [];
    $overdue = [];
    $colors = [];

    foreach ($reportData as $row) {
        $labels[] = $row['name'];
        $completed[] = $row['completed_tasks'];
        $inProgress[] = $row['in_progress_tasks'];
        $pending[] = $row['pending_tasks'];
        $overdue[] = $row['overdue_tasks'];
        $colors[] = $row['color'] ?? '#6C4DF6';
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completed Tasks',
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
            ],
            [
                'label' => 'Members',
                'data' => array_column($reportData, 'member_count'),
                'backgroundColor' => $colors,
                'type' => 'bar'
            ]
        ]
    ];
}

function prepareSystemUsageChartData($reportData)
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

// Get all documents for admin
$documents_stmt = $pdo->prepare(
    "SELECT d.*, u.full_name as uploaded_by_name, dep.name as department_name 
     FROM documents d
     JOIN users u ON d.uploaded_by = u.user_id
     LEFT JOIN departments dep ON d.department_id = dep.department_id
     ORDER BY d.uploaded_at DESC"
);
$documents_stmt->execute();
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle file upload
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
        $upload_error = "File upload error: " . $file_error;
    } elseif (!in_array($file_type, $allowed_types)) {
        $upload_error = "Only PDF, Word, and Excel files are allowed";
    } elseif ($file_size > 10 * 1024 * 1024) { // 10MB limit
        $upload_error = "File size must be less than 10MB";
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
                $_POST['department_id'] ?? null,
                $file_size,
                $file_type
            ]);

            // Log activity
            log_activity($_SESSION['user_id'], 'document_upload', "Uploaded document: $file_name");

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
    $doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ?");
    $doc_stmt->execute([$doc_id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

    if ($document) {
        $file_path = "../../uploads/nebula_documents/" . $document['file_path'];

        if (file_exists($file_path)) {
            // Update download count
            $update_stmt = $pdo->prepare("UPDATE documents SET downloads = downloads + 1 WHERE document_id = ?");
            $update_stmt->execute([$doc_id]);

            // Log activity
            log_activity($_SESSION['user_id'], 'document_download', "Downloaded document: " . $document['name']);

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

    // If file doesn't exist
    $error = "Document not found";
    header("Location: reports.php");
    exit();
}

// Handle document deletion
if (isset($_GET['delete'])) {
    $doc_id = $_GET['delete'];
    $doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ?");
    $doc_stmt->execute([$doc_id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

    if ($document) {
        $file_path = "../../uploads/nebula_documents/" . $document['file_path'];

        // Delete file from server
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // Delete record from database
        $delete_stmt = $pdo->prepare("DELETE FROM documents WHERE document_id = ?");
        $delete_stmt->execute([$doc_id]);

        // Log activity
        log_activity($_SESSION['user_id'], 'document_delete', "Deleted document: " . $document['name']);

        $success = "Document deleted successfully!";
    } else {
        $error = "Document not found";
    }

    header("Location: reports.php");
    exit();
}

// Get all documents for admin with department filter
$department_filter = isset($_GET['doc_dept']) && $_GET['doc_dept'] != '' ? $_GET['doc_dept'] : null;
$documents_sql = "SELECT d.*, u.full_name as uploaded_by_name, dep.name as department_name 
                 FROM documents d
                 JOIN users u ON d.uploaded_by = u.user_id
                 LEFT JOIN departments dep ON d.department_id = dep.department_id
                 WHERE 1=1";

if ($department_filter) {
    $documents_sql .= " AND d.department_id = :department_id";
}

$documents_sql .= " ORDER BY d.uploaded_at DESC";

$documents_stmt = $pdo->prepare($documents_sql);

if ($department_filter) {
    $documents_stmt->bindParam(':department_id', $department_filter, PDO::PARAM_INT);
}

$documents_stmt->execute();
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Reports Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reports-container {
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

        .report-form {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            margin-bottom: 2rem;
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
            margin-top: 1rem;
        }

        .report-results {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            margin-bottom: 2rem;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .report-title {
            font-size: 1.5rem;
            font-weight: 600;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .chart-container {
            height: 400px;
            margin-bottom: 2rem;
            position: relative;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2rem;
        }

        .data-table th,
        .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .data-table th {
            font-weight: 600;
            color: var(--cosmic-pink);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        .data-table tr:hover {
            background: rgba(108, 77, 246, 0.1);
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

        .export-options {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
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
            .reports-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 1rem;
            }

            .form-col {
                width: 100%;
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

        .document-card {
            background: rgba(15, 15, 26, 0.4);
            border-radius: var(--radius-md);
            padding: 1rem;
            border: 1px solid var(--quantum-foam);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition-normal);
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
            border: 1px dashed var(--quantum-foam);
            border-radius: var(--radius-md);
            color: var(--neon-white);
            cursor: pointer;
            transition: var(--transition-normal);
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

        /* Add to the existing styles */
        #document-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        #document-modal>div {
            background: var(--deep-space);
            border-radius: 12px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--nebula-purple);
            box-shadow: 0 0 30px rgba(108, 77, 246, 0.3);
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            border: none;
            font-family: var(--font-primary);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-normal);
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
            color: var(--neon-white);
        }

        .btn-danger {
            background: linear-gradient(135deg,rgb(107, 0, 0),rgb(147, 74, 68));
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }

        .btn i {
            font-size: 0.9em;
        }
    </style>
</head>

<body>
    <div class="reports-container">
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
                <a href="reports.php" class="nav-link active">
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
                <h1 class="page-title">Reports Dashboard</h1>
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
            <div class="report-form">
                <form method="post" action="reports.php">
                    <div class="form-row">
                        <div class="form-col">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select id="report_type" name="report_type" class="form-control" required>
                                <option value="">Select a report type</option>
                                <option value="user_activity" <?= ($_POST['report_type'] ?? '') === 'user_activity' ? 'selected' : '' ?>>User Activity</option>
                                <option value="task_performance" <?= ($_POST['report_type'] ?? '') === 'task_performance' ? 'selected' : '' ?>>Task Performance</option>
                                <option value="department_metrics" <?= ($_POST['report_type'] ?? '') === 'department_metrics' ? 'selected' : '' ?>>Department Metrics</option>
                                <option value="system_usage" <?= ($_POST['report_type'] ?? '') === 'system_usage' ? 'selected' : '' ?>>System Usage</option>
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

                        <div class="form-col" id="department-col"
                            style="<?= ($_POST['report_type'] ?? '') === 'task_performance' ? '' : 'display: none;' ?>">
                            <label for="department_id" class="form-label">Department</label>
                            <select id="department_id" name="department_id" class="form-control">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>" <?= ($_POST['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                <div class="report-results">
                    <div class="report-header">
                        <h2 class="report-title">
                            <?php
                            switch ($_POST['report_type']) {
                                case 'user_activity':
                                    echo 'User Activity Report';
                                    break;
                                case 'task_performance':
                                    echo 'Task Performance Report';
                                    break;
                                case 'department_metrics':
                                    echo 'Department Metrics Report';
                                    break;
                                case 'system_usage':
                                    echo 'System Usage Report';
                                    break;
                                default:
                                    echo 'Report Results';
                            }
                            ?>
                        </h2>
                        <div class="export-options">
                            <button class="btn btn-secondary" onclick="exportToCSV()">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </button>
                            <button class="btn btn-secondary" onclick="exportToPDF()">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                        </div>
                    </div>

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
                                                if ($key === 'last_activity' || $key === 'activity_date') {
                                                    echo $value ? date('M j, Y H:i', strtotime($value)) : 'N/A';
                                                } elseif ($key === 'avg_completion_hours') {
                                                    echo $value ? round($value, 2) . ' hours' : 'N/A';
                                                } elseif ($key === 'status') {
                                                    echo '<span class="status-badge status-' . $value . '">' . ucfirst($value) . '</span>';
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
            <div class="report-results" style="margin-top: 2rem;">
                <div class="report-header">
                    <h2 class="report-title">
                        <i class="fas fa-folder-open"></i> Document Management
                    </h2>
                </div>

                <!-- Upload Form -->
                <div class="upload-form" style="margin-bottom: 2rem;">
                    <h3
                        style="margin-top: 0; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: large; color: var(--cosmic-pink);">
                        <i class="fas fa-upload" style="color: var(--nebula-purple);"></i>
                        <span>Upload New Document</span>
                    </h3>

                    <form method="post" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-col">
                                <label class="form-label" for="document">Select File (PDF, Word, Excel)</label>
                                <div class="file-input-container">
                                    <label for="document" class="file-input-label">
                                        <i class="fas fa-file-import"></i>
                                        <span class="file-input-text">Choose a file</span>
                                        <span class="file-input-name" id="file-name-display">No file selected</span>
                                    </label>
                                    <input type="file" class="file-input" id="document" name="document"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                                </div>
                            </div>

                            <!-- Add this above the documents list -->
                            <div class="form-row" style="margin-bottom: 1rem;">
                                <div class="form-col">
                                    <label for="doc_dept" class="form-label">Filter by Department</label>
                                    <select id="doc_dept" name="doc_dept" class="form-control"
                                        onchange="filterDocuments()">
                                        <option value="">All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['department_id'] ?>" <?= ($_GET['doc_dept'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($dept['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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
                    <div style="display: grid; gap: 1rem;">
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
                                        <h3 style="margin: 0 0 0.5rem 0; font-size: large;">
                                            <?= htmlspecialchars($doc['name']) ?>
                                        </h3>
                                        <div
                                            style="display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.9rem; opacity: 0.8;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i class="fas fa-user"></i>
                                                <span><?= htmlspecialchars($doc['uploaded_by_name']) ?></span>
                                            </div>
                                            <?php if ($doc['department_name']): ?>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <i class="fas fa-users"></i>
                                                    <span><?= htmlspecialchars($doc['department_name']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i class="fas fa-download"></i>
                                                <span><?= $doc['downloads'] ?> downloads</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i class="fas fa-file"></i>
                                                <span><?= round($doc['file_size'] / 1024) ?> KB</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="document-actions">
                                    <a href="reports.php?download=<?= $doc['document_id'] ?>" class="btn btn-primary btn-sm"
                                        style="padding: 0.5rem;" title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="btn btn-secondary btn-sm" style="padding: 0.5rem;"
                                        onclick="showDocumentInfo(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Details">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm" style="padding: 0.5rem;"
                                        onclick="confirmDelete(<?= $doc['document_id'] ?>, '<?= htmlspecialchars(addslashes($doc['name'])) ?>')"
                                        title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h3>No documents found</h3>
                        <p>There are no documents available in the system</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Document Info Modal -->
            <div id="document-modal"
                style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 1000; align-items: center; justify-content: center;">
                <div
                    style="background: var(--deep-space); border-radius: 12px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--nebula-purple);">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 id="modal-doc-title" style="margin: 0;"></h2>
                        <button onclick="closeModal()"
                            style="background: none; border: none; color: var(--neon-white); font-size: 1.5rem; cursor: pointer;">&times;</button>
                    </div>
                    <div id="modal-doc-content"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Initialize chart when report data is available
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (!empty($chartData)): ?>
                const ctx = document.getElementById('reportChart').getContext('2d');

                // Determine chart type based on report type
                const reportType = '<?= $_POST['report_type'] ?? '' ?>';
                let chartType = 'bar';
                let options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 15, 26, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: 'rgba(255, 255, 255, 0.8)',
                            borderColor: 'rgba(108, 77, 246, 0.5)',
                            borderWidth: 1,
                            padding: 12
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(224, 224, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(224, 224, 255, 0.7)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(224, 224, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(224, 224, 255, 0.7)'
                            }
                        }
                    }
                };

                // Special options for system usage report (line chart)
                if (reportType === 'system_usage') {
                    chartType = 'line';
                    options.scales.x.ticks = {
                        color: 'rgba(224, 224, 255, 0.7)',
                        maxRotation: 45,
                        minRotation: 45
                    };
                }

                // Create the chart
                new Chart(ctx, {
                    type: chartType,
                    data: <?= json_encode($chartData) ?>,
                    options: options
                });
            <?php endif; ?>

            // Show/hide department filter based on report type
            document.getElementById('report_type').addEventListener('change', function () {
                const departmentCol = document.getElementById('department-col');
                departmentCol.style.display = this.value === 'task_performance' ? 'block' : 'none';
            });
        });

        function filterDocuments() {
            const deptId = document.getElementById('doc_dept').value;
            const url = new URL(window.location.href);

            if (deptId) {
                url.searchParams.set('doc_dept', deptId);
            } else {
                url.searchParams.delete('doc_dept');
            }

            window.location.href = url.toString();
        }

        // Export to CSV
        function exportToCSV() {
            const reportType = '<?= $_POST['report_type'] ?? '' ?>';
            let reportTitle = 'Report';

            switch (reportType) {
                case 'user_activity': reportTitle = 'User_Activity_Report'; break;
                case 'task_performance': reportTitle = 'Task_Performance_Report'; break;
                case 'department_metrics': reportTitle = 'Department_Metrics_Report'; break;
                case 'system_usage': reportTitle = 'System_Usage_Report'; break;
            }

            const dateRange = '<?= $_POST['start_date'] ?? '' ?>_to_<?= $_POST['end_date'] ?? '' ?>';
            const filename = `${reportTitle}_${dateRange}.csv`;

            // Get table data
            const table = document.querySelector('.data-table');
            const rows = table.querySelectorAll('tr');
            let csvContent = '';

            // Add headers
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.textContent);
            });
            csvContent += headers.join(',') + '\n';

            // Add rows
            rows.forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach(td => {
                    // Remove any HTML tags from the cell content
                    rowData.push(td.textContent.replace(/,/g, ';').replace(/\n/g, ' '));
                });
                if (rowData.length > 0) {
                    csvContent += rowData.join(',') + '\n';
                }
            });

            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Export to PDF
        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();

            const reportType = '<?= $_POST['report_type'] ?? '' ?>';
            let reportTitle = 'Report';

            switch (reportType) {
                case 'user_activity': reportTitle = 'User Activity Report'; break;
                case 'task_performance': reportTitle = 'Task Performance Report'; break;
                case 'department_metrics': reportTitle = 'Department Metrics Report'; break;
                case 'system_usage': reportTitle = 'System Usage Report'; break;
            }

            const dateRange = '<?= $_POST['start_date'] ?? '' ?> to <?= $_POST['end_date'] ?? '' ?>';

            // Add title and date range
            doc.setFontSize(18);
            doc.setTextColor(108, 77, 246);
            doc.text(reportTitle, 14, 20);
            doc.setFontSize(12);
            doc.setTextColor(0, 0, 0);
            doc.text(`Date Range: ${dateRange}`, 14, 28);

            // Add chart image if available
            const chartCanvas = document.getElementById('reportChart');
            if (chartCanvas) {
                const chartImage = chartCanvas.toDataURL('image/png');
                doc.addImage(chartImage, 'PNG', 14, 40, 180, 100);
            }

            // Add table data
            const table = document.querySelector('.data-table');
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.textContent);
            });

            const rows = [];
            table.querySelectorAll('tr').forEach(tr => {
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => {
                    rowData.push(td.textContent);
                });
                if (rowData.length > 0) {
                    rows.push(rowData);
                }
            });

            // Start table below chart
            const startY = chartCanvas ? 150 : 40;

            doc.autoTable({
                head: [headers],
                body: rows,
                startY: startY,
                theme: 'grid',
                headStyles: {
                    fillColor: [108, 77, 246],
                    textColor: 255
                },
                alternateRowStyles: {
                    fillColor: [240, 240, 255]
                },
                styles: {
                    cellPadding: 3,
                    fontSize: 8,
                    overflow: 'linebreak'
                },
                margin: { top: startY }
            });

            // Save the PDF
            doc.save(`${reportTitle.replace(/ /g, '_')}_${dateRange.replace(/ /g, '_')}.pdf`);
        }

        // Show/hide department filter based on report type selection
        document.getElementById('report_type').addEventListener('change', function () {
            const departmentCol = document.getElementById('department-col');
            if (this.value === 'task_performance') {
                departmentCol.style.display = 'block';
            } else {
                departmentCol.style.display = 'none';
            }
        });

        // Document modal functions
        function showDocumentInfo(doc) {
            document.getElementById('modal-doc-title').textContent = doc.name;

            let html = `
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
            <i class="fas fa-file" style="font-size: 3rem; color: var(--stellar-blue);"></i>
            <div>
                <h3 style="margin: 0 0 0.5rem 0;">${doc.name}</h3>
                <p style="margin: 0; opacity: 0.8;">${Math.round(doc.file_size / 1024)} KB - ${doc.file_type}</p>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
            <div>
                <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Uploaded By</h4>
                <p style="margin: 0;">${doc.uploaded_by_name}</p>
            </div>
            
            ${doc.department_name ? `
            <div>
                <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Department</h4>
                <p style="margin: 0;">${doc.department_name}</p>
            </div>
            ` : ''}
            
            <div>
                <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Upload Date</h4>
                <p style="margin: 0;">${new Date(doc.uploaded_at).toLocaleDateString()}</p>
            </div>
            
            <div>
                <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Downloads</h4>
                <p style="margin: 0;">${doc.downloads}</p>
            </div>
        </div>
        
        <div style="display: flex; gap: 1rem;">
            <a href="reports.php?download=${doc.document_id}" class="btn btn-primary" style="flex: 1; text-align: center;">
                <i class="fas fa-download"></i> Download
            </a>
            <button class="btn btn-danger" style="flex: 1;" 
                onclick="confirmDelete(${doc.document_id}, '${doc.name.replace(/'/g, "\\'")}')">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
    `;

            document.getElementById('modal-doc-content').innerHTML = html;
            document.getElementById('document-modal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('document-modal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function (event) {
            if (event.target === document.getElementById('document-modal')) {
                closeModal();
            }
        });

        // File name display
        document.getElementById('document').addEventListener('change', function (e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file selected';
            document.getElementById('file-name-display').textContent = fileName;
        });

        // Document deletion confirmation
        function confirmDelete(docId, docName) {
            if (confirm(`Are you sure you want to delete "${docName}"? This action cannot be undone.`)) {
                window.location.href = `reports.php?delete=${docId}`;
            }
        }
    </script>
</body>

</html>