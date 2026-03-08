<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/db_connect.php';

try {
    $pdo = Database::getInstance();
    $currentUserId = $_SESSION['user_id'];

    // Get all messages for the current user (both direct and group messages)
    $stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name, u.avatar as sender_avatar 
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.receiver_id = ? AND m.sender_id = ?) OR 
          (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.message_id ASC  -- Changed from DESC to ASC for oldest first
");
$stmt->execute([$currentUserId, $_GET['receiver_id'], $currentUserId, $_GET['receiver_id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark direct messages as read (group messages would need different handling)
    $pdo->prepare("
        UPDATE messages 
        SET is_read = TRUE 
        WHERE receiver_id = ? AND is_read = FALSE
    ")->execute([$currentUserId]);

    echo json_encode($messages);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    error_log("Message fetch error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    error_log("Message fetch error: " . $e->getMessage());
}