-- ActivityLogger Database Schema
-- Run this SQL to create the activity_logs table
-- Compatible with MySQL 5.7+ and MariaDB 10.2+

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL COMMENT 'User who performed the action (NULL for system/anonymous)',
    source VARCHAR(50) NULL COMMENT 'Source context: admin, frontend, api, cli, webhook, mobile_app, etc.',
    action VARCHAR(100) NOT NULL COMMENT 'Action performed: create, update, delete, login, export, etc.',
    entity_type VARCHAR(100) NULL COMMENT 'Entity type affected (optional): user, product, order, etc.',
    entity_id VARCHAR(100) NULL COMMENT 'Entity identifier - string for flexibility (int, UUID, composite key)',
    old_values JSON NULL COMMENT 'Previous state before change (for updates/deletes)',
    new_values JSON NULL COMMENT 'New state after change (for creates/updates)',
    context JSON NULL COMMENT 'Additional context data - any structure',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP address (supports IPv6)',
    user_agent VARCHAR(500) NULL COMMENT 'Browser/client user agent string',
    session_id VARCHAR(64) NULL COMMENT 'PHP session ID for grouping related actions',
    checksum VARCHAR(64) NULL COMMENT 'SHA-256 integrity verification hash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action occurred',

    -- Indexes for common query patterns
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_entity (entity_type, entity_id),
    INDEX idx_activity_action (action),
    INDEX idx_activity_source (source),
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_session (session_id),

    -- Compound indexes for common filter combinations
    INDEX idx_activity_user_created (user_id, created_at),
    INDEX idx_activity_entity_created (entity_type, entity_id, created_at),
    INDEX idx_activity_action_created (action, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Add foreign key constraint if you have a users table
-- ALTER TABLE activity_logs
--     ADD CONSTRAINT fk_activity_user
--     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Optional: Prevent updates to activity logs (immutable audit trail)
-- DELIMITER //
-- CREATE TRIGGER prevent_activity_log_update
-- BEFORE UPDATE ON activity_logs
-- FOR EACH ROW
-- BEGIN
--     SIGNAL SQLSTATE '45000'
--     SET MESSAGE_TEXT = 'Activity logs cannot be modified';
-- END//
-- DELIMITER ;

-- Optional: Prevent deletion of recent logs (enforce retention policy)
-- DELIMITER //
-- CREATE TRIGGER prevent_activity_log_delete
-- BEFORE DELETE ON activity_logs
-- FOR EACH ROW
-- BEGIN
--     IF OLD.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY) THEN
--         SIGNAL SQLSTATE '45000'
--         SET MESSAGE_TEXT = 'Cannot delete activity logs less than 90 days old';
--     END IF;
-- END//
-- DELIMITER ;
