<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /teamsphere/src/auth/auth.php');
    exit;
}

// Verify admin role
if ($_SESSION['role'] !== 'admin') {
    header('Location: /teamsphere/dashboard/dashboard.php');
    exit;
}

// Define absolute root path
define('ROOT_PATH', realpath(dirname(__DIR__) . '/..'));
require_once ROOT_PATH . '/includes/db_connect.php';
require_once ROOT_PATH . '/includes/functions.php';

try {
    $pdo = Database::getInstance();

    // Get dashboard statistics
    $stats = [];

    // User count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = $stmt->fetchColumn();

    // Active tasks
    $stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed'");
    $stats['active_tasks'] = $stmt->fetchColumn();

    // Departments
    $stmt = $pdo->query("SELECT COUNT(*) FROM departments");
    $stats['departments'] = $stmt->fetchColumn();

    // Recent activity
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.avatar 
        FROM activity_log a
        JOIN users u ON a.user_id = u.user_id
        ORDER BY timestamp DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent users
    $stmt = $pdo->query("
        SELECT user_id, username, full_name, avatar, role, join_date 
        FROM users 
        ORDER BY join_date DESC 
        LIMIT 5
    ");
    $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Task status distribution
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM tasks 
        GROUP BY status
    ");
    $task_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Department distribution
    $stmt = $pdo->query("
        SELECT d.name, COUNT(ud.user_id) as user_count
        FROM departments d
        LEFT JOIN user_departments ud ON d.department_id = ud.department_id
        GROUP BY d.department_id
        ORDER BY user_count DESC
        LIMIT 5
    ");
    $department_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log admin dashboard access
    log_activity($_SESSION['user_id'], 'admin_dashboard', 'Accessed admin dashboard');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("An error occurred while loading dashboard data.");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-container {
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

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
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

        .stats-grid {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: none;
            /* Hide scrollbar for Firefox */
        }

        .stats-grid::-webkit-scrollbar {
            display: none;
            /* Hide scrollbar for Chrome/Safari */
        }

        .stat-card {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            transition: var(--transition-normal);
            position: relative;
            overflow: hidden;
            min-width: 225px;
            flex: 1;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(108, 77, 246, 0.2);
            border-color: rgba(108, 77, 246, 0.5);
        }

        .stat-icon {
            font-size: 1.75rem;
            margin-bottom: 1rem;
            color: var(--cosmic-pink);
        }

        .stat-title {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .panel {
            background: rgba(15, 15, 26, 0.6);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid var(--quantum-foam);
            margin-bottom: 1.5rem;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .activity-details {
            flex: 1;
        }

        .activity-user {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .activity-action {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .activity-time {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .user-item:last-child {
            border-bottom: none;
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

        .user-join-date {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        /* Add this to your style section */
        .chart-container {
            height: 200px;
            width: 100%;
            position: relative;
            margin: 1rem 0;
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 1rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .dept-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--quantum-foam);
        }

        .dept-item:last-child {
            border-bottom: none;
        }

        .dept-name {
            font-weight: 500;
        }

        .dept-count {
            background: rgba(108, 77, 246, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.8rem;
            font-weight: 600;
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
            max-width: 800px;
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

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-astronaut"></i>
                </div>
                <div class="sidebar-title">TeamSphere</div>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link active">
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

            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <h1>Admin Dashboard</h1>
                <div class="user-profile">
                    <div class="avatar"
                        style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                        <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                    </div>
                    <div class="username"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                    <div class="role-badge"><?= htmlspecialchars($_SESSION['role']) ?></div>
                </div>

            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-title">Total Users</div>
                    <div class="stat-value"><?= $stats['total_users'] ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-title">Active Tasks</div>
                    <div class="stat-value"><?= $stats['active_tasks'] ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="stat-title">Departments</div>
                    <div class="stat-value"><?= $stats['departments'] ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-title">Activity Today</div>
                    <div class="stat-value"><?= rand(15, 50) ?></div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Recent Activity Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Recent Activity</h2>
                            <button class="btn btn-secondary btn-sm" onclick="openModal('activity-modal')">View
                                All</button>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($recent_activity as $activity): ?>
                                <div class="activity-item">
                                    <div class="avatar"
                                        style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                                        <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="activity-details">
                                        <div class="activity-user"><?= htmlspecialchars($activity['username']) ?></div>
                                        <div class="activity-action"><?= htmlspecialchars($activity['action']) ?></div>
                                        <div class="activity-time"><?= formatTime($activity['timestamp']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Task Status Chart Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Task Status Distribution</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="taskChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <?php foreach ($task_status as $status): ?>
                                <div class="legend-item">
                                    <div class="legend-color"
                                        style="background-color: <?= getStatusColor($status['status']) ?>;"></div>
                                    <span><?= ucfirst(str_replace('_', ' ', $status['status'])) ?>
                                        (<?= $status['count'] ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Recent Users Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Recent Users</h2>
                            <button class="btn btn-secondary btn-sm" onclick="openModal('users-modal')">View
                                All</button>
                        </div>
                        <div class="user-list">
                            <?php foreach ($recent_users as $user): ?>
                                <div class="user-item">
                                    <div class="avatar"
                                        style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                                        <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="user-info">
                                        <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                                        <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
                                    </div>
                                    <div class="user-join-date"><?= date('M j', strtotime($user['join_date'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Department Distribution Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Top Departments</h2>
                            <button class="btn btn-secondary btn-sm" onclick="openModal('dept-modal')">View All</button>
                        </div>
                        <div class="dept-list">
                            <?php foreach ($department_dist as $dept): ?>
                                <div class="dept-item">
                                    <div class="dept-name"><?= htmlspecialchars($dept['name']) ?></div>
                                    <div class="dept-count"><?= $dept['user_count'] ?> users</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Modal -->
    <div class="modal" id="activity-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('activity-modal')">&times;</button>
            <h2 class="modal-title">All Activity</h2>
            <div class="activity-list">
                <?php
                $stmt = $pdo->query("
                    SELECT a.*, u.username, u.avatar 
                    FROM activity_log a
                    JOIN users u ON a.user_id = u.user_id
                    ORDER BY timestamp DESC
                    LIMIT 50
                ");
                $all_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_activity as $activity): ?>
                    <div class="activity-item">
                        <div class="avatar"
                            style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                            <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                        </div>
                        <div class="activity-details">
                            <div class="activity-user"><?= htmlspecialchars($activity['username']) ?></div>
                            <div class="activity-action"><?= htmlspecialchars($activity['action']) ?></div>
                            <div class="activity-time"><?= formatTime($activity['timestamp']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Users Modal -->
    <div class="modal" id="users-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('users-modal')">&times;</button>
            <h2 class="modal-title">All Users</h2>
            <div class="user-list">
                <?php
                $stmt = $pdo->query("
                    SELECT user_id, username, full_name, avatar, role, join_date 
                    FROM users 
                    ORDER BY join_date DESC
                ");
                $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_users as $user): ?>
                    <div class="user-item">
                        <div class="avatar"
                            style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                            <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                            <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
                        </div>
                        <div class="user-join-date"><?= date('M j, Y', strtotime($user['join_date'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Departments Modal -->
    <div class="modal" id="dept-modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('dept-modal')">&times;</button>
            <h2 class="modal-title">All Departments</h2>
            <div class="dept-list">
                <?php
                $stmt = $pdo->query("
                    SELECT d.name, COUNT(ud.user_id) as user_count
                    FROM departments d
                    LEFT JOIN user_departments ud ON d.department_id = ud.department_id
                    GROUP BY d.department_id
                    ORDER BY user_count DESC
                ");
                $all_depts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_depts as $dept): ?>
                    <div class="dept-item">
                        <div class="dept-name"><?= htmlspecialchars($dept['name']) ?></div>
                        <div class="dept-count"><?= $dept['user_count'] ?> users</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('taskChart').getContext('2d');

            // Define chart colors with better contrast
            const chartColors = {
                pending: '#4A90E2',    // Blue
                in_progress: '#FFC154', // Yellow
                completed: '#47B881',   // Green
                archived: '#6C4DF6'     // Purple
            };

            const taskChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($task_status as $status): ?>
                        '<?= ucfirst(str_replace('_', ' ', $status['status'])) ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($task_status as $status): ?>
                            <?= $status['count'] ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            <?php foreach ($task_status as $status): ?>
                            chartColors['<?= $status['status'] ?>'],
                            <?php endforeach; ?>
                        ],
                        borderColor: 'rgba(15, 15, 26, 0.9)',
                        borderWidth: 2,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            display: false // We'll use our custom legend
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 15, 26, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: 'rgba(255, 255, 255, 0.8)',
                            borderColor: 'rgba(108, 77, 246, 0.5)',
                            borderWidth: 1,
                            padding: 12,
                            callbacks: {
                                label: function (context) {
                                    return `${context.label}: ${context.raw}`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
        });
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
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
    </script>
</body>

</html>

<?php
// Helper functions
function formatTime($timestamp)
{
    $now = new DateTime();
    $time = new DateTime($timestamp);
    $interval = $now->diff($time);

    if ($interval->y > 0)
        return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    if ($interval->m > 0)
        return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    if ($interval->d > 0)
        return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    if ($interval->h > 0)
        return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    if ($interval->i > 0)
        return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

function getStatusColor($status)
{
    switch ($status) {
        case 'pending':
            return '#4A90E2'; // Stellar Blue
        case 'in_progress':
            return '#FFC154'; // Galaxy Gold
        case 'completed':
            return '#47B881'; // Success Green
        case 'archived':
            return '#6C4DF6'; // Nebula Purple
        default:
            return '#E0E0FF'; // Neon White
    }
}
?>