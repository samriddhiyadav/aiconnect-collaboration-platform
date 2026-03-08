<?php
// Employee Profile - Complete Standalone Version
// File: src/employee/profile.php

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

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $job_title = $_POST['job_title'];
    $phone = $_POST['phone'];

    $update_stmt = $pdo->prepare("UPDATE users SET full_name = ?, job_title = ?, phone = ? WHERE user_id = ?");
    $update_stmt->execute([$full_name, $job_title, $phone, $user_id]);

    // Update session data
    $_SESSION['full_name'] = $full_name;

    // Log activity
    $activity_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
    $activity_stmt->execute([
        $user_id,
        'profile_update',
        "Updated profile information"
    ]);

    // Refresh user data
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Success message
    $success_message = "Profile updated successfully!";
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Verify current password
    if (!password_verify($current_password, $user['password_hash'])) {
        $password_error = "Current password is incorrect";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match";
    } elseif (strlen($new_password) < 8) {
        $password_error = "Password must be at least 8 characters";
    } else {
        // Update password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ?, last_password_change = NOW() WHERE user_id = ?");
        $update_stmt->execute([$new_hash, $user_id]);

        // Log activity
        $activity_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
        $activity_stmt->execute([
            $user_id,
            'password_change',
            "Changed account password"
        ]);

        // Success message
        $password_success = "Password changed successfully!";
    }
}

// Get department info
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
    <title>TeamSphere | My Profile</title>
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

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            color: white;
            flex-shrink: 0;
        }

        .profile-info h1 {
            margin: 0 0 0.5rem 0;
            background: none;
            color: var(--neon-white);
        }

        .profile-info p {
            margin: 0;
            opacity: 0.8;
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

        .profile-section {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .profile-section h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
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
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 8px;
            color: var(--neon-white);
            font-family: var(--font-primary);
            transition: var(--transition-normal);
        }

        .form-control:focus {
            border-color: var(--cosmic-pink);
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.3);
            background: rgba(15, 15, 26, 0.8);
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
            text-align: center;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .stat-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
            opacity: 0.8;
        }

        .stat-card p {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .alert {
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

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
        }

        .btn-outline:hover {
            background: rgba(224, 224, 255, 0.05);
            border-color: var(--nebula-purple);
            color: var(--neon-white);
        }

        /* For the scrollable quick links on mobile */
        @media (max-width: 768px) {
            .quick-links {
                -webkit-overflow-scrolling: touch;
            }

            .quick-links div {
                padding-bottom: 1rem;
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
                <a href="tasks.php" class="nav-link">
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
                <a href="profile.php" class="nav-link active">
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
            <div class="profile-header">
                <div class="avatar-large">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <h1><?= htmlspecialchars($user['full_name']) ?></h1>
                    <p><?= htmlspecialchars($user['job_title']) ?></p>
                    <p><?= htmlspecialchars($department['name'] ?? 'No department assigned') ?></p>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Pending Tasks</h3>
                    <p><?= $pending_tasks ?></p>
                </div>
                <div class="stat-card">
                    <h3>Completed Tasks</h3>
                    <p>
                        <?php
                        $completed_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed'");
                        $completed_stmt->execute([$user_id]);
                        echo $completed_stmt->fetchColumn();
                        ?>
                    </p>
                </div>
                <div class="stat-card">
                    <h3>Team Members</h3>
                    <p>
                        <?php
                        if ($department) {
                            $team_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_departments WHERE department_id = ?");
                            $team_stmt->execute([$department['department_id']]);
                            echo $team_stmt->fetchColumn();
                        } else {
                            echo '0';
                        }
                        ?>
                    </p>
                </div>
                <div class="stat-card">
                    <h3>Days Active</h3>
                    <p>
                        <?php
                        $join_date = new DateTime($user['join_date']);
                        $today = new DateTime();
                        echo $today->diff($join_date)->days;
                        ?>
                    </p>
                </div>
            </div>

            <!-- Add this right after the stats-grid div -->
            <div class="quick-links" style="margin-bottom: 2rem;">
                <div style="display: flex; gap: 1rem; overflow-x: auto; padding-bottom: 0.5rem;">
                    <a href="#personal-info" class="btn btn-outline" style="white-space: nowrap;">
                        <i class="fas fa-user-circle"></i> Personal Info
                    </a>
                    <a href="#change-password" class="btn btn-outline" style="white-space: nowrap;">
                        <i class="fas fa-lock"></i> Change Password
                    </a>
                    <a href="#account-info" class="btn btn-outline" style="white-space: nowrap;">
                        <i class="fas fa-info-circle"></i> Account Info
                    </a>
                </div>
            </div>

            <!-- Profile Information Section -->
            <div class="profile-section">
                <h2 id="personal-info"><i class="fas fa-user-circle" style="color: var(--nebula-purple);"></i> Personal Information</h2>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <div class="alert-content"><?= $success_message ?></div>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" class="form-control" id="username"
                                value="<?= htmlspecialchars($user['username']) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control" id="email"
                                value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="job_title">Job Title</label>
                            <input type="text" class="form-control" id="job_title" name="job_title"
                                value="<?= htmlspecialchars($user['job_title']) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                value="<?= htmlspecialchars($user['phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="department">Department</label>
                            <input type="text" class="form-control" id="department"
                                value="<?= htmlspecialchars($department['name'] ?? 'Not assigned') ?>" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="join_date">Join Date</label>
                        <input type="text" class="form-control" id="join_date"
                            value="<?= date('F j, Y', strtotime($user['join_date'])) ?>" readonly>
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>

            <!-- Change Password Section -->
            <div class="profile-section">
                <h2 id="change-password"><i class="fas fa-lock" style="color: var(--stellar-blue);"></i> Change Password</h2>

                <?php if (isset($password_error)): ?>
                    <div class="alert alert-error">
                        <div class="alert-content"><?= $password_error ?></div>
                    </div>
                <?php elseif (isset($password_success)): ?>
                    <div class="alert alert-success">
                        <div class="alert-content"><?= $password_success ?></div>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required>
                        </div>
                    </div>

                    <div class="form-group">
                        <small style="opacity: 0.7;">Password must be at least 8 characters long</small>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>

            <!-- Account Information Section -->
            <div class="profile-section">
                <h2 id="account-info"><i class="fas fa-info-circle" style="color: var(--galaxy-gold);"></i> Account Information</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">User ID</label>
                        <input type="text" class="form-control" value="<?= $user['user_id'] ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" readonly>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Last Login</label>
                        <input type="text" class="form-control"
                            value="<?= $user['last_login'] ? date('F j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?>"
                            readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Password Change</label>
                        <input type="text" class="form-control"
                            value="<?= $user['last_password_change'] ? date('F j, Y', strtotime($user['last_password_change'])) : 'Never' ?>"
                            readonly>
                    </div>
                </div>
            </div>
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
    </script>
</body>

</html>