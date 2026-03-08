-- =============================================
-- Database Creation
-- =============================================
CREATE DATABASE IF NOT EXISTS teamsphere;
USE teamsphere;

SET SQL_MODE = "STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION";

-- =============================================
-- Core Tables
-- =============================================

-- Departments (Planets)
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#6C4DF6',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- Users (Stars)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    job_title VARCHAR(50),
    phone VARCHAR(20),
    avatar VARCHAR(255) DEFAULT 'default-star.png',
    role ENUM('employee', 'manager', 'admin') DEFAULT 'employee',
    join_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    status ENUM('active', 'inactive') DEFAULT 'active'
) ENGINE=InnoDB;

-- User-Department Assignments (Orbits)
CREATE TABLE user_departments (
    user_id INT NOT NULL,
    department_id INT NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, department_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Task Management (Comets)
-- =============================================

-- Main Tasks
CREATE TABLE tasks (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    assigned_to INT NOT NULL,
    created_by INT NOT NULL,
    department_id INT,
    status ENUM('pending', 'in_progress', 'completed', 'archived') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    deadline DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (assigned_to) REFERENCES users(user_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- Task Dependencies
CREATE TABLE task_dependencies (
    task_id INT NOT NULL,
    depends_on INT NOT NULL,
    dependency_type ENUM('blocks', 'relates_to', 'duplicates') NOT NULL,
    PRIMARY KEY (task_id, depends_on),
    FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
    FOREIGN KEY (depends_on) REFERENCES tasks(task_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Communication System
-- =============================================

-- Chat Groups (Constellations)
CREATE TABLE chat_groups (
    group_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Messages (Transmissions)
CREATE TABLE messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NULL,
    group_id INT NULL,
    content TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    is_group_message BOOLEAN DEFAULT FALSE,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(user_id),
    FOREIGN KEY (receiver_id) REFERENCES users(user_id),
    FOREIGN KEY (group_id) REFERENCES chat_groups(group_id),
    CHECK (receiver_id IS NOT NULL OR group_id IS NOT NULL)
) ENGINE=InnoDB;

-- Group Members
CREATE TABLE group_members (
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES chat_groups(group_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Scheduling & Events
-- =============================================

-- Calendar Events
CREATE TABLE events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    organizer_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    location VARCHAR(100),
    is_recurring BOOLEAN DEFAULT FALSE,
    recurrence_pattern VARCHAR(50),
    FOREIGN KEY (organizer_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Event Attendees
CREATE TABLE event_attendees (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    response ENUM('accepted', 'declined', 'tentative') DEFAULT 'tentative',
    PRIMARY KEY (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Document Management (Nebula Files)
-- =============================================

-- Documents
CREATE TABLE documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    department_id INT,
    file_size INT NOT NULL,
    file_type VARCHAR(50),
    downloads INT DEFAULT 0,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- Document Versions
CREATE TABLE document_versions (
    version_id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_number INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    changes TEXT,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id),
    UNIQUE (document_id, version_number)
) ENGINE=InnoDB;

-- =============================================
-- Announcements & Notifications
-- =============================================

-- Announcements (Broadcasts)
CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    created_by INT NOT NULL,
    department_id INT,
    is_global BOOLEAN DEFAULT FALSE,
    priority ENUM('normal', 'important', 'critical') DEFAULT 'normal',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- Notifications (Alerts)
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    type ENUM('system', 'task', 'message', 'event') NOT NULL,
    reference_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Analytics & Logging
-- =============================================

-- Activity Log
CREATE TABLE activity_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Indexes for Performance
-- =============================================
CREATE INDEX idx_tasks_assigned ON tasks(assigned_to);
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_messages_sender ON messages(sender_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_documents_department ON documents(department_id);

-- =============================================
-- Sample Data (Except Users)
-- =============================================

-- Departments
INSERT INTO departments (name, description, color) VALUES
('Engineering', 'Software development team', '#4A90E2'),
('Marketing', 'Digital and content marketing', '#FF6B9D'),
('HR', 'Human resources and operations', '#FFC154'),
('Finance', 'Accounting and financial planning', '#47B881'),
('Design', 'UI/UX and graphic design', '#AD5CFF');

-- Add this to schema.sql before the sample data section
CREATE TABLE password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE (token)
) ENGINE=InnoDB;

-- TeamSphere Database Updates for Admin/Employee Roles
-- This file contains all required schema changes for role-based access control

-- 1. Update users table structure
ALTER TABLE users 
MODIFY COLUMN role ENUM('admin', 'employee', 'manager') NOT NULL DEFAULT 'employee',
ADD COLUMN is_superadmin BOOLEAN DEFAULT FALSE AFTER role,
ADD COLUMN last_password_change DATETIME AFTER last_login,
ADD COLUMN must_change_password BOOLEAN DEFAULT FALSE AFTER last_password_change;

-- 2. Create permissions table
CREATE TABLE IF NOT EXISTS permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (name)
) ENGINE=InnoDB;

-- 3. Create role_permissions junction table
CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(20) NOT NULL,
    permission_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission_id),
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Create user_permissions table
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Insert initial permission data
INSERT IGNORE INTO permissions (name, description) VALUES
('access_admin_panel', 'Access to admin dashboard and tools'),
('manage_users', 'Create, edit, and delete user accounts'),
('manage_departments', 'Create and manage departments'),
('manage_all_tasks', 'View and modify all tasks in the system'),
('manage_own_tasks', 'View and modify only assigned tasks'),
('send_announcements', 'Create system-wide announcements'),
('view_reports', 'Access to analytics and reporting'),
('manage_settings', 'Modify system settings and configuration');

-- Assign permissions to roles
INSERT IGNORE INTO role_permissions (role, permission_id) VALUES
('admin', (SELECT permission_id FROM permissions WHERE name = 'access_admin_panel')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'manage_users')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'manage_departments')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'manage_all_tasks')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'send_announcements')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'view_reports')),
('admin', (SELECT permission_id FROM permissions WHERE name = 'manage_settings')),
('employee', (SELECT permission_id FROM permissions WHERE name = 'manage_own_tasks'));

-- 6. Update activity_log table
ALTER TABLE activity_log
ADD COLUMN role VARCHAR(20) AFTER user_id,
ADD COLUMN affected_user_id INT NULL AFTER role;

-- 7. Grant all permissions to superadmin
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT 
    u.user_id, 
    p.permission_id 
FROM 
    users u
CROSS JOIN 
    permissions p
WHERE 
    u.is_superadmin = TRUE
    AND NOT EXISTS (
        SELECT 1 
        FROM user_permissions up 
        WHERE up.user_id = u.user_id 
        AND up.permission_id = p.permission_id
    );

ALTER TABLE events 
ADD COLUMN event_date DATE AFTER end_time;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_name VARCHAR(100) DEFAULT 'TeamSphere',
    timezone VARCHAR(50) DEFAULT 'UTC',
    maintenance_mode BOOLEAN DEFAULT FALSE,
    logo_url VARCHAR(255),
    favicon_url VARCHAR(255),
    theme_color VARCHAR(7) DEFAULT '#6C4DF6',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mailer VARCHAR(20) DEFAULT 'smtp',
    host VARCHAR(100),
    port INT DEFAULT 587,
    username VARCHAR(100),
    password VARCHAR(255),
    encryption VARCHAR(10) DEFAULT 'tls',
    from_address VARCHAR(100),
    from_name VARCHAR(100),
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    password_policy VARCHAR(20) DEFAULT 'medium',
    min_password_length INT DEFAULT 8,
    require_mixed_case BOOLEAN DEFAULT TRUE,
    require_numbers BOOLEAN DEFAULT TRUE,
    require_special_chars BOOLEAN DEFAULT FALSE,
    login_attempts INT DEFAULT 5,
    lockout_time INT DEFAULT 15,
    two_factor_auth BOOLEAN DEFAULT FALSE,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_frequency VARCHAR(20) DEFAULT 'daily',
    backup_time TIME DEFAULT '02:00:00',
    keep_backups INT DEFAULT 7,
    backup_type VARCHAR(20) DEFAULT 'full',
    storage_location VARCHAR(20) DEFAULT 'local',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Full database is now 100% complete --