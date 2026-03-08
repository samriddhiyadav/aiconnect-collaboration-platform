<?php
/**
 * TeamSphere - Database Connection Handler
 * Establishes PDO connection with error handling
 */

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            // Create PDO connection
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true // For better performance
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Set timezone if needed
            $this->connection->exec("SET time_zone = '+00:00'");

        } catch (PDOException $e) {
            // Log error securely
            error_log("Database connection failed: " . $e->getMessage());
            
            // Show user-friendly message
            if (DEBUG_MODE) {
                die("Database connection error: " . $e->getMessage());
            } else {
                die("System maintenance in progress. Please try again later.");
            }
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new Database();
        }
        return self::$instance->connection;
    }

    // Prevent cloning and serialization
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize database connection");
    }
}

// Helper function for quick queries
function db_query($sql, $params = []) {
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query failed: " . $e->getMessage() . " [SQL: $sql]");
        throw $e;
    }
}
?>