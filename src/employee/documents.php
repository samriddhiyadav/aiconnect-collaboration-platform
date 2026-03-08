<?php
// Employee Documents - Complete Standalone Version
// File: src/employee/documents.php

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

// Get department info
$dept_stmt = $pdo->prepare(
    "SELECT d.* FROM departments d
    JOIN user_departments ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ? AND ud.is_primary = 1"
);
$dept_stmt->execute([$user_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Get documents
$documents_stmt = $pdo->prepare(
    "SELECT d.*, u.full_name as uploaded_by_name 
     FROM documents d
     JOIN users u ON d.uploaded_by = u.user_id
     WHERE d.department_id = ? OR d.uploaded_by = ?
     ORDER BY d.uploaded_at DESC"
);
$documents_stmt->execute([$department['department_id'] ?? 0, $user_id]);
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
                $user_id,
                $department['department_id'] ?? null,
                $file_size,
                $file_type
            ]);

            // Log activity
            $activity_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
            $activity_stmt->execute([
                $user_id,
                'document_upload',
                "Uploaded document: $file_name"
            ]);

            $upload_success = "Document uploaded successfully!";
            header("Location: documents.php");
            exit();
        } else {
            $upload_error = "Failed to move uploaded file";
        }
    }
}

// Handle document download
if (isset($_GET['download'])) {
    $doc_id = $_GET['download'];
    $doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ? AND (department_id = ? OR uploaded_by = ?)");
    $doc_stmt->execute([$doc_id, $department['department_id'] ?? 0, $user_id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

    if ($document) {
        $file_path = "../../uploads/nebula_documents/" . $document['file_path'];

        if (file_exists($file_path)) {
            // Update download count
            $update_stmt = $pdo->prepare("UPDATE documents SET downloads = downloads + 1 WHERE document_id = ?");
            $update_stmt->execute([$doc_id]);

            // Log activity
            $activity_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
            $activity_stmt->execute([
                $user_id,
                'document_download',
                "Downloaded document: " . $document['name']
            ]);

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

    // If file doesn't exist or user doesn't have permission
    header("Location: documents.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Documents</title>
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

        .document-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
        }

        .document-card:hover {
            border-color: var(--nebula-purple);
            transform: translateY(-3px);
        }

        .document-icon {
            font-size: 2rem;
            color: var(--stellar-blue);
            margin-right: 1rem;
        }

        .document-actions {
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

        .upload-form {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(224, 224, 255, 0.1);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(224, 224, 255, 0.05);
            border-radius: 8px;
        }

        .file-icon {
            font-size: 1.5rem;
            color: var(--cosmic-pink);
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }
        }

        /* Add these styles to your existing style section */
        .file-input-container {
            position: relative;
            margin-top: 0.5rem;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.25rem;
            background: rgba(15, 15, 26, 0.8);
            border: 1px solid rgba(224, 224, 255, 0.2);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-label:hover {
            background: rgba(108, 77, 246, 0.1);
            border-color: var(--nebula-purple);
        }

        .file-input-label i {
            color: var(--cosmic-pink);
            font-size: 1.2rem;
        }

        .file-input-text {
            font-weight: 500;
        }

        .file-input-name {
            margin-left: auto;
            opacity: 0.8;
            font-size: 0.9rem;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-input {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
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
                <a href="documents.php" class="nav-link active">
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
                <h1 style="margin: 0;">Documents</h1>
            </div>

            <!-- Upload Form -->
            <div class="upload-form">
                <h2 style="margin-top: 0; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-upload" style="color: var(--nebula-purple);"></i>
                    <span>Upload New Document</span>
                </h2>

                <?php if (isset($upload_error)): ?>
                    <div class="alert alert-error">
                        <div class="alert-content"><?= $upload_error ?></div>
                    </div>
                <?php elseif (isset($upload_success)): ?>
                    <div class="alert alert-success">
                        <div class="alert-content"><?= $upload_success ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
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

                    <div class="form-group">
                        <small style="opacity: 0.7;">Maximum file size: 10MB</small>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </form>
            </div>

            <!-- Documents List -->
            <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-folder-open" style="color: var(--stellar-blue);"></i>
                <span>Department Documents</span>
            </h2>

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
                                    <h3 style="margin: 0 0 0.5rem 0;"><?= htmlspecialchars($doc['name']) ?></h3>
                                    <div style="display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.9rem; opacity: 0.8;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-user"></i>
                                            <span><?= htmlspecialchars($doc['uploaded_by_name']) ?></span>
                                        </div>
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
                                <a href="documents.php?download=<?= $doc['document_id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-download"></i> Download
                                </a>
                                <button class="btn btn-secondary"
                                    onclick="showDocumentInfo(<?= htmlspecialchars(json_encode($doc)) ?>)">
                                    <i class="fas fa-info-circle"></i> Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No documents found</h3>
                    <p>There are no documents available in your department</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Document Info Modal -->
    <div id="document-modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 1000; align-items: center; justify-content: center;">
        <div
            style="background: var(--deep-space); border-radius: 12px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--nebula-purple);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 id="modal-doc-title" style="margin: 0;"></h2>
                <button onclick="closeModal()"
                    style="background: none; border: none; color: var(--neon-white); font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="modal-doc-content"></div>
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

        // Show document info modal
        function showDocumentInfo(doc) {
            document.getElementById('modal-doc-title').textContent = doc.name;

            let html = `
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-file" style="font-size: 3rem; color: var(--stellar-blue);"></i>
                    <div>
                        <h3 style="margin: 0 0 0.5rem 0;">${doc.name}</h3>
                        <p style="margin: 0; opacity: 0.8;">${Math.round(doc.file_size / 1024)} KB</p>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                    <div>
                        <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Uploaded By</h4>
                        <p style="margin: 0;">${doc.uploaded_by_name}</p>
                    </div>
                    
                    <div>
                        <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Upload Date</h4>
                        <p style="margin: 0;">${new Date(doc.uploaded_at).toLocaleDateString()}</p>
                    </div>
                    
                    <div>
                        <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">Downloads</h4>
                        <p style="margin: 0;">${doc.downloads}</p>
                    </div>
                    
                    <div>
                        <h4 style="margin: 0 0 0.5rem 0; opacity: 0.8;">File Type</h4>
                        <p style="margin: 0;">${doc.file_type}</p>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <a href="documents.php?download=${doc.document_id}" class="btn btn-primary" style="flex: 1; text-align: center;">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            `;

            document.getElementById('modal-doc-content').innerHTML = html;
            document.getElementById('document-modal').style.display = 'flex';
        }

        // Close modal
        function closeModal() {
            document.getElementById('document-modal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function (event) {
            if (event.target === document.getElementById('document-modal')) {
                closeModal();
            }
        });

        document.getElementById('document').addEventListener('change', function (e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file selected';
            document.getElementById('file-name-display').textContent = fileName;
        });
    </script>
</body>

</html>