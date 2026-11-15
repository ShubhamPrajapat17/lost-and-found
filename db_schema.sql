-- =====================================================================
-- Lost & Found Web Application - MySQL Database Schema
-- =====================================================================
-- Import this file into MySQL before running the application:
--   mysql -u root -p < db_schema.sql
-- (or phpMyAdmin -> Import). It creates the `lost_and_found` database.
--
-- NOTE: you don't have to do this by hand. The app creates the database and
-- tables itself on first run (see includes/db_setup.php). If you rename the
-- database, also change DB_NAME in config.php.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `lost_and_found` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lost_and_found`;

-- ---------------------------------------------------------------------
-- Table: users
-- Stores both regular users and admins (role column differentiates them)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    profile_image VARCHAR(255) DEFAULT NULL,
    theme ENUM('default', 'dark', 'light') NOT NULL DEFAULT 'default' COMMENT 'UI theme - default follows the OS preference',
    -- 'suspended' is what the admin panel shows as "Blocked".
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    blocked_reason VARCHAR(255) DEFAULT NULL COMMENT 'Why the admin blocked this account',
    blocked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: items
-- Stores both lost and found item reports (type column differentiates)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('lost', 'found') NOT NULL,
    category ENUM('Electronics', 'Documents', 'Keys', 'Bags', 'Jewelry', 'Pets', 'Other') NOT NULL DEFAULT 'Other',
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    item_date DATE NOT NULL,
    item_time TIME NOT NULL,
    location VARCHAR(255) NOT NULL COMMENT 'Specific street/landmark text, not GPS',
    image_path VARCHAR(255) DEFAULT NULL,
    image_url VARCHAR(500) DEFAULT NULL COMMENT 'Cloudinary/S3 hosted URL if cloud storage enabled',
    contact_email VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(30) NOT NULL,
    secret_detail VARCHAR(255) DEFAULT NULL COMMENT 'Optional verification secret set by finder',
    status ENUM('pending_review', 'active', 'claimed', 'resolved', 'handed_over', 'rejected') NOT NULL DEFAULT 'pending_review',
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    -- Two-step claim handshake on an approved report:
    --   1. an admin claims it   -> admin_claimed_at is set
    --   2. the reporter claims it -> owner_claimed_at is set
    --   3. the reporter may then mark the report resolved
    -- A NULL timestamp means "not claimed yet".
    admin_claimed_by INT DEFAULT NULL,
    admin_claimed_at DATETIME DEFAULT NULL,
    owner_claimed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_claimed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_item_date (item_date),
    INDEX idx_admin_claimed (admin_claimed_at),
    INDEX idx_owner_claimed (owner_claimed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: claims
-- Verification claims submitted by a claimant against a found item
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    claimant_id INT NOT NULL,
    claimant_name VARCHAR(150) NOT NULL,
    claimant_phone VARCHAR(30) NOT NULL,
    claimant_email VARCHAR(150) NOT NULL,
    proof_type ENUM('document', 'secret_detail') NOT NULL DEFAULT 'secret_detail',
    proof_file VARCHAR(255) DEFAULT NULL,
    proof_text TEXT DEFAULT NULL COMMENT 'Secret detail entered by claimant',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (claimant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: chats
-- Secure in-app messages between finder and claimant, tied to a claim
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_claim (claim_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: activity_log
-- Powers the admin dashboard "Recent Activity Stream"
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: contact_messages
-- Support requests sent to the admin from contact.php (guests included, so
-- a blocked user can still appeal). The admin answers them from the
-- "Support Messages" tab; logged-in users see the reply on contact.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL COMMENT 'NULL when sent by a visitor who is not logged in',
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

-- ---------------------------------------------------------------------
-- Seed data: one default admin account
-- Default login -> email: admin@lostfound.com | password: Admin@123
-- (Hash below is generated with PHP password_hash(), bcrypt)
-- ---------------------------------------------------------------------
INSERT INTO users (full_name, email, phone, password_hash, role)
VALUES (
    'System Admin',
    'admin@lostfound.com',
    '0000000000',
    '$2y$10$mxZcNme4C2BAdPwv/rjgteZ3.SjuiqH0EQQP/N7TNTb19LW3juij6',
    'admin'
)
ON DUPLICATE KEY UPDATE email = email;
