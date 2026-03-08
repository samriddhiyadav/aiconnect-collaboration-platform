<?php
/**
 * TeamSphere - Global Functions
 * Contains reusable functions for the application
 */

require_once __DIR__ . '/db_connect.php';

/**
 * Sanitize user input
 * @param string $data The input to sanitize
 * @param string $type The type of sanitization ('string', 'email', 'int', 'float', 'url')
 * @return mixed The sanitized data
 */
function sanitize_input($data, $type = 'string')
{
    if (empty($data))
        return null;

    switch ($type) {
        case 'email':
            $data = filter_var(trim($data), FILTER_SANITIZE_EMAIL);
            return filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : null;
        case 'int':
            return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'url':
            $data = filter_var(trim($data), FILTER_SANITIZE_URL);
            return filter_var($data, FILTER_VALIDATE_URL) ? $data : null;
        case 'string':
        default:
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Redirect to another page
 * @param string $url The URL to redirect to
 * @param int $statusCode HTTP status code (default: 303)
 */
function redirect($url, $statusCode = 303)
{
    header('Location: ' . $url, true, $statusCode);
    exit();
}

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function is_logged_in()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user has a specific role
 * @param string $role The role to check ('admin', 'manager', 'employee')
 * @return bool True if user has the role, false otherwise
 */
function has_role($role)
{
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Hash a password using the configured algorithm
 * @param string $password The password to hash
 * @return string The hashed password
 */
function hash_password($password)
{
    return password_hash($password, PASSWORD_HASH_ALGO, ['cost' => PASSWORD_COST]);
}

/**
 * Verify a password against a hash
 * @param string $password The password to verify
 * @param string $hash The hash to compare against
 * @return bool True if password matches, false otherwise
 */
function verify_password($password, $hash)
{
    return password_verify($password, $hash);
}

/**
 * Generate a CSRF token and store it in session
 * @return string The generated token
 */
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token
 * @param string $token The token to validate
 * @return bool True if valid, false otherwise
 */
function validate_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get user data by ID
 * @param int $user_id The user ID
 * @return array|null User data array or null if not found
 */
function get_user_by_id($user_id)
{
    try {
        $stmt = db_query("SELECT * FROM users WHERE user_id = ?", [$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching user: " . $e->getMessage());
        return null;
    }
}

/**
 * Get user's departments
 * @param int $user_id The user ID
 * @return array Array of department IDs and names
 */
function get_user_departments($user_id)
{
    try {
        $stmt = db_query(
            "SELECT d.department_id, d.name, d.color, ud.is_primary 
             FROM user_departments ud
             JOIN departments d ON ud.department_id = d.department_id
             WHERE ud.user_id = ?",
            [$user_id]
        );
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching user departments: " . $e->getMessage());
        return [];
    }
}

/**
 * Get tasks assigned to a user
 * @param int $user_id The user ID
 * @param string $status Task status filter (optional)
 * @return array Array of tasks
 */
function get_user_tasks($user_id, $status = null)
{
    try {
        $sql = "SELECT * FROM tasks WHERE assigned_to = ?";
        $params = [$user_id];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY deadline ASC, priority DESC";

        $stmt = db_query($sql, $params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching user tasks: " . $e->getMessage());
        return [];
    }
}

/**
 * Create a new task
 * @param array $task_data Array containing task details
 * @return int|false The new task ID or false on failure
 */
function create_task($task_data)
{
    try {
        $sql = "INSERT INTO tasks (title, description, assigned_to, created_by, department_id, status, priority, deadline)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $task_data['title'],
            $task_data['description'],
            $task_data['assigned_to'],
            $task_data['created_by'],
            $task_data['department_id'] ?? null,
            $task_data['status'] ?? 'pending',
            $task_data['priority'] ?? 'medium',
            $task_data['deadline'] ?? null
        ];

        db_query($sql, $params);
        return Database::getInstance()->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error creating task: " . $e->getMessage());
        return false;
    }
}

/**
 * Get department members
 * @param int $department_id The department ID
 * @return array Array of user data
 */
function get_department_members($department_id)
{
    try {
        $stmt = db_query(
            "SELECT u.user_id, u.username, u.full_name, u.job_title, u.avatar 
             FROM user_departments ud
             JOIN users u ON ud.user_id = u.user_id
             WHERE ud.department_id = ? AND u.status = 'active'",
            [$department_id]
        );
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching department members: " . $e->getMessage());
        return [];
    }
}

/**
 * Format date for display
 * @param string $date The date string
 * @param string $format The format to use (default: 'M j, Y g:i A')
 * @return string Formatted date or 'N/A' if invalid
 */
function format_date($date, $format = 'M j, Y g:i A')
{
    if (empty($date))
        return 'N/A';

    try {
        $date = new DateTime($date);
        return $date->format($format);
    } catch (Exception $e) {
        error_log("Error formatting date: " . $e->getMessage());
        return 'N/A';
    }
}

/**
 * Get unread notifications count for a user
 * @param int $user_id The user ID
 * @return int Count of unread notifications
 */
function get_unread_notifications_count($user_id)
{
    try {
        $stmt = db_query(
            "SELECT COUNT(*) as count FROM notifications 
             WHERE user_id = ? AND is_read = FALSE",
            [$user_id]
        );
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error fetching unread notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Add a new notification
 * @param int $user_id The recipient user ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param int|null $reference_id Optional reference ID
 * @return bool True on success, false on failure
 */
function add_notification($user_id, $title, $message, $type = 'system', $reference_id = null)
{
    try {
        $sql = "INSERT INTO notifications (user_id, title, message, type, reference_id)
                VALUES (?, ?, ?, ?, ?)";

        $params = [
            $user_id,
            $title,
            $message,
            $type,
            $reference_id
        ];

        db_query($sql, $params);
        return true;
    } catch (PDOException $e) {
        error_log("Error adding notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Log user activity
 * @param int $user_id The user ID
 * @param string $action The action performed
 * @param string $details Additional details (optional)
 * @return bool True on success, false on failure
 */
function log_activity($user_id, $action, $details = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        db_query(
            "INSERT INTO activity_log (user_id, action, details, ip_address) 
             VALUES (?, ?, ?, ?)",
            [$user_id, $action, $details, $ip]
        );
        return true;
    } catch (PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the current URL with query parameters
 * @param array $params Additional query parameters to include
 * @return string The current URL
 */
function current_url($params = [])
{
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
        "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    if (!empty($params)) {
        $query = parse_url($url, PHP_URL_QUERY);
        $url .= ($query ? '&' : '?') . http_build_query($params);
    }

    return $url;
}

/**
 * Get avatar URL for a user
 * @param string $avatar The avatar filename
 * @return string Full URL to the avatar image
 */
function get_avatar_url($avatar)
{
    if (empty($avatar) || !file_exists(__DIR__ . '/../assets/images/avatars/' . $avatar)) {
        return BASE_URL . '/assets/images/default-avatar.png';
    }
    return BASE_URL . '/assets/images/avatars/' . $avatar;
}

/**
 * Check if a user has access to a department
 * @param int $user_id The user ID
 * @param int $department_id The department ID
 * @return bool True if user has access, false otherwise
 */
function has_department_access($user_id, $department_id)
{
    try {
        $stmt = db_query(
            "SELECT 1 FROM user_departments 
             WHERE user_id = ? AND department_id = ?",
            [$user_id, $department_id]
        );
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("Error checking department access: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all departments with hierarchy
 * @return array Hierarchical array of departments
 */
function get_departments_hierarchy()
{
    try {
        // First get all departments
        $stmt = db_query("SELECT * FROM departments ORDER BY parent_id, name");
        $departments = $stmt->fetchAll();

        // Build hierarchy
        $hierarchy = [];
        foreach ($departments as $dept) {
            if ($dept['parent_id'] === null) {
                $hierarchy[$dept['department_id'] = $dept];
                $hierarchy[$dept['department_id']]['children'] = [];
            }
        }

        foreach ($departments as $dept) {
            if ($dept['parent_id'] !== null && isset($hierarchy[$dept['parent_id']])) {
                $hierarchy[$dept['parent_id']]['children'][] = $dept;
            }
        }

        return $hierarchy;
    } catch (PDOException $e) {
        error_log("Error fetching department hierarchy: " . $e->getMessage());
        return [];
    }
}

/**
 * Get human-readable time difference (e.g., "2 hours ago")
 * @param string $datetime The datetime string to compare
 * @param bool $full Whether to show all time units or just the largest
 * @return string Formatted time difference
 */
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks and remaining days
    $weeks = floor($diff->d / 7);
    $remainingDays = $diff->d % 7;

    $timeUnits = [
        'y' => ['value' => $diff->y, 'name' => 'year'],
        'm' => ['value' => $diff->m, 'name' => 'month'],
        'w' => ['value' => $weeks, 'name' => 'week'],
        'd' => ['value' => $remainingDays, 'name' => 'day'],
        'h' => ['value' => $diff->h, 'name' => 'hour'],
        'i' => ['value' => $diff->i, 'name' => 'minute'],
        's' => ['value' => $diff->s, 'name' => 'second'],
    ];

    // Filter out units with zero values
    $timeUnits = array_filter($timeUnits, function($unit) {
        return $unit['value'] > 0;
    });

    // If we want just the largest unit, take the first one
    if (!$full && !empty($timeUnits)) {
        $timeUnits = [array_shift($timeUnits)];
    }

    // Build the output string
    $parts = [];
    foreach ($timeUnits as $unit) {
        $parts[] = $unit['value'] . ' ' . $unit['name'] . ($unit['value'] > 1 ? 's' : '');
    }

    return $parts ? implode(', ', $parts) . ' ago' : 'just now';
}

function getInitials($name) {
    $initials = '';
    $words = explode(' ', $name);
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials;
}

function formatActivity($action, $details) {
    switch ($action) {
        case 'update_profile':
            return "Updated profile: $details";
        case 'complete_task':
            return "Completed task: $details";
        case 'send_message':
            return "Sent message to $details";
        default:
            return ucfirst(str_replace('_', ' ', $action));
    }
}

function formatDate($datetime) {
    $now = new DateTime();
    $date = new DateTime($datetime);
    $diff = $now->diff($date);
    
    if ($diff->days === 0) {
        return $date->format('H:i');
    } elseif ($diff->days === 1) {
        return 'Yesterday';
    } elseif ($diff->days < 7) {
        return $date->format('l');
    } else {
        return $date->format('M j');
    }
}

function getUserData($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUpcomingTasks($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM tasks 
                          WHERE assigned_to = ? 
                          AND status IN ('pending', 'in_progress') 
                          AND deadline >= NOW()
                          ORDER BY deadline ASC
                          LIMIT 5");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentAnnouncements($pdo, $user_id) {
    // Get user's department
    $dept_stmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = ? AND is_primary = 1");
    $dept_stmt->execute([$user_id]);
    $dept = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    
    $query = "SELECT a.*, u.full_name as sender_name 
              FROM announcements a
              JOIN users u ON a.created_by = u.user_id
              WHERE (a.is_global = 1 OR a.department_id = ?)
              AND (a.expires_at IS NULL OR a.expires_at > NOW())
              ORDER BY a.created_at DESC
              LIMIT 3";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$dept['department_id'] ?? 0]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTaskStatistics($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT 
                          SUM(status = 'pending') as pending,
                          SUM(status = 'in_progress') as in_progress,
                          SUM(status = 'completed') as completed,
                          COUNT(*) as total
                          FROM tasks 
                          WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['completion_percentage'] = $stats['total'] > 0 
        ? round(($stats['completed'] / $stats['total']) * 100) 
        : 0;
        
    return $stats;
}

function getDepartmentMembers($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT u.user_id, u.full_name, u.job_title, u.avatar
                          FROM users u
                          JOIN user_departments ud ON u.user_id = ud.user_id
                          WHERE ud.department_id = (
                              SELECT department_id 
                              FROM user_departments 
                              WHERE user_id = ? AND is_primary = 1
                          )
                          AND u.user_id != ?
                          AND u.status = 'active'
                          LIMIT 6");
    $stmt->execute([$user_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * Log activity (alternative version with PDO parameter)
 * @param PDO $pdo Database connection
 * @param int $user_id The user ID
 * @param string $action_type The action type
 * @param string $action_details Action details
 * @return bool True on success, false on failure
 */
