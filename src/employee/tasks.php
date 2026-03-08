<?php
// Employee Tasks - Complete Standalone Version
// File: src/employee/tasks.php

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

// Handle task status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $task_id = $_POST['task_id'];
    $new_status = $_POST['new_status'];

    $update_stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE task_id = ? AND assigned_to = ?");
    $update_stmt->execute([$new_status, $task_id, $user_id]);

    // Log activity
    $activity_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
    $activity_stmt->execute([
        $user_id,
        'task_update',
        "Changed status of task #$task_id to $new_status"
    ]);

    header("Location: tasks.php");
    exit();
}

// Get tasks with filters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';

$query = "SELECT t.*, u.full_name as created_by_name 
          FROM tasks t 
          JOIN users u ON t.created_by = u.user_id 
          WHERE t.assigned_to = ?";

$params = [$user_id];

if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority_filter;
}

$query .= " ORDER BY 
            CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END,
            t.deadline ASC,
            CASE t.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END";

$tasks_stmt = $pdo->prepare($query);
$tasks_stmt->execute($params);
$tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department info for task creation
$dept_stmt = $pdo->prepare(
    "SELECT d.* FROM departments d
    JOIN user_departments ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ? AND ud.is_primary = 1"
);
$dept_stmt->execute([$user_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | My Tasks</title>
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

        .task-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
        }

        .task-card:hover {
            border-color: var(--nebula-purple);
            transform: translateY(-3px);
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

        .priority-critical {
            color: #ff4d4d;
            border-left: 3px solid #ff4d4d;
        }

        .priority-high {
            color: #ff9e4d;
            border-left: 3px solid #ff9e4d;
        }

        .priority-medium {
            color: var(--galaxy-gold);
            border-left: 3px solid var(--galaxy-gold);
        }

        .priority-low {
            color: #4dff4d;
            border-left: 3px solid #4dff4d;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #FFC107;
        }

        .status-in_progress {
            background: rgba(33, 150, 243, 0.2);
            color: #2196F3;
        }

        .status-completed {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
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
            opacity: 0.8;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .empty-state {
            padding: 3rem;
            text-align: center;
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--nebula-purple);
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
                <a href="tasks.php" class="nav-link active">
                    <i class="fas fa-tasks"></i>
                    <span>Tasks</span>
                    <?php if ($pending_tasks > 0): ?>
                        <span class="task-badge"
                            style="margin-left: auto; background: var(--cosmic-pink); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;"><?= $pending_tasks ?></span>
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
                <h1 style="margin: 0;">My Tasks</h1>
            </div>

            <!-- Task Filters -->
            <div class="filters">
                <div class="filter-group">
                    <span class="filter-label">Status:</span>
                    <select id="status-filter" class="form-control"
                        style="background: rgba(15, 15, 26, 0.5); border: 1px solid rgba(224, 224, 255, 0.1); color: var(--neon-white); padding: 0.5rem; border-radius: 6px;"
                        onchange="updateFilters()">
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
                    <select id="priority-filter" class="form-control"
                        style="background: rgba(15, 15, 26, 0.5); border: 1px solid rgba(224, 224, 255, 0.1); color: var(--neon-white); padding: 0.5rem; border-radius: 6px;"
                        onchange="updateFilters()">
                        <option value="all" <?= $priority_filter === 'all' ? 'selected' : '' ?>>All Priorities</option>
                        <option value="critical" <?= $priority_filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                        <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                        <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                    </select>
                </div>
                <button class="btn btn-primary" style="margin-left: auto;" onclick="resetFilters()">
                    <i class="fas fa-sync-alt"></i> Reset Filters
                </button>
            </div>

            <!-- Task List -->
            <?php if (count($tasks) > 0): ?>
                <div style="display: grid; gap: 1rem;">
                    <?php foreach ($tasks as $task): ?>
                        <div class="task-card <?= 'priority-' . $task['priority'] ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                                <div>
                                    <h3 style="margin: 0 0 0.5rem 0;"><?= htmlspecialchars($task['title']) ?></h3>
                                    <p style="margin: 0; opacity: 0.9;"><?= htmlspecialchars($task['description']) ?></p>
                                </div>
                                <span class="status-badge <?= 'status-' . $task['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                </span>
                            </div>

                            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem; font-size: 0.9rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-user" style="opacity: 0.7;"></i>
                                    <span><?= htmlspecialchars($task['created_by_name']) ?></span>
                                </div>
                                <?php if ($task['deadline']): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-calendar-alt" style="opacity: 0.7;"></i>
                                        <span><?= date('M j, Y', strtotime($task['deadline'])) ?></span>
                                        <?php if (strtotime($task['deadline']) < time() && $task['status'] !== 'completed'): ?>
                                            <span style="color: var(--cosmic-pink);">(Overdue)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-bolt" style="opacity: 0.7;"></i>
                                    <span><?= ucfirst($task['priority']) ?></span>
                                </div>
                            </div>

                            <div class="task-actions">
                                <?php if ($task['status'] !== 'completed'): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                        <input type="hidden" name="new_status" value="in_progress">
                                        <button type="submit" name="update_status" class="btn btn-secondary"
                                            style="padding: 0.5rem 1rem;">
                                            <i class="fas fa-play"></i> Start
                                        </button>
                                    </form>

                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                        <input type="hidden" name="new_status" value="completed">
                                        <button type="submit" name="update_status" class="btn btn-primary"
                                            style="padding: 0.5rem 1rem;">
                                            <i class="fas fa-check"></i> Complete
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button class="btn btn-secondary" style="padding: 0.5rem 1rem;"
                                    onclick="showTaskDetails(<?= $task['task_id'] ?>)">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-tasks"></i>
                    <h3>No tasks found</h3>
                    <p>You don't have any tasks matching your current filters</p>
                    <button class="btn btn-primary" onclick="resetFilters()">
                        Reset Filters
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div id="task-modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 1000; align-items: center; justify-content: center;">
        <div
            style="background: var(--deep-space); border-radius: 12px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--nebula-purple);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="modal-task-title" style="margin: 0;"></h2>
                <button onclick="closeModal()"
                    style="background: none; border: none; color: var(--neon-white); font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="modal-task-content"></div>
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

        // Update filters
        function updateFilters() {
            const status = document.getElementById('status-filter').value;
            const priority = document.getElementById('priority-filter').value;
            window.location.href = `tasks.php?status=${status}&priority=${priority}`;
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'tasks.php';
        }

        // Show task details modal
        function showTaskDetails(taskId) {
            // In a real implementation, you would fetch task details via AJAX
            // For this example, we'll simulate it with the existing data
            const task = <?= json_encode(array_column($tasks, null, 'task_id')) ?>[taskId];

            if (task) {
                document.getElementById('modal-task-title').textContent = task.title;

                let html = `
                    <div style="margin-bottom: 1.5rem;">
                        <p>${task.description || 'No description provided'}</p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Status</h4>
                            <span class="status-badge status-${task.status}" style="display: inline-block;">
                                ${task.status.replace('_', ' ').toUpperCase()}
                            </span>
                        </div>
                        
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Priority</h4>
                            <span style="color: ${task.priority === 'critical' ? '#ff4d4d' :
                        task.priority === 'high' ? '#ff9e4d' :
                            task.priority === 'medium' ? 'var(--galaxy-gold)' : '#4dff4d'
                    }">
                                ${task.priority.toUpperCase()}
                            </span>
                        </div>
                        
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Assigned By</h4>
                            <p style="margin: 0;">${task.created_by_name}</p>
                        </div>
                        
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Deadline</h4>
                            <p style="margin: 0;">${task.deadline ? new Date(task.deadline).toLocaleDateString() : 'No deadline'}</p>
                        </div>
                    </div>
                `;

                document.getElementById('modal-task-content').innerHTML = html;
                document.getElementById('task-modal').style.display = 'flex';
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('task-modal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function (event) {
            if (event.target === document.getElementById('task-modal')) {
                closeModal();
            }
        });
    </script>
</body>

</html>