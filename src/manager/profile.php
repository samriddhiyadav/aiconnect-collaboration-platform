<?php
// Manager Profile - Complete Standalone Version
// File: src/manager/profile.php

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

// Get pending tasks count
$tasks_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$tasks_stmt->execute([$user_id]);
$pending_tasks = $tasks_stmt->fetchColumn();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $job_title = $_POST['job_title'];

        // Basic validation
        if (empty($full_name) || empty($email)) {
            $error = "Full name and email are required";
        } else {
            try {
                $update_stmt = $pdo->prepare(
                    "UPDATE users SET 
                    full_name = ?, 
                    email = ?, 
                    phone = ?, 
                    job_title = ?
                    WHERE user_id = ?"
                );
                $update_stmt->execute([$full_name, $email, $phone, $job_title, $user_id]);

                // Handle password change if provided
                if (!empty($_POST['new_password'])) {
                    if ($_POST['new_password'] === $_POST['confirm_password']) {
                        $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                            ->execute([$hashed_password, $user_id]);
                    } else {
                        $error = "Passwords do not match";
                    }
                }

                // Handle avatar upload
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatar = $_FILES['avatar'];
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 2 * 1024 * 1024; // 2MB

                    if (in_array($avatar['type'], $allowed_types) && $avatar['size'] <= $max_size) {
                        $upload_dir = '../../uploads/avatars/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $file_ext = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                        $filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_ext;
                        $destination = $upload_dir . $filename;

                        if (move_uploaded_file($avatar['tmp_name'], $destination)) {
                            // Delete old avatar if exists
                            if (!empty($user['avatar'])) {
                                $old_avatar = $upload_dir . basename($user['avatar']);
                                if (file_exists($old_avatar)) {
                                    unlink($old_avatar);
                                }
                            }

                            $pdo->prepare("UPDATE users SET avatar = ? WHERE user_id = ?")
                                ->execute([$filename, $user_id]);
                        }
                    } else {
                        $error = "Invalid avatar file. Only JPG, PNG or GIF up to 2MB allowed.";
                    }
                }

                $success = "Profile updated successfully";
                // Refresh user data
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Manager Profile</title>
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

        .profile-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 3rem;
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-info h1 {
            margin: 0;
            font-size: 2rem;
        }

        .profile-info p {
            margin: 0.5rem 0;
            opacity: 0.8;
        }

        .profile-role {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(108, 77, 246, 0.2);
            border-radius: 20px;
            color: var(--nebula-purple);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .profile-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .profile-card h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .profile-card h2 i {
            color: var(--nebula-purple);
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
            min-height: 120px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--nebula-purple);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 77, 246, 0.3);
        }

        .btn-secondary {
            background: rgba(224, 224, 255, 0.1);
            color: white;
        }

        .btn-secondary:hover {
            background: rgba(224, 224, 255, 0.2);
            box-shadow: none;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.8);
        }

        .nav-link:hover {
            background: rgba(108, 77, 246, 0.2);
            color: white;
        }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(108, 77, 246, 0.3), rgba(74, 144, 226, 0.3));
            border-left: 3px solid var(--cosmic-pink);
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
            margin-left: auto;
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            background: rgba(15, 15, 26, 0.8);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid var(--nebula-purple);
        }

        .avatar-upload input {
            display: none;
        }

        .avatar-upload i {
            font-size: 1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            border-left: 3px solid #4CAF50;
            color: #4CAF50;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.2);
            border-left: 3px solid #F44336;
            color: #F44336;
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
                text-align: center;
            }

            .form-row {
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
                <a href="team.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>My Team</span>
                    <span class="task-badge"><?= $team_count ?></span>
                </a>
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
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
            <div class="profile-container">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                            <div class="avatar">
                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                            </div>
                        <?php else: ?>
                            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                        <?php endif; ?>
                        <label class="avatar-upload" title="Change Avatar">
                            <input type="file" name="avatar" id="avatarInput" accept="image/*">
                            <i class="fas fa-camera"></i>
                        </label>
                    </div>
                    <div class="profile-info">
                        <h1><?= htmlspecialchars($user['full_name']) ?></h1>
                        <p><?= htmlspecialchars($user['job_title']) ?></p>
                        <span class="profile-role">Manager</span>
                        <p style="margin-top: 0.5rem;">
                            <i class="fas fa-building" style="margin-right: 0.5rem;"></i>
                            <?= htmlspecialchars($department['name'] ?? 'No Department') ?>
                        </p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="profile-card">
                        <h2><i class="fas fa-user-edit"></i> Personal Information</h2>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name"
                                    value="<?= htmlspecialchars($user['full_name']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email"
                                    value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone"
                                    value="<?= htmlspecialchars($user['phone']) ?>">
                            </div>

                            <div class="form-group">
                                <label for="job_title">Job Title</label>
                                <input type="text" id="job_title" name="job_title"
                                    value="<?= htmlspecialchars($user['job_title']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="profile-card">
                        <h2><i class="fas fa-lock"></i> Security</h2>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password">
                                <small style="opacity: 0.7;">Leave blank to keep current password</small>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password">
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                        <button type="reset" class="btn btn-secondary">Reset</button>
                        <button type="submit" name="update_profile" class="btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Preview avatar before upload
        document.getElementById('avatarInput').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (event) {
                    const avatar = document.querySelector('.profile-avatar');
                    avatar.innerHTML = ''; // Clear existing content

                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.alt = 'Profile Avatar Preview';

                    const uploadLabel = document.createElement('label');
                    uploadLabel.className = 'avatar-upload';
                    uploadLabel.title = 'Change Avatar';
                    uploadLabel.innerHTML = `
                        <input type="file" name="avatar" id="avatarInput" accept="image/*">
                        <i class="fas fa-camera"></i>
                    `;
                    uploadLabel.querySelector('input').addEventListener('change', arguments.callee);

                    avatar.appendChild(img);
                    avatar.appendChild(uploadLabel);
                }
                reader.readAsDataURL(file);
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function (e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }

            if (newPassword && newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }

            return true;
        });
    </script>
</body>

</html>