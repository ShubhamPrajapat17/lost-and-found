-- =====================================================================
-- Migration: user blocking + contact/support messages
--
-- You normally do NOT need to run this: the app applies it automatically
-- the first time it connects (includes/db_setup.php). It is provided for
-- people who prefer to upgrade by hand. Run it against your database:
--   mysql -u root -p lost_and_found < migrations/2026_10_29_support_blocking.sql
--
-- Requires MySQL 8.0+ or MariaDB 10.3+ (uses ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS blocked_reason VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS blocked_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    category ENUM('bug', 'account', 'report', 'claim', 'other') NOT NULL DEFAULT 'other',
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'in_progress', 'resolved') NOT NULL DEFAULT 'new',
    admin_reply TEXT DEFAULT NULL,
    replied_by INT DEFAULT NULL,
    replied_at DATETIME DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_contact_status (status),
    INDEX idx_contact_created (created_at),
    INDEX idx_contact_user (user_id)
) ENGINE=InnoDB;
