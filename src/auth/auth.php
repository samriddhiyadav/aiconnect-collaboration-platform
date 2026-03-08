<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define root path
define('ROOT_PATH', realpath(dirname(__DIR__)));

// Require database connection
require_once ROOT_PATH . '/../includes/db_connect.php';

// Get database connection
try {
    $pdo = Database::getInstance();
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'login':
            handleLogin($pdo);
            break;
        case 'register':
            handleRegister($pdo);
            break;
        case 'forgot_password':
            handleForgotPassword($pdo);
            break;
        case 'reset_password':
            handleResetPassword($pdo);
            break;
    }
}

// Handle GET actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        handleLogout();
    } elseif ($_GET['action'] === 'reset_password' && isset($_GET['token'])) {
        $form = 'reset_password';
    }
}

// Activity logging function
function logActivity($pdo, $user_id, $action, $details = '')
{
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) 
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Activity log failed: " . $e->getMessage());
        return false;
    }
}

// Handle login
function handleLogin($pdo)
{
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (empty($email) || empty($password)) {
        $_SESSION['error'] = 'Email and password are required';
        header('Location: auth.php?form=login');
        exit;
    }

    try {
        // Get user by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['error'] = 'Invalid email or password';
            header('Location: auth.php?form=login');
            exit;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $_SESSION['error'] = 'Invalid email or password';
            header('Location: auth.php?form=login');
            exit;
        }

        // Check if user is active
        if ($user['status'] !== 'active') {
            $_SESSION['error'] = 'Your account is not active';
            header('Location: auth.php?form=login');
            exit;
        }

        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['avatar'] = $user['avatar'];

        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);


        $role = strtolower($user['role']);
        $dashboardPath = "../$role/dashboard.php";
        if (!file_exists(ROOT_PATH . "/$role/dashboard.php")) {
            $dashboardPath = "../../dashboard/dashboard.php";
        }
        // Log activity
        logActivity($pdo, $user['user_id'], 'login', 'User logged in');

        $_SESSION['dashboard_path'] = $dashboardPath;
        // Redirect to dashboard
        $_SESSION['login_success'] = 'Login successful! Welcome back, ' . htmlspecialchars($user['full_name']) . '!';

        // Show message on current page before redirect
        header('Location: auth.php?form=login&success=1');
        exit;

    } catch (PDOException $e) {
        error_log("Login failed: " . $e->getMessage());
        $_SESSION['error'] = 'An error occurred during login';
        header('Location: auth.php?form=login');
        exit;
    }
}

// Handle registration
function handleRegister($pdo)
{
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'employee';

    // Store form data in session to repopulate form on error
    $_SESSION['form_data'] = [
        'username' => $username,
        'email' => $email,
        'full_name' => $full_name,
        'role' => $role
    ];

    // Validate input
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($full_name)) {
        $_SESSION['error'] = 'All fields are required';
        header('Location: auth.php?form=register');
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['error'] = 'Passwords do not match';
        header('Location: auth.php?form=register');
        exit;
    }

    if (strlen($password) < 8) {
        $_SESSION['error'] = 'Password must be at least 8 characters';
        header('Location: auth.php?form=register');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email format';
        header('Location: auth.php?form=register');
        exit;
    }

    try {
        // Check if username or email already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'Username or email already exists';
            header('Location: auth.php?form=register');
            exit;
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Create user
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, join_date) 
                              VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$username, $email, $password_hash, $full_name, $role]);

        // Get new user ID
        $user_id = $pdo->lastInsertId();

        // Log activity
        logActivity($pdo, $user_id, 'registration', 'New user registered');

        // Clear form data from session
        unset($_SESSION['form_data']);

        // Auto-login the new user
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['full_name'] = $full_name;
        $_SESSION['role'] = $role;
        $_SESSION['avatar'] = 'default-star.png';

        $role = strtolower($_SESSION['role']); // Get the registered role
        $dashboardPath = "../$role/dashboard.php";

        // Check if the dashboard exists, fallback to default if not
        if (!file_exists(ROOT_PATH . "/$role/dashboard.php")) {
            $dashboardPath = "../dashboard/dashboard.php";
        }

        // Redirect to appropriate dashboard
        $_SESSION['register_success'] = 'Registration successful! Welcome to TeamSphere.';
        header("Location: $dashboardPath");
        exit;

    } catch (PDOException $e) {
        error_log("Registration failed: " . $e->getMessage());
        $_SESSION['error'] = 'An error occurred during registration';
        header('Location: auth.php?form=register');
        exit;
    }
}

// Handle forgot password
function handleForgotPassword($pdo)
{
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $_SESSION['error'] = 'Please enter your email address';
        header('Location: auth.php?form=forgot_password');
        exit;
    }

    try {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['error'] = 'No account found with that email address';
            header('Location: auth.php?form=forgot_password');
            exit;
        }

        // Generate reset token (valid for 1 hour)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store token in database
        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?) 
                              ON DUPLICATE KEY UPDATE token = ?, expires_at = ?");
        $stmt->execute([$user['user_id'], $token, $expires, $token, $expires]);

        // In a real application, you would send an email here
        $reset_link = "https://yourdomain.com/auth.php?action=reset_password&token=$token";

        // Show success message
        $_SESSION['forgot_success'] = "Password reset link has been sent to your email. <a href='$reset_link' class='text-cosmic-pink'>Click here for demo</a>";
        header('Location: auth.php?form=forgot_password&success=1');
        exit;

    } catch (PDOException $e) {
        error_log("Forgot password failed: " . $e->getMessage());
        $_SESSION['error'] = 'An error occurred while processing your request';
        header('Location: auth.php?form=forgot_password');
        exit;
    }
}

// Handle password reset
function handleResetPassword($pdo)
{
    $token = $_GET['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($token)) {
        $_SESSION['error'] = 'Invalid reset token';
        header('Location: auth.php');
        exit;
    }

    try {
        // Validate token
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            $_SESSION['error'] = 'Invalid or expired reset token';
            header('Location: auth.php');
            exit;
        }

        // Validate passwords
        if (empty($password) || empty($confirm_password)) {
            $_SESSION['error'] = 'Please enter and confirm your new password';
            header("Location: auth.php?action=reset_password&token=$token");
            exit;
        }

        if ($password !== $confirm_password) {
            $_SESSION['error'] = 'Passwords do not match';
            header("Location: auth.php?action=reset_password&token=$token");
            exit;
        }

        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters';
            header("Location: auth.php?action=reset_password&token=$token");
            exit;
        }

        // Update password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->execute([$password_hash, $reset['user_id']]);

        // Delete reset token
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);

        // Log activity
        logActivity($pdo, $reset['user_id'], 'password_reset', 'Password reset successfully');

        // Show success message
        $_SESSION['reset_success'] = 'Password updated successfully. You can now login with your new password.';
        header('Location: auth.php?form=login&success=1');
        exit;

    } catch (PDOException $e) {
        error_log("Password reset failed: " . $e->getMessage());
        $_SESSION['error'] = 'An error occurred while resetting your password';
        header("Location: auth.php?action=reset_password&token=$token");
        exit;
    }
}

// Handle logout
function handleLogout()
{
    // Log activity before destroying session
    if (isset($_SESSION['user_id'])) {
        global $pdo;
        logActivity($pdo, $_SESSION['user_id'], 'logout', 'User logged out');
    }

    // Destroy session
    session_unset();
    session_destroy();

    // Redirect to login page
    header('Location: auth.php');
    exit;
}

// Determine which form to show
$form = 'login';
if (isset($_GET['form'])) {
    $form = $_GET['form'];
} elseif (isset($_GET['action']) && $_GET['action'] === 'reset_password' && isset($_GET['token'])) {
    $form = 'reset_password';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Authentication Portal</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .auth-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .auth-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background-color: var(--deep-space);
            background-image:
                radial-gradient(circle at 20% 30%, rgba(108, 77, 246, 0.15) 0%, transparent 25%),
                radial-gradient(circle at 80% 70%, rgba(74, 144, 226, 0.15) 0%, transparent 25%);
            background-size: cover;
            background-attachment: fixed;
        }

        .auth-stars {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-image:
                radial-gradient(1px 1px at 20px 30px, white, rgba(0, 0, 0, 0)),
                radial-gradient(1px 1px at 40px 70px, white, rgba(0, 0, 0, 0)),
                radial-gradient(1px 1px at 90px 40px, white, rgba(0, 0, 0, 0));
            background-size: 100px 100px;
            animation: twinkle 5s infinite alternate;
        }

        .auth-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            width: 100%;
        }

        .auth-form-container {
            width: 100%;
            max-width: 500px;
            min-height: 500px;
            max-height: 90vh;
            background: rgba(15, 15, 26, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 2rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(108, 77, 246, 0.3);
            transform-style: preserve-3d;
            perspective: 1000px;
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--stellar-blue) rgba(15, 15, 26, 0.8);
        }

        .auth-form-container:hover {
            transform: translateY(-5px) rotateX(5deg);
            box-shadow: 0 20px 40px rgba(108, 77, 246, 0.4);
        }

        /* Custom scrollbar */
        .auth-form-container::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }

        .auth-form-container::-webkit-scrollbar-track {
            background: rgba(15, 15, 26, 0.4);
            border-radius: 10px;
        }

        .auth-form-container::-webkit-scrollbar-thumb {
            background-color: var(--nebula-purple);
            border-radius: 10px;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 1rem;
        }

        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .auth-logo-icon {
            font-size: 2rem;
            color: var(--nebula-purple);
            text-shadow: 0 0 15px rgba(108, 77, 246, 0.5);
            animation: pulse 4s infinite alternate;
        }

        .auth-logo-text {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 15px rgba(108, 77, 246, 0.3);
        }

        .auth-title {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--neon-white);
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .auth-subtitle {
            opacity: 0.8;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        /* Tabs Navigation */
        .auth-tabs {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
            gap: 0.5rem;
        }

        .auth-tab {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            background: rgba(15, 15, 26, 0.5);
            color: var(--neon-white);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid rgba(224, 224, 255, 0.1);
            font-size: 0.85rem;
        }

        .auth-tab.active {
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
            box-shadow: 0 3px 10px rgba(108, 77, 246, 0.4);
        }

        .auth-tab:hover:not(.active) {
            background: rgba(224, 224, 255, 0.1);
        }

        .form-group {
            position: relative;
            margin-bottom: 0.75rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.4rem;
            color: var(--neon-white);
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 1rem;
            background: rgba(15, 15, 26, 0.5);
            border: 1px solid rgba(224, 224, 255, 0.1);
            border-radius: 10px;
            color: var(--neon-white);
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--cosmic-pink);
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.2);
            outline: none;
        }

        /* Add to existing styles */
        .form-control-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background: rgba(15, 15, 26, 0.5) url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3e%3cpath d='M7 10l5 5 5-5z'/%3e%3c/svg%3e") no-repeat;
            background-position: right 0.75rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }

        .form-control-select:focus {
            border-color: var(--cosmic-pink);
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.2);
            outline: none;
        }

        /* Password toggle icon styling */
        .password-toggle i {
            transition: all 0.3s ease;
        }

        .password-toggle-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--neon-white);
            opacity: 0.7;
            cursor: pointer;
            transition: all 0.3s ease;
            background: none;
            border: none;
            padding: 0;
            font-size: 1rem;
        }

        .password-toggle:hover {
            opacity: 1;
            color: var(--cosmic-pink);
        }

        .btn-auth {
            padding: 0.75rem 1.25rem;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            z-index: 1;
            width: 100%;
            font-size: 0.9rem;
        }

        .btn-auth-primary {
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
            box-shadow: 0 5px 15px rgba(108, 77, 246, 0.4);
        }

        .btn-auth-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(108, 77, 246, 0.6);
        }

        /* Footer Links - Simplified */
        .auth-footer {
            text-align: center;
            margin-top: 0.1rem;
            font-size: 0.85rem;
        }

        .auth-footer-links {
            display: flex;
            justify-content: center;
            gap: 0.1rem;
            flex-wrap: wrap;
        }

        .auth-link {
            color: var(--stellar-blue);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .auth-link:hover {
            color: var(--cosmic-pink);
            text-decoration: underline;
        }

        .auth-link-divider {
            color: rgba(224, 224, 255, 0.3);
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(5px);
            border: 1px solid transparent;
            font-size: 0.9rem;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.15);
            border-color: rgba(244, 67, 54, 0.3);
            color: #F44336;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.15);
            border-color: rgba(76, 175, 80, 0.3);
            color: #4CAF50;
        }

        .alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: currentColor;
            opacity: 0.7;
        }

        /* Decorative Elements */
        .planet-decoration {
            position: absolute;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            filter: blur(20px);
            opacity: 0.3;
            z-index: -1;
            animation: float 6s infinite ease-in-out;
        }

        .planet-1 {
            background: var(--nebula-purple);
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .planet-2 {
            background: var(--stellar-blue);
            bottom: 15%;
            right: 10%;
            animation-delay: 1s;
        }

        .planet-3 {
            background: var(--cosmic-pink);
            top: 40%;
            right: 15%;
            width: 150px;
            height: 150px;
            animation-delay: 2s;
        }

        /* Success message styles */
        .success-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            padding: 15px 25px;
            background: #115846;
            color: white;
            border-radius: 5px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideDown 0.5s ease-out forwards;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translate(-50%, -20px);
            }

            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        .success-message::before {
            content: '✓';
            font-weight: bold;
        }

        /* Form validation styles */
        .invalid-feedback {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color:rgb(131, 64, 72);
        }

        .is-invalid {
            border-color: rgb(131, 64, 72) !important;
            box-shadow: 0 0 0 3px rgba(244, 67, 54, 0.2) !important;
        }

        .is-invalid+.invalid-feedback {
            display: block;
        }

        /* Form error message */
        .form-error-message {
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="auth-background"></div>
        <div class="auth-stars"></div>

        <!-- Decorative planets -->
        <div class="planet-decoration planet-1"></div>
        <div class="planet-decoration planet-2"></div>
        <div class="planet-decoration planet-3"></div>

        <!-- Success message container -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message" id="successMessage">
                <?php
                if ($form === 'login' && isset($_SESSION['login_success'])) {
                    echo $_SESSION['login_success'];
                    unset($_SESSION['login_success']);
                } elseif ($form === 'register' && isset($_SESSION['register_success'])) {
                    echo $_SESSION['register_success'];
                    unset($_SESSION['register_success']);
                } elseif ($form === 'forgot_password' && isset($_SESSION['forgot_success'])) {
                    echo $_SESSION['forgot_success'];
                    unset($_SESSION['forgot_success']);
                } elseif ($form === 'login' && isset($_SESSION['reset_success'])) {
                    echo $_SESSION['reset_success'];
                    unset($_SESSION['reset_success']);
                }
                ?>
            </div>
        <?php endif; ?>

        <div class="auth-content">
            <div class="auth-form-container auth-form-switch">
                <div class="auth-header">
                    <div class="auth-logo">
                        <div class="auth-logo-icon">
                            <i class="fas fa-user-astronaut"></i>
                        </div>
                        <span class="auth-logo-text">TeamSphere</span>
                    </div>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-error">
                            <?= htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <h2 class="auth-title">
                        <?php
                        echo $form === 'login' ? 'Welcome Back' :
                            ($form === 'register' ? 'Join Our Cosmos' :
                                ($form === 'forgot_password' ? 'Reset Your Orbit' :
                                    'Set New Coordinates'));
                        ?>
                    </h2>
                    <p class="auth-subtitle">
                        <?php
                        echo $form === 'login' ? 'Sign in to access your stellar workspace' :
                            ($form === 'register' ? 'Begin your cosmic journey with us' :
                                ($form === 'forgot_password' ? 'Enter your email to receive reset instructions' :
                                    'Create a new password for your account'));
                        ?>
                    </p>
                </div>

                <div class="auth-tabs">
                    <div class="auth-tab <?= $form === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Login
                    </div>
                    <div class="auth-tab <?= $form === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">
                        Register</div>
                    <div class="auth-tab <?= $form === 'forgot_password' ? 'active' : '' ?>"
                        onclick="switchTab('forgot_password')">Reset Password</div>
                </div>

                <?php if ($form === 'login'): ?>
                    <form class="auth-form" method="POST" action="auth.php" id="loginForm">
                        <input type="hidden" name="action" value="login">

                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                            <div class="invalid-feedback">Please enter your email address</div>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <div class="password-toggle-container">
                                <input type="password" id="password" name="password" class="form-control" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Please enter your password</div>
                            <div style="font-size: 0.85rem;"><a href="auth.php?form=forgot_password" class="auth-link">Lost
                                    in space? Reset password</a></div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-auth btn-auth-primary">Launch Into Workspace</button>
                        </div>

                        <div class="auth-footer">
                            <div class="auth-footer-links">
                                <span class="auth-link-divider">•</span>
                                <a href="auth.php?form=register" class="auth-link">New astronaut? Register</a>
                            </div>
                        </div>
                    </form>

                <?php elseif ($form === 'register'): ?>
                    <form class="auth-form" method="POST" action="auth.php" id="registerForm">
                        <input type="hidden" name="action" value="register">

                        <div class="form-group">
                            <label for="role" class="form-label">Role</label>
                            <select id="role" name="role" class="form-control form-control-select" required>
                                <option value="employee" <?= ($_SESSION['form_data']['role'] ?? '') === 'employee' ? 'selected' : '' ?>>Employee</option>
                                <option value="manager" <?= ($_SESSION['form_data']['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                                <option value="admin" <?= ($_SESSION['form_data']['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <div class="invalid-feedback">Please select a role</div>
                        </div>

                        <div class="form-group">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                value="<?= htmlspecialchars($_SESSION['form_data']['full_name'] ?? '') ?>" required>
                            <div class="invalid-feedback">Please enter your full name</div>
                        </div>

                        <div class="form-group">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" id="username" name="username" class="form-control"
                                value="<?= htmlspecialchars($_SESSION['form_data']['username'] ?? '') ?>" required>
                            <div class="invalid-feedback">Please choose a username</div>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control"
                                value="<?= htmlspecialchars($_SESSION['form_data']['email'] ?? '') ?>" required>
                            <div class="invalid-feedback">Please enter a valid email address</div>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <div class="password-toggle-container">
                                <input type="password" id="password" name="password" class="form-control" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Password must be at least 8 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <div class="password-toggle-container">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                    required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePassword('confirm_password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Passwords must match</div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-auth btn-auth-primary">Begin Cosmic Journey</button>
                        </div>

                        <div class="auth-footer">
                            <div class="auth-footer-links">
                                <a href="auth.php?form=login" class="auth-link">Already have an account? Sign in</a>
                            </div>
                        </div>
                    </form>

                <?php elseif ($form === 'forgot_password'): ?>
                    <form class="auth-form" method="POST" action="auth.php" id="forgotForm">
                        <input type="hidden" name="action" value="forgot_password">

                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                            <div class="invalid-feedback">Please enter your email address</div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-auth btn-auth-primary">Send Reset Instructions</button>
                        </div>

                        <div class="auth-footer">
                            <div class="auth-footer-links">
                                <a href="auth.php?form=login" class="auth-link">Remembered your password? Sign in</a>
                            </div>
                        </div>
                    </form>

                <?php elseif ($form === 'reset_password'): ?>
                    <form class="auth-form" method="POST"
                        action="auth.php?action=reset_password&token=<?= htmlspecialchars($_GET['token'] ?? '') ?>"
                        id="resetForm">
                        <div class="form-group">
                            <label for="password" class="form-label">New Password</label>
                            <div class="password-toggle-container">
                                <input type="password" id="password" name="password" class="form-control" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Password must be at least 8 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <div class="password-toggle-container">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                    required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePassword('confirm_password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Passwords must match</div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-auth btn-auth-primary">Reset Password</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId, button) {
            const input = document.getElementById(fieldId);
            const icon = button.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Switch between auth tabs
        function switchTab(tabName) {
            // Update active tab
            document.querySelectorAll('.auth-tab').forEach(tab => {
                tab.classList.toggle('active', tab.textContent.toLowerCase().includes(tabName));
            });

            // Submit form to switch views
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = 'auth.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'form';
            input.value = tabName;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        // Create dynamic stars
        document.addEventListener('DOMContentLoaded', function () {
            const starsContainer = document.querySelector('.auth-stars');
            const starsCount = 200;

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

            // Hide success message after 3 seconds
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.opacity = '0';
                    setTimeout(() => successMessage.remove(), 500);
                }, 3000);
            }

            // Form validation
            const forms = document.querySelectorAll('.auth-form');

            forms.forEach(form => {
                form.addEventListener('submit', function (e) {
                    let isValid = true;
                    const inputs = form.querySelectorAll('input[required], select[required]');

                    // Clear previous error highlights
                    form.querySelectorAll('.is-invalid').forEach(el => {
                        el.classList.remove('is-invalid');
                    });

                    // Remove existing error messages
                    const existingError = form.querySelector('.form-error-message');
                    if (existingError) existingError.remove();

                    // Validate required fields
                    inputs.forEach(input => {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        }
                    });

                    // Special validation for password fields
                    const password = form.querySelector('#password');
                    const confirmPassword = form.querySelector('#confirm_password');

                    if (password && confirmPassword) {
                        if (password.value !== confirmPassword.value) {
                            confirmPassword.classList.add('is-invalid');
                            isValid = false;
                        }

                        if (password.value.length < 8) {
                            password.classList.add('is-invalid');
                            isValid = false;
                        }
                    }

                    if (!isValid) {
                        e.preventDefault();

                        // Show general error message
                        const errorMessage = document.createElement('div');
                        errorMessage.className = 'alert alert-error form-error-message';
                        errorMessage.textContent = 'Please correct the errors in the form';
                        form.prepend(errorMessage);

                        // Scroll to first error
                        const firstError = form.querySelector('.is-invalid');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.opacity = '0';
                    setTimeout(() => {
                        successMessage.remove();
                        // Redirect to dashboard after message disappears
                        <?php if (isset($_SESSION['dashboard_path'])): ?>
                            window.location.href = "<?= $_SESSION['dashboard_path'] ?>";
                            <?php unset($_SESSION['dashboard_path']); ?>
                        <?php endif; ?>
                    }, 500);
                }, 1500);
            }
            if (performance.navigation.type === 1) { // Page was reloaded
                if (window.location.href.includes('success=1')) {
                    // Remove the success parameter from URL
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
                // Remove message element if it exists
                const msg = document.getElementById('successMessage');
                if (msg) msg.remove();
            }
        });
    </script>
</body>

</html>