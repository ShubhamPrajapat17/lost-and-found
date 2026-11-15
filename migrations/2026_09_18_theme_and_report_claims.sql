-- =====================================================================
-- Migration: theme preference + two-step report claim workflow
-- Run this against an EXISTING lost_and_found database:
--   mysql -u root -p lost_and_found < migrations/2026_09_18_theme_and_report_claims.sql
-- Fresh installs get these columns from db_schema.sql already.
-- =====================================================================

USE lost_and_found;

-- ---------------------------------------------------------------------
-- users.theme — UI theme preference (default follows the OS setting)
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN theme ENUM('default', 'dark', 'light') NOT NULL DEFAULT 'default' AFTER profile_image;

-- ---------------------------------------------------------------------
-- items.* — two-step claim handshake on an approved report.
--   1. admin claims the report  -> admin_claimed_at is set
--   2. reporter claims it       -> owner_claimed_at is set
--   3. reporter may now resolve -> status = 'resolved'
-- A NULL timestamp means "not claimed yet".
-- ---------------------------------------------------------------------
ALTER TABLE items
    ADD COLUMN admin_claimed_by INT DEFAULT NULL AFTER is_approved,
    ADD COLUMN admin_claimed_at DATETIME DEFAULT NULL AFTER admin_claimed_by,
    ADD COLUMN owner_claimed_at DATETIME DEFAULT NULL AFTER admin_claimed_at,
    ADD CONSTRAINT fk_items_admin_claimed_by
        FOREIGN KEY (admin_claimed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_admin_claimed (admin_claimed_at),
    ADD INDEX idx_owner_claimed (owner_claimed_at);
